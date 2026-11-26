# T10 · KMS/HSM 生产化抽象评估（只报告，不改生产代码）

> 日期：2026-11-05 · 归属：A7 · 等级：**评估报告（未接入任何云 KMS / 硬件 HSM）**
> 硬约束：本文件只做评估。**未经人拍板，不接入任何云 KMS，也不改 `DeviceCredentialService` / `SigningService` 的生产路径。**

## 0. 结论（先说答案）

1. **现有 `KeyProvider` 接口不足以直接换后端。** 它抽象的是"某把密钥的**解析与状态**"（resolve / activeKeyId / epoch / rotate / revoke），
   调用方（验签器）确实可以不动；但**真正的密码学操作**（生成设备密钥对、签发 X.509、对指令签名）**没有接口**——
   它们硬编码在 `DeviceCredentialService` 与 `SigningService` 里，直接调 `openssl_*` 并读写磁盘 PEM。
   把这些直接换成云 KMS，等于在每个调用点做一次替换，风险不可控。
2. **需要新抽两层**（都在现有 `Contracts/` 风格内，不另起架子）：
   - `CertificateAuthoritySigner`：CSR → 证书（CA 私钥托管在 KMS/HSM）；
   - `AsymmetricSigner`：任意字节 → 签名（指令/事实签名私钥托管在 KMS/HSM）。
   `KeyProvider` 保持不变，继续负责 key_id / epoch / 吊销语义。
3. **最难、也最容易被低估的不是 KMS 调用，而是"设备私钥从哪来"。**
   现状 `issue()` 在**服务端**生成设备私钥并把 `private_key_pem` 明文返回给配对流程（`DeviceCredentialService.php:105,122,160-168`）。
   只要还保留"服务端生成私有钥"，就谈不上"密钥不落明文"——这不是换个 KMS 能解决的，是**端侧 CSR-up 模型**的架构变更。
4. **迁移成本量级（估算，非实测）**：只做"CA/签名私钥进 KMS/HSM"约 **1–2 周**；
   加上"设备密钥端侧生成（CSR-up）"约 **再 1–2 周**；证书签发若走通用 KMS（只能 Sign、不会签发 X.509）需要自行组装 TBS 证书，**风险高**。
5. **建议先做 PKCS#11 软件 HSM（SoftHSM2）作为过渡与 CI 代理，再做云 KMS。** 本批 T8 已用 SoftHSM2 验证了
   "私钥不可导出 + HSM 内签名 + 销毁后签名失败"（PROVEN_LOCAL），可作为抽象层的第一个真实后端实现。

## 1. 现状事实（可复核）

| 位置 | 事实 |
|---|---|
| `Services/Deploy/Contracts/KeyProvider.php:19-42` | 已有接口：resolve / activeKeyId / currentEpoch / rotate / revoke / capabilities；注释明确"不实现假 KMS/HSM"。 |
| `Services/Deploy/DeviceKeyProvider.php` | 唯一实现；`capabilities()` 如实返回 `kms=false, hsm=false, rotation=true, revocation=true, epochs=true, offline_recovery=true`。 |
| `Services/Deploy/DeviceCredentialService.php:98-169` `issue()` | `openssl_pkey_new` 生成**设备**私钥；`openssl_csr_new`/`openssl_csr_sign` 用 **CA 私钥**签证书；`openssl_pkey_export` 把设备私钥导出成 PEM 返回。 |
| `DeviceCredentialService.php:130-158` | key ring 的"读-改-写"在 `lockForUpdate()` 行锁内完成。 |
| `DeviceCredentialService.php:738-810` `caKey()` / `ensureCa()` | CA 私钥是磁盘文件 `device_ca_private.pem`，经 `openssl_pkey_get_private` 读入进程；首次缺文件即自签生成。 |
| `SigningService.php:63-71` `sign()` | 指令签名私钥是磁盘文件 `platform_private.pem`，`openssl_sign(... OPENSSL_ALGO_SHA256)` 在进程内完成。 |
| `config/deploy_gateways.php` `keys` | 目录默认 `disk=local, dir=keys`；注释要求生产指到受限持久卷。 |
| `SigningService.php:11-17` | 明确写着"KMS/HSM 未实现"；且 Agent **只内置一把公钥**，替换签名私钥会让所有已部署 Agent 验签失败。 |

**一句话**：当前私钥（CA 私钥、平台签名私钥、设备私钥）都在**进程内存或明文 PEM 文件**里出现过；
`KeyProvider` 只解决了"钥匙环的状态语义"，没解决"私钥的保管与使用"。`capabilities()` 的 `kms/hsm=false` 是诚实的。

## 2. 需要抽的层（草案，**不启用**）

> 以下只是接口形状，便于评估"改动面有多大"。**没有落成生产文件、没有注册进容器、没有开关。**

