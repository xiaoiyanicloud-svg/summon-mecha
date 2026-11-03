# 机器人技能设备运行时与标准转码器 · 重构版落地蓝图

- 版本：0.1
- 日期：2026-11-03
- 状态：**Draft for review，待 ADR-0001 审批**
- 决策记录：[ADR-0001：设备端 Agent 边界与标准转码器分层](./adr/ADR-0001-device-agent-and-standard-transcoder.md)
- 适用范围：召唤机甲技能平台、平台侧构建服务、设备端 Agent、厂商插件
- 不适用范围：机器人实时运动控制、远程摇杆、LiveKit 视频接管、固件开发

## 0. 结论先行

本蓝图采用以下总架构：

> **平台负责“生成并审核不可变成品”，Agent 负责“验证并安装成品”，厂商控制器负责“真实运动安全”。**

标准转码器是平台侧离线构建能力，不是设备端运行时：

```text
开发者中立技能
  -> 平台 manifest / 权限 / 风险校验
  -> 标准转码器（异步构建）
  -> 不可变成品 + 构建证据 + 签名
  -> skill_assets / skill_build
  -> deploy_task
  -> Agent 下载与验签
  -> 厂商 InstallAdapter
  -> 厂商运行环境
```

本标准不把 `.rsp` 作为正式格式，不把 Laravel Web 进程作为转码执行器，也不允许 Agent 在机器人上现场修改技能包。

---

## 1. 目标与非目标

### 1.1 目标

1. 保持 `zhaohuan.skill/v2` 为唯一公开技能清单格式。
2. 把现有 `skill_build` 从“登记意图”升级为可追溯的真实构建记录。
3. 定义标准转码器的输入、输出、插件协议、幂等键、状态机和验收方式。
4. 为动作类、应用类、厂商导入类技能提供统一的离线构建入口。
5. 为设备端 Agent 提供唯一可接受的不可变成品。
6. 复用现有 `device_binding`、`deploy_task`、`install_record`、签名下载和熔断能力。
7. 让 L1/L2/L3 由真实产物和验证证据决定，而不是由文档或文案决定。
8. 先完成单机型、单固件、单 SDK 版本的可信闭环，再扩展多品牌。

### 1.2 非目标

以下内容不在本蓝图内：

- 平台直接控制机器人关节、步态、平衡和底盘速度；
- 平台实现 ZMP、全身控制、MPC 或运动仲裁；
- Agent 内置动作转码算法；
- Agent 在机器人上执行 `docker build`、代码生成或动作重定向；
- 远程人工摇杆和 LiveKit 音视频接管；
- 厂商云 API 接入或篡改厂商云；
- 跨品牌“任意技能一次上传、全部开箱即用”；
- 固件、驱动或硬件制造；
- 在未做 HIL 的情况下宣称 M4 或 L1。

---

## 2. 当前仓库基线

### 2.1 已有平台能力

| 能力 | 当前实现 | 本蓝图如何复用 |
|---|---|---|
| 设备身份 | `AgentController::enroll/register/heartbeat` | 作为 Agent 唯一接入入口 |
| 设备真相源 | `device_binding` | 所有下发只认 binding |
| 设备凭据 | `DeviceCredentialService` | per-device secret / 证书签名 |
| 任务状态机 | `deploy_task` | 安装、卸载、回滚、启停、回收 |
| 指令签名 | `SigningService` | 签名任务、版本、包指纹、包 SHA-256、时间戳 |
| 限时下载 | `DeployTaskService::buildCommand` | Agent 只接受短时签名 URL |
| 安装记录 | `install_record` | 安装事实、回滚和灰度统计 |
| 网关注册表 | `config/deploy_gateways.php` | 当前 HTTP 轮询，可扩展 MQTT |
| 技能清单 | `SkillManifest` / `ManifestValidator` | 作为标准转码器唯一输入契约 |
| 技能资产 | `skill_assets` | 保存构建产物和手工上传产物 |
| 构建登记 | `skill_build` | 当前仅登记意图，需升级为真实构建记录 |
| 构建器注册表 | `config/skill_builders.php` | 扩展为标准转码器插件入口 |
| 权限与风险 | manifest `permissions` / `safety` | 转码和发布前硬校验 |
| 兼容性 | `device_model` + compatibility | 构建目标与下发目标双重校验 |
| 熔断 | 平台 kill switch | Agent 上线时执行撤回/禁用 |

### 2.2 当前明确缺口

1. `skill_build` 尚未进入交付链路。
2. `BuildResult::success()` 没有实际生产者。
3. `MotionCarrierBuilder` 只登记 pending，不产出 artifact。
4. 仓库中没有设备端 Agent 实现。
5. 还没有正式的 `target_profile` 数据契约。
6. 还没有构建运行历史、构建幂等键和工具链指纹。
7. 还没有构建产物签名、SBOM、构建沙箱和可复现策略。
8. 还没有面向 Agent 的安装插件契约和回滚事务。
9. 当前只有手动通道被证明可在真实业务中运行，M4 仍未完成。

---

## 3. 架构分层

```text
┌────────────────────────────────────────────────────────────────────┐
│ 1. 供给侧 / 审核面                                                   │
│  开发者门户 / Admin / internal.key                                  │
│  manifest.json + 源文件 + 目标机型声明                              │
└───────────────────────────────┬────────────────────────────────────┘
                                │
┌───────────────────────────────▼────────────────────────────────────┐
│ 2. 平台构建面（Standard Transcoder）                                 │
│  BuildCoordinator                                                   │
│    ├─ 输入校验                                                     │
│    ├─ TargetProfileResolver                                         │
│    ├─ CarrierBuilder（动作/应用/厂商导入）                          │
│    ├─ ArtifactInspector                                            │
│    ├─ 构建沙箱 / 资源限制 / 工具链锁                               │
│    └─ BuildEvidence + ArtifactSigner                                │
└───────────────────────────────┬────────────────────────────────────┘
                                │ 仅输出不可变成品
┌───────────────────────────────▼────────────────────────────────────┐
│ 3. 平台资产与交付面                                                  │
│  skill_build / skill_assets / skill_review / skill_quality           │
│  deploy_task / install_record / blocklist / incident                 │
│  AgentGateway（当前 HTTP poll，后续可 MQTT）                         │
└───────────────────────────────┬────────────────────────────────────┘
                                │ 签名任务 + 短时下载 URL
┌───────────────────────────────▼────────────────────────────────────┐
│ 4. 设备端 Agent                                                     │
│  agent-core：接入、任务、验证、事务、状态、健康                       │
│  plugin-host：一厂一插件，只做安装/启停/卸载/回滚                    │
│  本地状态库 + staging + 旧版本保留                                  │
└───────────────────────────────┬────────────────────────────────────┘
                                │ 厂商高层接口 / 容器 / 资源同步
┌───────────────────────────────▼────────────────────────────────────┐
│ 5. 厂商运行环境                                                      │
│  厂商运控 / FSM / 容器 / 资源管理器 / ROS 2 节点                     │
│  运动安全、平衡、急停、限幅和轨迹仍由厂商负责                        │
└────────────────────────────────────────────────────────────────────┘
```

### 3.1 三个“适配器”必须区分

| 名称 | 所在位置 | 职责 | 不负责 |
|---|---|---|---|
| `CarrierBuilder` | 平台构建面 | 中立输入 -> 厂商载体 | 不安装、不运行 |
| `InstallAdapter` | 设备 Agent | 安装/启停/卸载/回滚成品 | 不转码、不生成代码 |
| `VendorRuntime` | 机器人本体 | 厂商 FSM、运控、执行 | 不负责平台交易和授权 |

三者不能用同一个类或同一个概念混写。

---
## 4. 标准数据契约

### 4.1 公开技能清单保持不变

公开格式继续使用 `zhaohuan.skill/v2` 的 `manifest.json`。以下示例只展示本蓝图依赖的字段；真实发布仍以 `ManifestValidator` 为准。

```json
{
  "schema": "zhaohuan.skill/v2",
  "kind": "motion",
  "name": {
    "zh_CN": "展厅迎宾挥手",
    "en": "Showroom Greeting Wave"
  },
  "version": "1.0.0",
  "description": "面向展厅迎宾的挥手动作",
  "pricing_mode": "buyout",
  "delivery_channels": ["agent", "manual"],
  "permissions": ["motion_control"],
  "resource_type": "motion",
  "compatibility": {
    "models": [
      {
        "vendor": "unitree",
        "model": "unitree_g1",
        "min_firmware": "1.0.0",
        "min_app": "1.0.0",
        "dof": 23
      }
    ]
  },
  "safety": {
    "risk_level": "medium",
    "requires_open_area": true,
    "estop_required": true,
    "max_duration_s": 30
  },
  "motion": {
    "format": "bvh",
    "fps": 30
  }
}
```

约束：

1. 不允许新增 `.rsp` 公共扩展名。
2. 不允许把构建参数、目标机型重定向参数直接写成厂家私货绕过 manifest。
3. 若技能含脚本或应用，必须以 `app_package` 或 `vendor_package` 载体进入平台。
4. 真实机型是否可交付，仍由构建结果和 HIL 证据决定。

### 4.2 Target Profile