```php
// 草案 A：CA 托管（签 CSR = 用 KMS/HSM 里的 CA 私钥对 TBS 证书签名）
interface CertificateAuthoritySigner
{
    /** 用托管私钥对 CSR 签发证书；返回 DER 编码的 X.509。 */
    public function signCsr(string $csrDer, int $serial, int $ttlDays): string;

    /** 平台 CA 证书（公开，供 Agent 验链）。 */
    public function caCertPem(): string;

    /** 托管后端自述：kms/hsm/offline_recovery 等，禁止夸大。 */
    public function capabilities(): array;
}

// 草案 B：签名托管（指令 / 事实签名）
interface AsymmetricSigner
{
    /** 对规范串签名，返回原始签名字节（调用方负责 base64）。 */
    public function sign(string $bytes): string;

    public function publicKeyPem(): string;

    /** 公钥 key_id（SPKI sha256），用于 Agent 侧选钥/溯源。 */
    public function keyId(): string;

    public function capabilities(): array;
}
```

接入点（未来）：

- `DeviceCredentialService::issue()` 中 `openssl_csr_sign(...)` 改为 `CertificateAuthoritySigner::signCsr(...)`；
  `caKey()` / `ensureCa()` 变为本地实现的一个后端。
- `SigningService::sign()` 改为注入 `AsymmetricSigner`；本地实现保留为默认（行为零变化）。
- 设备私钥：**目标形态应由设备侧生成并上送 CSR**；服务端不再 `openssl_pkey_new` 生成设备私钥、不再返回 `private_key_pem`。
  这是**协议变更**，需与 Agent 配对流程一起改，不能只改服务端。

## 3. 迁移成本（量级估算，未实测）

| 阶段 | 内容 | 估算 |
|---|---|---|
| 1 | 抽 `CertificateAuthoritySigner` / `AsymmetricSigner` 两个契约 + 本地实现（默认不变） | 2–3 天 |
| 2 | 用 `config` 开关切换后端；补单测（本地后端回归 + mock 后端契约测试） | 1–2 天 |
| 3 | PKCS#11 后端（SoftHSM2 先行；PHP 侧需 `pkcs11` 扩展或经本地代理/服务） | 3–5 天 |
| 4 | 云 KMS 后端（AWS KMS / 阿里云 KMS / Azure Key Vault）：非对称 Sign API 差异大，需适配 | 3–5 天 |
| 5 | 证书签发：若云 KMS 只提供 Sign，需自行组装 X.509（TBS + 签名 + 序列号/有效期/扩展） | 3–5 天，**高风险** |
| 6 | 设备密钥端侧生成（CSR-up）+ Agent 配对流程改造 | 1–2 周 |
| 7 | 测试：SoftHSM 作为 CI 代理、真实 KMS 沙箱、轮换/吊销/离线场景 | 2–3 天 |

> 只做"CA/签名私钥进 KMS/HSM"：**约 1–2 周**。加"设备私钥端侧生成"：**再 1–2 周**。
> 成本大头在 5/6，不在"调一次 KMS API"。

## 4. 风险

1. **X.509 签发不是通用 KMS 的能力**：多数云 KMS 只提供 `Sign`，不提供"签一张证书"。自行组装证书极易在
   扩展项、编码、链验证上出错；更稳的是用托管 PKI（AWS Private CA / Azure Key Vault 证书），但那又是另一套依赖。
2. **公钥轮换会打断存量 Agent**：`SigningService` 的 Agent 只内置一把公钥。换 CA/换签名密钥 = 所有存量设备验签失败，
   直到重新 enroll/重装。必须配"双公钥过渡窗口"，而这本身是协议变更。
3. **可用性**：本地文件签名没有网络依赖；KMS 是把网络与凭证放到签发/签名热路径上。需要超时、重试、熔断与
   **降级口径**（不能悄悄退回本地明文密钥，否则等于没上 KMS）。
4. **离线恢复**：`DeviceKeyProvider::capabilities()['offline_recovery']=true` 依赖本地 CA。KMS 后端下该能力应改口，
   不能继续对外宣称 true。
5. **成本与锁定**：每次签发/签名计费；密钥材料与审计绑定厂商。
6. **合规/供应商红线**：不得接入或篡改厂商云（与机器人厂商 EULA 无关，但同样是外部依赖边界）。

## 5. 建议（需人拍板）

- **拍板项 1**：是否接受"设备私钥端侧生成（CSR-up）"这一协议变更？这是"密钥不落明文"的真正前提。
- **拍板项 2**：过渡后端选 **SoftHSM2（PKCS#11 软件 HSM）** 还是直接上云 KMS？建议先 SoftHSM2，把抽象层与 CI 跑顺，再换云。
- **拍板项 3**：证书签发走"自行组装 X.509"还是"托管 PKI 服务"？前者便宜但风险高，后者贵但正确性有保障。
- **推荐顺序**：抽契约 → SoftHSM2 后端（复用本批 T8 的验证方式）→ 契约测试 → 评估云 KMS → 双公钥过渡 → 切生产。

## 6. 明确未做

- 未写任何生产代码；未新增容器绑定；未加开关；未接入任何云 KMS/硬件 HSM。
- 未验证云 KMS 的实际 API（无凭证、无网络策略确认）。
- 成本/工期为量级估算，**不是实测**。
- 本批 T8 的 SoftHSM2 证据只证明"软件 HSM 下私钥不可导出、签名由 HSM 完成、销毁后签名失败"（PROVEN_LOCAL），
  **不证明** 生产 KMS/HSM 就绪，也不构成"KMS 已接入"。