`TargetProfile` 描述“目标机型如何接受某类载体”。建议以版本化 JSON 存放在受审核的配置目录，并在每次构建中记录其摘要。

建议路径：

```text
my-bagisto-store/resources/skill-target-profiles/
  unitree/
    unitree_g1/
      1.0.0.json
    unitree_go2/
      1.0.0.json
  agibot/
    agibot_a2/
      1.0.0.json
```

建议结构：

```json
{
  "profile_version": "1.0.0",
  "model_code": "unitree_g1",
  "vendor_code": "unitree",
  "runtime_family": "vendor_resource",
  "supported_carriers": ["app_package", "motion_data"],
  "sdk_versions": ["unitree_sdk2py:1.x"],
  "firmware_versions": ["1.0.0"],
  "compute": {
    "arch": "aarch64",
    "os": "ubuntu-20.04-or-22.04"
  },
  "motion": {
    "dof": 23,
    "joint_schema": "unitree_g1_23dof_v1",
    "axis_convention": "vendor",
    "unit": "radian",
    "limits": "limits/g1-23dof.json",
    "retarget_engine": "unverified",
    "retarget_engine_version": "pending"
  },
  "packaging": {
    "format": "zip",
    "install_layout": "vendor-defined",
    "entrypoint": "metadata-defined"
  },
  "verification": {
    "status": "unverified",
    "sil": false,
    "hil": false
  }
}
```

说明：

- `verification.status` 未达到 `verified` 时，只能用于实验，不得对外展示为 L1。
- `retarget_engine` 不能在文档中先写成已实现；必须有实际 builder 和测试证据。
- `joint_schema`、限位、坐标系和单位是转码器的输入，不允许在 builder 内硬编码。

### 4.3 Build Request

标准转码器接收的是受控构建请求，不直接执行任意用户命令。

```json
{
  "build_key": "sha256(source_digest+manifest_digest+model_code+profile_digest+builder_key+builder_version+options_digest)",
  "skill_version_id": 123,
  "source_asset_id": 456,
  "target_model_code": "unitree_g1",
  "target_profile_version": "1.0.0",
  "builder_key": "motion.retarget",
  "builder_version": "0.1.0",
  "options": {
    "fps": 30,
    "strict_limits": true
  }
}
```

要求：

1. 请求参数必须白名单化，不允许把任意 shell 参数传给构建器。
2. `build_key` 用于幂等和缓存。
3. 同一 `build_key` 已成功时，直接复用已有产物，不重新生成。
4. `builder_version` 或 profile 摘要变化时，必须产生新的 build key。
5. `skill_version_id` 与 `source_asset_id` 必须可追溯。

### 4.4 Build Output

标准转码器每次构建只产出一个可交付 bundle，并登记到 `skill_assets`。

建议 bundle 形态：

```text
<skill_version>/
  manifest.json
  artifact.json
  payload/
    <vendor-specific files>
  signature.json        # 可选：平台内部签名清单
  sbom.json             # 可选：应用类包需要
  README.md
```

`artifact.json` 建议包含：

```json
{
  "build_key": "sha256:...",
  "skill_version_id": 123,
  "target_model_code": "unitree_g1",
  "target_profile_version": "1.0.0",
  "carrier_type": "app_package",
  "builder_key": "app.container",
  "builder_version": "0.1.0",
  "artifact_sha256": "...",
  "files": [
    {
      "path": "payload/example.tar.gz",
      "sha256": "...",
      "size": 123456
    }
  ],
  "created_at": "2026-11-03T00:00:00Z",
  "verification": {
    "static": "passed",
    "sil": "not_run",
    "hil": "not_run"
  }
}
```

### 4.5 Agent 下发指令

继续复用现有 `AgentGateway` 指令结构，不在本蓝图另造设备控制协议：

```json
{
  "task_id": "DT202611030001",
  "operation": 1,
  "skill_id": 1001,
  "version": "1.0.0",
  "package_url": "https://platform.example/agent/packages/123?...",
  "package_hash": "collection-hash",
  "package_sha256": "file-sha256",
  "signature": "...",
  "ts": 1790000000,
  "target_model_code": "unitree_g1",
  "build_key": "sha256:..."
}
```

`target_model_code` 和 `build_key` 必须参与服务端发布前校验；如果当前实现暂未进入签名串，应在后续版本显式升级签名契约并保持向后兼容。

### 4.6 Agent 回报

沿用现有 phase：

```text
downloading
installing
download_failed
done
failed
timeout
```

建议在 `error_code` 中区分：

```text
SIG_INVALID
HASH_MISMATCH
TARGET_MISMATCH
BUILD_INVALID
PROFILE_MISMATCH
DOWNLOAD_FAILED
INSTALL_FAILED
HEALTH_CHECK_FAILED
ROLLBACK_FAILED
KILL_SWITCHED
```

错误码为机器契约，文案可以改，码值变更必须报批。

---

## 5. 标准转码器详细设计

### 5.1 定位

标准转码器是一个**平台侧异步构建服务**，建议命名为：

```text
Standard Carrier Build Service
技能标准构建服务
```

它不运行在：

- Laravel 同步 HTTP 请求；
- 机器人设备；
- 厂商运控板；
- Agent 进程内。

### 5.2 运行方式

建议新增：

```text
config/skill_transcoder.php
```

内容包括：

- builder 注册表；
- target profile 目录；
- 构建队列名；
- 构建超时；
- CPU/内存/磁盘限制；
- 允许的源格式；
- 允许的输出载体；
- 是否启用签名；
- 构建 worker 版本。

推荐运行配置：

```text
queue: skill-builds
worker: 独立进程，不处理订单/支付队列
storage: 私有盘或对象存储
sandbox: 独立容器
network: 默认关闭，仅允许受控 artifact store
```

当前仓库的 `queue` 和 scheduler 需要常驻进程；构建服务上线前必须保证：

```bash
php artisan queue:work --queue=skill-builds
php artisan schedule:work
```

否则构建任务只是写入数据库，不会执行。

### 5.3 构建状态机

现有 `skill_build.status` 只有 `pending/building/success/failed`。建议对 agent 通道扩展为：

```text
pending
queued
building
verifying
success
failed
cancelled
```

状态约束：

- `success` 必须存在真实 `artifact_asset_id`；
- `success` 必须存在产物 SHA-256；
- `success` 必须通过目标机型校验；
- `failed` 必须记录错误码；
- 任何状态变更写 build run 记录和日志；
- 没有真实构建器时，不允许手写 success 绕过链路。

### 5.4 构建执行流水线

```text
1. 读取 manifest 和源资产
2. 校验 schema / kind / permissions / safety / delivery_channels
3. 解析 target_profile
4. 校验源格式与目标载体是否匹配
5. 生成规范化内部中间表示（仅构建进程内）
6. 调用 CarrierBuilder
7. 生成 bundle
8. 计算每个文件哈希和 bundle SHA-256
9. 运行静态/格式/资源/依赖检查
10. 可选运行 SIL 或容器启动探针
11. 写入私有存储
12. 创建 skill_assets 记录
13. 更新 skill_build / build_run
14. 进入审核和发布闸门
```

### 5.5 幂等与缓存

构建键：

```text
sha256(
  source_digest
  + manifest_digest
  + target_model_code
  + target_profile_digest
  + builder_key
  + builder_version
  + normalized_options
)
```

规则：

1. 相同 build key 成功过，直接复用 artifact。
2. 相同 build key 正在构建，返回现有 job，不重复创建。
3. 构建失败不缓存为 success；重试产生新的 run，但不覆盖历史。
4. 构建产物不可原地更新；更新即新建 artifact。
5. 任何源、profile、builder 或 options 变化都必须产生新 build key。

### 5.6 Builder 类型

#### A. `vendor.passthrough`：厂商成品导入

用途：开发者或运营已经拿到目标机型可直接安装的成品包。

动作：

- 验签/哈希；
- 校验 manifest；
- 校验目标机型和锁定的 carrier；
- 不允许跨机型；
- 生成 build record；
- 产物字节保持不变，仅增加平台元数据。

适用状态：

- 第一阶段最推荐；
- 可在没有自动重定向算法时先打通 M4。

#### B. `motion.retarget`：动作重定向

用途：把通用动作源转为目标机型可执行的动作资源。

前置条件：

- 有目标机型 profile；
- 有校验过的关节映射；
- 有可复现的算法和工具版本；
- 有动作质量门禁；
- 有目标厂商支持的资源格式。

禁止：

- 在没有 profile 的情况下按字符串猜测关节；
- 在设备上运行重定向；
- 把“生成动作文件”直接等同于“真机可安全执行”。

#### C. `app.container`：应用载体构建

用途：把可移植应用或平台 SDK 工程构建为目标架构容器包。

要求：

- 固定基础镜像 digest；
- aarch64/目标架构；
- 非 root；
- 只读文件系统优先；
- 资源限制；
- 健康检查；
- 禁止 privileged；
- 禁止 host network；
- 不包含厂商云凭证；
- 生成 SBOM 和依赖清单。

#### D. `composite.bundle`：复合技能编排

用途：动作、音频、表情、服务依赖的组合。

前置条件：

- 组合顺序可验证；
- 各子资产来源可追溯；
- 运行时有明确依赖；
- 失败回滚可执行。

该 builder 建议放在动作/应用 builder 之后，不要第一版就承诺。

### 5.7 动作转码的具体边界

标准转码器能做：

- 读取 BVH / SMPL-X / FBX 等来源；
- 把来源转换为内部规范化轨迹；
- 依据目标 profile 做单位和坐标系换算；
- 做动作重定向、限位裁剪和连续性检查；
- 输出目标厂商支持的 motion 资源；
- 生成动作预览、统计信息和验证报告。

标准转码器不能保证：

- 所有动作都能跨所有机型；
- 动作在真机上不会跌倒；
- 厂商专有控制逻辑可直接移植；
- 带硬件依赖的应用可以只靠动作重定向解决。

因此动作转码的对外承诺只能是：

> “该动作已生成目标机型载体，并完成指定等级的 SIL/HIL 验证。”

不能写成：

> “所有机器人可运行。”

### 5.8 应用转码的具体边界

标准转码器能做：

- 应用构建、打包、容器化；
- 目标架构校验；
- 依赖和文件清单；
- 静态扫描；
- 启动探针；
- 生成目标厂商要求的安装布局。

不能做：

- 替应用实现厂商私有 SDK；
- 把依赖厂商云账号的应用伪装成离线可用；
- 在没有目标机型的情况下猜测运行方式；
- 绕过厂商 FSM 和运控限制。

---

## 6. 数据模型调整建议

以下均为**建议**，本次不修改代码或迁移。

### 6.1 `skill_build` 扩展字段

建议新增：

| 字段 | 用途 |
|---|---|
| `build_key` | 幂等键，唯一 |
| `source_asset_id` | 源文件/源技能包 |
| `input_digest` | 输入总摘要 |
| `builder_key` | builder 类型 |
| `builder_version` | builder 版本 |
| `target_profile_version` | 目标 profile 版本 |
| `target_profile_digest` | profile 摘要 |
| `output_sha256` | bundle 字节哈希 |
| `last_run_id` | 最近一次构建运行 |
| `verification_status` | static/sil/hil 状态摘要 |

### 6.2 新增 `skill_build_run`

建议表：

```text
id
skill_build_id
attempt
status
started_at
finished_at
worker_id
builder_version
toolchain_digest
input_digest
output_asset_id
output_sha256
log
error_code
error_message
created_at
updated_at
```

用途：

- 保留每次构建的历史；
- 支持失败重试和审计；
- 避免 updateOrCreate 覆盖历史；
- 支持比较不同工具链的产物。

### 6.3 `skill_assets` 保持不变

`skill_assets` 仍是最终可交付产物的唯一存储记录：

```text
carrier_type
file_path
file_name
file_size
file_hash
payload
entrypoint
sort_order
```

构建器不得绕开 `skill_assets` 直接把文件地址塞进 `deploy_task`。

### 6.4 `device_model` 与 Target Profile 的关系

`device_model` 保留机型展示和兼容性信息；精密的转码约束放在版本化 profile 文件中。

建议增加以下关联概念，但可先不新增表：

```text
device_model.model_code
  -> target_profile_reference
  -> profile_version
```

Target profile 的建议存放和版本管理：

- 放在仓库中受审核；
- 每次发布记录 profile digest；
- 修改 profile 必须走代码评审；
- 不允许运营在后台随意改关节限位。

---

## 7. 设备端 Agent 设计

### 7.1 进程边界

推荐：

```text
agent-supervisor
  ├─ agent-core
  ├─ package-verifier
  ├─ plugin-host
  │    ├─ unitree InstallAdapter
  │    ├─ agibot InstallAdapter
  │    └─ ros2 InstallAdapter
  └─ local-state
```

原则：

- Agent 核心与厂商 SDK 不共享同一地址空间更好；
- 插件崩溃不能拖垮整个 Agent；
- 插件只接收平台已验签的 artifact；
- 第三方技能不直接获得厂商 SDK 对象；
- Agent 重启后能恢复任务和安装状态。

### 7.2 Agent 状态机

```text
ENROLLING
  -> READY
  -> RECEIVED
  -> DOWNLOADING
  -> VERIFYING
  -> STAGED
  -> INSTALLING
  -> HEALTH_CHECKING
  -> INSTALLED
  -> ENABLED

任意安装阶段 -> FAILED
FAILED -> ROLLING_BACK -> INSTALLED
RECEIVED/STAGED -> CANCELLED
```

每个状态必须有：

- 本地持久化；
- 平台回报；
- 超时；
- 可重试策略；
- 幂等键。

### 7.3 安装事务

1. 将包下载到随机临时目录；
2. 计算 SHA-256；
3. 校验平台签名、时间戳、task id、目标机型；
4. 检查 ZIP 路径穿越、符号链接、体积炸弹和禁止文件；
5. 解包到 staging；
6. 调用 InstallAdapter 的 `prepare`；
7. 执行目标机型、固件和 SDK 预检；
8. `install`；
9. `health_check`；
10. 成功后原子切换激活版本；
11. 保留上一版本和回滚元数据；
12. 上报 `done`；
13. 任何失败进入 rollback 或 `failed`，不得报假成功。

### 7.4 InstallAdapter 契约

建议接口：

```text
prepare(artifact, target_profile) -> PreparedInstall
install(prepared) -> InstallResult
enable(install_id) -> RuntimeResult
disable(install_id) -> RuntimeResult
uninstall(install_id) -> RuntimeResult
rollback(install_id, previous) -> InstallResult
health(install_id) -> HealthResult
```

Adapter 不允许：

- 修改平台 artifact；
- 执行 manifest 未声明的权限；
- 直接访问平台私钥；
- 自行决定跨机型兼容；
- 绕过平台 kill switch。

### 7.5 离线与熔断

- Agent 保存当前授权和任务快照。
- 对买断技能，离线缓存的有效期可以在首次合法安装后由授权策略定义。
- 对 20 分钟试用，必须在本地上线时计时，并在截止时间停用；不能依赖云端及时在线。
- 平台 kill switch 对新下发即时阻断；已安装设备在下次上线时执行禁用/卸载。
- 手动通道安装的技能当前无法被自动卸载，必须在 UI 和运营口径中保留这一限制。

### 7.6 健康上报

允许上报：

- Agent 版本；
- OS / 架构；
- 磁盘余量；
- 容器/进程状态；
- 技能运行状态；
- 启动失败码；
- 厂商 SDK/固件版本；
- 设备在线时间。

第一阶段不允许通过健康上报通道远程控制运动。健康上报是只读观测，不是遥操作通道。

---

## 8. 发布、兼容与下发闸门

创建 Agent 下发任务前必须通过：

```text
manifest 有效
AND 技能版本已审核/发布
AND skill_build.status = success
AND artifact 存在且哈希一致
AND target_model_code 与 build 一致
AND target_profile 版本允许
AND 设备 binding = bound
AND 设备能力匹配
AND entitlement 有效
AND delivery_channel 包含 agent
AND 平台未触发 kill switch
```

### L1 / L2 / L3 判定

| 级别 | 判定条件 | 对外文案 |
|---|---|---|
| L1 可交付 | 目标机型有成功产物，且已完成规定验证 | “可一键安装/已适配” |
| L2 可适配 | 有源格式和目标 profile，但无成功产物或无 HIL | “可转换，尚未自动下发” |
| L3 仅展示 | 无产物、无 profile 或无可用通道 | “仅展示，不发售” |

设备 agent 通道只在 L1 且满足全部闸门时开放。

---

## 9. 平台接口与模块建议

### 9.1 复用现有接口

```text
POST /api/agent/enroll
POST /api/agent/register
POST /api/agent/heartbeat
POST /api/agent/evt
GET  /api/agent/commands
GET  /api/agent/self-update
GET  /api/agent/packages/{versionId}
```

### 9.2 建议新增内部构建接口

以下接口仅给 internal.key 或运营后台使用，不直接暴露给 C 端：

```text
POST /api/skill-admin/builds
GET  /api/skill-admin/builds/{id}
POST /api/skill-admin/builds/{id}/cancel
GET  /api/skill-admin/builds/{id}/runs
```

要求：

- `POST` 返回 202 + build id；
- 构建异步执行；
- 构建结果不能由调用方直接写 success；
- 只能通过真实构建服务回写。

### 9.3 建议新增服务

| 服务 | 职责 |
|---|---|
| `BuildCoordinator` | 接收请求、幂等、入队 |
| `TargetProfileResolver` | 解析并校验 target profile |
| `BuildWorker` | 执行构建、更新 run |
| `ArtifactInspector` | 文件、哈希、结构、安全扫描 |
| `ArtifactSigner` | 为产物/构建证据签名 |
| `ArtifactResolver` | 根据 version + model + carrier 选择产物 |
| `DeployGate` | 下发前统一检查 |
| `AgentTaskPlanner` | deploy_task -> Agent 指令 |
| `InstallTransaction` | 设备侧安装事务 |

---

## 10. 标准转码器的测试策略

### 10.1 构建契约测试

- 同一输入、profile、builder、options 产生相同 build key；
- 同一 build key 重复请求不重复建产物；
- 缺 manifest、错 kind、错 carrier、错 model 全部失败；
- `success` 必须有 `artifact_asset_id`；
- 产物哈希与 `skill_assets.file_hash` 一致；
- 任何失败都不能进入 Agent 下发。

### 10.2 转码黄金测试

对动作类：

- 输入固定动作集；
- 固定 profile；
- 输出文件结构固定；
- 关节/时间轴/帧率/单位/限位检查；
- 可重复生成相同字节，或以明确的非确定性字段记录原因；
- 生成动作统计报告。

对应用类：

- 目标架构正确；
- 非 root；
- 无 privileged；
- 依赖打包完整；
- 启动探针通过；
- 无敏感凭证。

### 10.3 安全测试

至少覆盖：

- 错误签名；
- 哈希不匹配；
- ZIP 路径穿越；
- 符号链接逃逸；
- 解压炸弹；
- 非法文件类型；
- 未授权权限声明；
- 源文件/目标机型不匹配；
- 高资源占用；
- builder 崩溃；
- 构建 worker 队列积压。

### 10.4 SIL 与 HIL

- SIL：仿真/容器/模型级验证，不宣称真机可交付；
- HIL：吊架、急停、断网、断电、错误固件、错误 SDK、错误机型、回滚；
- 每个 L1 机型必须有独立 HIL 记录；
- 没有 HIL 的动作只能处于 L2 或实验状态。

---

## 11. 分阶段落地计划

### Phase 0：决策冻结

交付物：

- ADR-0001 审批；
- 明确 Agent 不接管实时运动；
- 明确 `.rsp` 不进入正式格式；
- 明确标准转码器为平台异步构建面；
- 选定第一个目标机型。

退出条件：人类负责人签字，不再在实现中临时扩大边界。

### Phase 1：契约与缺口收口

交付物：

- 冻结 `zhaohuan.skill/v2` 转码输入；
- 冻结 `TargetProfile` v1；
- 冻结 `BuildRequest` / `BuildResult` / artifact 描述；
- 设计 `skill_build` 增量字段和 `skill_build_run`；
- 设计 InstallAdapter 接口；
- 定义 L1/L2/L3 判定；
- 写数据 preflight 和迁移计划。

退出条件：契约评审通过，不写业务 production 写接口。

### Phase 2：标准转码器 MVP（pass-through）

先做不依赖动作算法的产品闭环：

- 上传一个厂商成品包；
- 通过 `vendor.passthrough` 生成构建记录；
- 产物落到 `skill_assets`；
- 完成静态检查和目标 profile 校验；
- 能创建 build success；
- 仍不宣称自动跨品牌转码。

退出条件：一个目标机型的成品包可以从上传、构建记录、审核到待下发。

### Phase 3：Agent 模拟器闭环

- 实现设备和平台的身份、任务、回报模拟；
- 验证签名、哈希、过期、重复、断网、取消；
- 验证同一任务重启不会重复安装；
- 验证安装失败可以回到旧版本；
- 使用独立测试库，禁止对生产库做破坏性操作。

退出条件：无真机情况下，平台与 Agent 契约稳定。

### Phase 4：单机型 HIL

- 选定宇树 G1 或其他一个明确目标；
- 固定固件、SDK、目标 profile；
- 通过厂商官方高层接口安装/启停；
- 完成吊架、急停、断网、断电、回滚；
- 只对该组合标 L1。

退出条件：形成真实 HIL 证据，才允许开放 `agent` 生产下发。

### Phase 5：自动动作转码

- 接入一个经过验证的 `motion.retarget` builder；
- 完成黄金动作集和 SIL；
- 在目标机型做 HIL 抽样；
- 生成质量报告和失败原因；
- 仅对已通过的动作类别开放。

退出条件：不把“能生成文件”误报成“真机可执行”。

### Phase 6：第二厂商

- 新增第二个 `CarrierBuilder`；
- 新增第二个 `InstallAdapter`；
- 新增第二个 TargetProfile；
- 完成独立 HIL；
- 不允许复用第一厂商的安装路径假装“零改动”。

退出条件：每个厂商分别有构建、安装和验证证据。

### Phase 7：实时接管（另立 ADR）

LiveKit、摇杆、音频 AEC、远程坐席、隐私和保险不在本蓝图内。必须另立项目和 ADR。

---

## 12. 运维与可观测性

必须记录：

- build id；
- build key；
- source digest；
- target profile digest；
- builder key/version；
- toolchain/image digest；
- artifact SHA-256；
- queue latency；
- build duration；
- failure reason；
- install task id；
- device did；
- install_record id；
- rollback result；
- kill switch result。

关键指标：

- 构建成功率；
- 构建重试率；
- 构建缓存命中率；
- 产物验证失败率；
- 按机型/固件统计安装成功率；
- 回滚成功率；
- 离线设备上线处置率；
- 签名/哈希失败次数；
- 任务滞留时长。

日志必须脱敏：不打印设备私钥、API secret、完整签名 URL 中的敏感参数。

---

## 13. 风险与应对

| 风险 | 等级 | 应对 |
|---|---|---|
| 动作重定向质量不足 | 高 | 黄金测试、SIL、HIL、只开放已验证动作类别 |
| 厂商 SDK 版本漂移 | 高 | TargetProfile 锁 SDK/固件，构建与安装双校验 |
| 设备端被第三方代码攻击 | 高 | 分进程、容器、能力白名单、签名和最小权限 |
| 构建不可复现 | 高 | 固定工具链、输入摘要、build run、产物哈希 |
| 跨品牌承诺过度 | 高 | L1/L2/L3 + 每厂商独立验证 |
| 构建队列不常驻 | 中 | 专用 queue worker、健康检查和告警 |
| 产物存储丢失 | 高 | 私有持久卷/对象存储、哈希校验、备份 |
| 设备离线导致回收延迟 | 中 | 下次上线执行，产品文案明确 |
| 人工通道无法自动卸载 | 中 | 保留 UI/运营说明，不伪装成 Agent 能力 |
| LiveKit/遥操作合规 | 高 | 另行立项、隐私/保险/EULA 评审 |

---

## 14. 验收清单

### 平台

- [ ] `manifest.json` 校验通过并且没有 `.rsp` 公共格式。
- [ ] `TargetProfile` 有版本、摘要和验证状态。
- [ ] `skill_build` 能记录 source、builder、profile、output、hash 和 run。
- [ ] `skill_build.status=success` 必须对应真实 `skill_assets`。
- [ ] `deploy_task` 创建前执行构建闸门、兼容性闸门、授权闸门和 kill switch 闸门。
- [ ] 构建失败、产物缺失、机型不匹配时无法创建 Agent 任务。

### Agent

- [ ] 使用现有 enroll/register/heartbeat/commands/evt 接口。
- [ ] 校验任务签名、SHA-256、任务幂等和防降级。
- [ ] 解包安全检查通过。
- [ ] 安装失败不报 success。
- [ ] 旧版本可回滚。
- [ ] 断网、断电、磁盘不足、错误机型均有明确失败码。
- [ ] kill switch 能让已装设备在下一次上线时执行处置。
- [ ] Agent 不在设备端执行转码或重打包。

### 真机

- [ ] 固定机型、固件、SDK、Agent 版本。
- [ ] HIL 吊架验证。
- [ ] 急停由厂商安全控制链负责。
- [ ] 验证安装/启停/卸载/回滚。
- [ ] 验证网络中断和电源中断。
- [ ] 没有 HIL 不标 L1。

### 诚实文案

- [ ] 不写“所有机器人开箱即用”。
- [ ] 不把 L2/L3 写成 L1。
- [ ] 不把 pass-through 写成自动跨品牌转码。
- [ ] 不把 SIL 写成真机。
- [ ] 不把 `.rsp` 写成已批准格式。

---

## 15. 仍需人类拍板的事项

1. 第一个 HIL 目标机型是否固定为宇树 G1。
2. Agent 核心采用 Go/Rust supervisor + 厂商插件子进程，还是 Python 单进程容器；建议前者，后者只作为过渡。
3. 动作重定向算法选型：自研、GMR 类工具或厂商官方工具；必须先过黄金集和 HIL。
4. TargetProfile 的维护责任人：平台工程、设备端工程还是厂商适配负责人。
5. 构建产物是否使用独立签名密钥，还是第一版复用 `SigningService`。
6. 第一版是否只开放 `vendor.passthrough`，把 `motion.retarget` 放到 Phase 5。建议是。

---

## 16. 最终交付定义

本蓝图全部实施完成后，平台应具备：

```text
中立技能上传
-> 权限/安全/兼容性校验
-> 平台离线标准转码
-> 不可变成品与构建证据
-> 审核与发布
-> 设备能力匹配
-> 签名任务下发
-> Agent 验签安装
-> 启停/卸载/回滚/回收
-> 熔断/审计/事故闭环
```

但必须同时记住：

- 标准转码器不保证任意技能跨任意机型；
- Agent 不是实时运控器；
- 厂商安全控制器仍是运动安全最终责任方；
- 没有目标机型 profile、成功产物和 HIL 证据，就没有 L1；
- 手动安装通道当前可用，但不等同于自动下发；
- 远程接管和 LiveKit 不在本蓝图内。
