# 机器人技能设备运行时与标准转码器 · 重构版落地蓝图

- 版本：0.3（一份标准技能、多机型编译与执行的完整开发方案）
- 日期：2026-11-03
- 状态：**Draft for review，待 ADR-0001 审批；本次只修订文档，未实现下述新增契约**
- 决策记录：[ADR-0001：设备端 Agent 边界与标准转码器分层](./adr/ADR-0001-device-agent-and-standard-transcoder.md)
- 范围：标准行为/能力契约、平台离线编译/动作转换、资产交付、设备端安装与执行生命周期；不含实时运控、遥操作、厂商云或固件开发。
- 原稿：`f176d6427d55a633e94b20b01d01c0e7400cc075`；核验代码：`542502b64b817f35ab9f31f382564a2ea9dfe849`。两提交间业务代码无差异。部署配置、真实设备和 HIL **未核验**。

## 0. 结论与读法

**平台构建并审核不可变成品，Agent 验证并安装成品，厂商控制器保留实时运控和硬件安全控制职责。** D6 的开发者承诺、沙盒受控执行、用户分级告知留痕仍须落实，不能以“调用厂商高层接口”为由免除平台自身的权限和交付控制。

完整开发工单与验收见§17—§21：先完成同一标准源在两个品牌上的行为编译与执行，再扩展动作重定向；§1—§16保留交付、安全和恢复基线。可以推进契约与隔离 MVP；生产 Agent、离线试用和动作转码须先满足本蓝图的退出条件。文中的“必须”均为**拟议实现及验收要求**，不代表当前代码已满足，也不代表待拍板事项已获批准。

```text
开发者合规包 -> 成品导入/离线构建 -> 不可变资产与构建证据
-> 最终产物审核 -> 按操作检查与签名 -> Agent 持久接收
-> 厂商安装插件 -> 独立设备观测 -> 回报/对账 -> 安装事实
```

四种结果分开验收：构建成功证明有合格产物；安装成功证明目标设备安装了对应字节；运行正常证明指定健康探针通过；安全可交付还需要规定场景下的 SIL/HIL、权限隔离和 D6 留痕。前一项不能替代后一项。

## 1. 目标与非目标

1. 复用 `zhaohuan.skill/v2`、`manifest.json`、`device_binding`、`skill_assets`、`skill_build`、`deploy_task` 和 `install_record`，不另造 `.rsp` 公共格式。
2. 先在一个明确的设备/固件/SDK/包组合上完成成品导入与可信安装，再评估动作转码和第二厂商。
3. 交付对象必须能沿源资产、工具链、profile、成品、审核、任务和安装事实追溯。
4. 断网、重启和回报丢失后能够对账，回滚能够恢复明确的良好产物。
5. 不接管关节、步态、平衡、底盘速度仲裁或硬件急停；不加入 LiveKit 和远程摇杆。
6. 不承诺任意技能跨任意品牌；不因一次 HIL 或单机型 L1 宣称项目 M4 完成。项目理解手册的 M4 三厂真机口径保持有效。

## 2. 当前仓库基线与评审证据

### 2.1 已核验事实

以下路径相对 `my-bagisto-store/packages/Webkul/Shop/src/`，均按页首代码提交核验：

| 能力 | 原始位置 | 可复用范围与限制 |
|---|---|---|
| 设备接入 | `Http/Controllers/API/AgentController.php`、`Services/Deploy/DeviceCredentialService.php` | 已有 enroll/register/heartbeat、独立凭据；实际部署是否强制 device 模式未知 |
| 指令和下载 | `Services/Deploy/SigningService.php:120`、`DeployTaskService.php:522` | 已签任务、技能、版本、集合指纹、单文件 SHA-256、时间戳；操作、设备和期限尚未纳入完整签名契约 |
| 构建消费 | `Services/SkillDelivery/SkillDeliveryAssetResolver.php:128` | 已按目标机型读取成功 build 的产物，不能再称“未进入交付链路” |
| 构建生产者 | `Services/SkillCompile/Builders/MotionCarrierBuilder.php` | 实际调用仍返回 pending、产物为空，尚无本轮验证的真实动作构建产物 |
| 参考 Agent | `Http/Controllers/AgentSetupController.php:170` 的 `AGENT_PY` | 已有下载、验签、解包、installed.json 和生命周期函数；尚无厂商生产插件与真机恢复证据 |
| 载体分类 | `Models/SkillAsset.php:202`、`:213` | `isFile()` 仅认 file；`isFileBacked()` 另认 motion_data/app_package/vendor_package；不能混用 |
| 适配证据 | `Services/SkillDelivery/CompatibilityService.php` | 已有资产 ID/hash 匹配；完整固件/SDK/Agent/profile 证据范围仍须验证 |

### 2.2 本次隔离实验与未验证项

本轮四个 PHP/Python 脚本不连接数据库、不读取生产密钥、不操作设备。原始脚本与输出随评审附件 `agent-runtime-review-evidence.zip` 交付，未纳入 Git；退出码 0 表示观测反例成立，**不是功能验收通过**。

| 观测 | 证据性质 | 结论边界 |
|---|---|---|
| 蓝图清单经实际 ManifestValidator 校验有效；MotionCarrierBuilder 仍 pending | 真实类隔离执行 | 清单示例本身不是阻塞，动作产物未生成 |
| 三种扩展落盘载体 isFile=false、isFileBacked=true | 真实模型隔离执行 | 按原稿直接接线会被现有 file 交付路径排除；完整数据库链路未执行 |
| 改 operation/did/expires_at/model 后原签名仍通过，改包 SHA-256 被拒绝 | 真实签名类、临时密钥 | 签名覆盖不足；不等于已复现远程入侵 |
| 共享 key 无设备身份时 auto 接受、device 返回 401 | 真实中间件、隔离配置 | 默认/兼容分支风险，不代表生产已启用 auto |
| 安装成功后 done 丢失，两轮轮询仅尝试回报一次 | 原始 Agent，网络/下载/验签为测试桩 | 未见持久任务和事件补报机制；未执行 HTTP/数据库对账 |
| 先装 v1、升级 v2，再以 v2 执行有包回滚，仍是 v2 却标 rolled_back | 原始安装函数执行；云端命令链静态核验 | 坏版本与恢复版本语义不一致；未执行完整灰度回滚集成 |

实际厂商包、工具链镜像、原始设备报文、实时进程状态和 HIL 数据本轮均未取得。手动安装可用是既有项目口径，本轮未重新执行真实业务验收；不能把历史交接记录当今天的部署事实。

## 3. 分层与职责

| 模块 | 所在位置 | 职责与禁止事项 |
|---|---|---|
| CarrierBuilder | 平台离线构建面 | 成品导入或生成目标载体；不安装、不运行，不在 Web 请求内执行 |
| 平台交付面 | Laravel 模块化单体 | 资产/审核/授权/任务/签名/对账；扩展沿用配置注册表，不做微服务改造 |
| Agent 与 InstallAdapter | 设备侧 | 身份、验签、持久任务、安装事务和厂商插件；不在设备现场转码或重打包 |
| VendorRuntime | 厂商运行环境 | 实际运行、FSM、运控与安全退出；接口支持范围需厂商资料及指定版本验证 |

第三方技能不能获得 Agent 凭据、宿主控制权或未声明的厂商 SDK 能力；必要的高层调用经受控能力桥接。不能假定容器化或厂商控制器天然使任意第三方动作安全。

## 4. 标准数据契约（提案，Phase 1 冻结）

### 4.1 中立清单保持不变

消费现有 `ManifestValidator`，不改公开 schema。以下为已通过现有校验的示例，机型与版本值仅示意，不证明真机兼容：

```json
{
  "schema": "zhaohuan.skill/v2",
  "kind": "motion",
  "name": {"zh_CN": "展厅迎宾挥手", "en": "Showroom Greeting Wave"},
  "version": "1.0.0",
  "description": "面向展厅迎宾的挥手动作",
  "pricing_mode": "buyout",
  "delivery_channels": ["agent", "manual"],
  "permissions": ["motion_control"],
  "resource_type": "motion",
  "compatibility": {"models": [{"vendor": "unitree", "model": "unitree_g1", "min_firmware": "1.0.0", "min_app": "1.0.0", "dof": 23}]},
  "safety": {"risk_level": "medium", "requires_open_area": true, "estop_required": true, "max_duration_s": 30},
  "motion": {"format": "bvh", "fps": 30}
}
```

校验通过只证明清单结构，不证明所声明的权限可授予、动作可执行或版本受支持。不得上传裸动作后绕过开发者责任自动代客包装；DEV-02/DEV-04 旧裁决未因本次修订失效。

### 4.2 TargetProfile 与验证范围

profile 是版本化、受评审的目标环境描述，建议存放 `my-bagisto-store/resources/skill-target-profiles/`。**该目录及协议尚待实现**。

必需信息：硬件变体与 model_code、OS/架构、准确固件与 SDK 版本、支持载体和实际安装接口、Agent/插件最低能力、关节序与单位/坐标系/限位（动作类）、资源限制、健康与安全退出方法。

`profile_digest` 必须覆盖 profile 及其引用的限位、映射、模型等文件内容，不能只哈希一个包含可变外部路径的 JSON。生产组合禁止用 `1.x` 或 `vendor-defined` 等占位值替代验证。

HIL 证据绑定：`artifact_sha256 + profile_digest + 硬件变体 + 固件 + SDK + Agent + 插件版本/摘要 + 动作参数范围 + 场景 + 测试记录`。profile 的 verified 状态不能自动使同机型全部技能获得 L1。关键组合改变时须重新判断证据适用性，不得静默继承。

### 4.3 BuildRequest 与构建身份

```json
{
  "skill_version_id": 123,
  "source_asset_id": 456,
  "target_model_code": "unitree_g1",
  "target_profile_digest": "sha256:example-profile",
  "builder_key": "vendor.passthrough",
  "builder_version": "example-unapproved",
  "toolchain_digest": "sha256:example-toolchain",
  "options": {"strict_limits": true}
}
```

服务端校验资产归属与参数白名单，自行计算 build_key，不能信任调用方提交的缓存键或 success。构建身份为规范化对象的 SHA-256，覆盖：

```text
identity_schema + source_digest + manifest_digest + target_model_code
+ target_profile_digest（含引用文件）+ builder_key/version
+ toolchain_digest（镜像、依赖锁、算法/模型版本）+ normalized_options
```

采用 §4.5 的 JCS 编码规则，不用无边界字符串相加。构建器版本如严格绑定不可变工具链，也须验证该绑定；仅在 run 中记录工具链、却仍复用旧缓存，不满足本契约。

相同键复用前须确认成品仍存在、哈希有效、未撤销；构建复用不绕过当前审核、授权与兼容闸门。共享相同字节不代表不同技能版本共享审核或 HIL 结论。

### 4.4 成品、封装与哈希范围

`skill_assets` 仍记录交付资产，禁止绕开它直接写任务 URL。但“保持资产表”不等于“现有所有载体已支持交付”。

**P-04 推荐但未批准：**外层交付封装登记为 `carrier_type=file`，内部元数据描述 motion_data/app_package/vendor_package；另一方案是显式扩展所有相关解析、审核、指纹和下载规则。选定前不得直接把 `isFile()` 全面改成 `isFileBacked()`。

P-04 还须说明平台外层封装与 DEV-02“不得代客打包”的范围差异；未批准前仅验证开发者已提交的合规成品，保持字节不变并外置构建元数据。不从裸文件代造合规包，不代填开发者的权限、风险或责任承诺；下列新增外层布局仅在该方案获批后适用。

```text
delivery-bundle.zip
  manifest.json
  artifact.json          # 来源、build_key、profile、内部载体类型、payload清单
  payload/<厂商成品>     # pass-through 要求此层原始字节不变
  sbom.json              # 应用类必须；其他类型按检查策略
```

- `payload_sha256`：原始厂商载体的字节摘要。
- `package_sha256` / `output_sha256`：最终封装文件的同一个字节摘要，放在外部资产/构建记录及签名任务中。
- `package_hash`：现有交付物集合指纹，不等于单文件 SHA-256。
- 包内 artifact.json 记录 payload 文件摘要，不存包含自身的整包摘要；分离签名/构建证明存包外并绑定整包摘要，避免自引用。
- pass-through 保持的是 payload 字节；外层封装是新资产，审核和任务绑定最终外层。不得声称封装后整包与原厂包逐字节相同。
- `created_at`、run 日志、审核/HIL 结论等易变记录放包外；必要包内时间使用固定来源时间。后续验证结果追加为外部证据，不改已签名包。

### 4.5 Agent 指令、签名与信任根

**当前 v1 事实：**`SigningService::canonical()` 签六字段串；命令另带 operation，缺少完整的设备/期限/重放绑定。不能将此标为 v2 已实现。

**生产前拟采用 agent-task/v2：**消息由 `signed`、`signature`、可刷新 `package_url` 组成。signed 必须包括下表字段；命令类型不用的字段显式为 null，缺字段不等同 null。数据库数字 ID 与单调序号用十进制字符串，task_id、did 等保留各自既有字符串格式；operation、协议时间用有界整数。未知协议、重复 JSON 键、未知 signed 字段或错误类型一律拒绝。

| 字段组 | signed 内容 |
|---|---|
| 协议与密钥 | protocol_version、signature_algorithm、key_id；算法白名单与可信公钥由已配置的信任根决定，禁止从命令任意下载公钥 |
| 执行意图 | task_id、command_seq、did、operation、skill_id、skill_version_id、version |
| 成品与环境 | package_asset_id、package_hash、package_sha256、build_key、target_model_code、target_profile_digest、审核/验证记录摘要 |
| 回滚 | revoked_version_id、restore_asset_id、restore_sha256；正常安装为 null，回滚按 §7.4 显式填写 |
| 技能执行（§17.6提案） | execution_id、target_release_id、installed_asset_id/sha256、plan_digest、binding/runtime摘要、类型化execution_params及其摘要、execution_deadline；取消任务绑定被取消execution_id，不执行任务的字段显式为null |
| 授权 | entitlement_id、authorization_policy_version、authorization_not_before、authorization_expires_at、revocation_epoch；买断授权不得被变相改成订阅 |
| 指令时效 | issued_at、task_expires_at；与授权期限分开，过期命令不得安装或启用 |

拟议签名字节为 `UTF-8("summon-mecha/agent-task/v2") || 0x0A || UTF-8(JCS(signed))`，`0x0A` 是单个 LF 字节；JCS 按 [RFC 8785](https://www.rfc-editor.org/rfc/rfc8785)。RSA/SHA-256 与现有实现衔接的算法标识、参数和 PHP/设备端黄金向量须在 Phase 1 一起冻结。公钥启动分发、轮换、撤销、设备离线恢复按 P-06 审批；公钥不能只靠同一次未认证下载建立信任。

- 先验签、核身份/期限/本地任务记录，再下载和解包。同一 task_id 的 signed 内容及 command_seq 冻结，重复消息只恢复该任务或补报，不创建第二次安装；已完成任务不得重新执行。同 task_id 改内容必须拒绝。command_seq 以设备为作用域持久单调递增；低于已接受序号且不在本地 journal 的新任务拒绝，journal 内未完成任务只按已有指令恢复。序号分配、并发领取与磁盘恢复纳入黄金测试。
- URL 可重新签发，但只能指向配置允许的 HTTPS 平台来源；不得使用命令任意 URL 访问本机、内网或携带额外凭据。URL 更新不改变 task_id、序号、资产、摘要或已签期限；仅重签相同 signed 不重置任务身份。若任务已过期或需换 key_id，须先对账原任务的未知结果，再重新授权并建立可追踪的替代关系；替代不能重复已经发生的副作用，旧任务结果继续保留，不能在原 ID 下延长期限。
- 新生产设备必须使用 `AGENT_AUTH_MODE=device`，停止发放共享 key；已吊销设备不能省略 did 回退到共享认证。升级后的生产 Agent 禁止自动降级到六字段 v1；旧协议只可留在明确隔离的联调环境。
- 创建任务、领取任务、设备执行前分别复查绑定、授权期限、撤销、熔断与验证范围，按 §8 区分操作。不能以创建时通过为由执行数小时前已失效的安装。
- 密钥/可信时钟/撤销状态不明时，停止新的安装和启用，保留按可信指令安全停用的能力；不得用共享 key 绕过身份恢复。

### 4.6 回报、持久接收与对账

现有 phase 保持 `downloading/installing/download_failed/done/failed/timeout`，不得把新增消息类型误写成现有接口已接受。

v2 拟在现有 evt 入口扩展持久接收、执行事件和事实对账消息；路由契约、ACL、错误码与数据库增量须在 Phase 1 评审。回报须绑定已认证 did、task_id、event_id、单调 event_seq、operation、实际资产摘要、实际激活版本、插件版本、健康结果和错误原因。平台不能仅凭字符串 done 推断实际运行。

Agent 验签和预检后先把任务及指令摘要落 journal，才发送持久接收确认；完成副作用后先落事实与待发事件，再发网络请求。事件重试保持 event_id 不变；平台持久处理后返回绑定 did、task_id、event_id 和处理摘要的 ACK，重复事件返回同一处理结果，不重复发授权或落安装事实。ACK 必须来自认证的平台连接；只收到 HTTP 200、但无预期 ACK，不得删除待发记录。

服务端 `delivered_at` 仅表示投递，不等于设备持久接收。断电后以 journal、事件 outbox 与插件独立观测恢复；超时表示结果未知，不等于未安装。迟到事实通过对账记录补充，保留原 timeout 及事件历史，不能强改原任务 success 掩盖中间故障。

命令或使用授权到期不阻断已发生事实的补报、ACK 和受认证对账。若到期发生在安装外部调用中途，按插件事先冻结的安全完成/退出策略处理并报告实际结果，不以强杀或续发新任务代替恢复。收到任务的 ACK 丢失时，平台重投相同 task_id，Agent 依据 journal 补发确认，不重新执行。

## 5. 平台成品构建

### 5.1 运行与隔离

沿用 `config/skill_builders.php` 注册表；可增加受控构建配置，但不得并存两个互相矛盾的 builder 真相源。专用 `skill-builds` worker 不消费订单队列。Web 只校验、入队、返回构建 ID。

构建进程与 Web、签名私钥及第三方源代码隔离；限制 CPU/内存/磁盘/时间，默认关闭网络，仅放行受控依赖/资产源；不挂 Docker 宿主控制 socket，不以 privileged 运行。签名由可信控制面在产物检查后完成，不能让 builder 自签伪造证据。

上线须核验常驻 worker、调度、积压告警、存储与恢复；存在 `queue:work` 命令不代表进程已运行。本轮未核验生产运行状态。

### 5.2 状态和构建运行历史

现有状态为 pending/building/success/failed；拟议 queued/verifying/cancelled 需增量迁移与契约评审后采用。每次运行写独立 run、工具链摘要、输入输出、开始/结束和错误；重试不能覆盖旧 run。

`success` 必须有真实资产、最终 SHA-256 和检查证据；它证明构建结果，不代表审核通过或 L1。曾经成功的构建不得为回退改写成 pending/failed；另记发布禁用/撤销状态及原因。取消任务与并发 worker 的迟到结果通过 run/租约代次隔离，失效 worker 不得覆盖新运行。

### 5.3 流水线与缓存

```text
源资产/清单校验 -> 锁定完整输入和profile -> 计算build_key
-> 独占领取run -> 隔离builder -> 成品检查 -> 私有持久存储
-> 资产/构建记录提交 -> 外部构建证明 -> 最终字节审核 -> 发布闸门
```

相同键并发只接受一个活动构建，失败重试产生新 run。持久存储与数据库之间发生中断时先对账，不能因上传了临时文件就写 success。审核记录绑定最终资产摘要；更新成品必须新建资产并重新判断审核与验证。

在两个隔离环境使用锁定输入主动重建并比较字节；缓存命中不算重建。非确定性差异必须定位，未达到逐字节一致时明确标为“不可复现”，不得只写“已说明原因”便通过可复现验收。

### 5.4 Builder 范围

| Builder | 最小结果 | 前提与阶段 |
|---|---|---|
| vendor.passthrough | 验证一个已有厂商成品，保留payload，生成可追溯外层 | 第一阶段建议；须确认真实安装接口和P-04封装，不等于动作转码或M4完成 |
| portable.behavior | 受限XML4标准源→能力绑定/IR→固定runner目标应用 | 首期主线；与source_format一起选择，不等于后续任意复合资产组合 |
| motion.retarget | 源动作→规范化轨迹→目标资源及质量报告 | P-01重新审批后才进入Phase 6动作专项；需目标模型、映射、算法、输出接口、黄金集、SIL/HIL |
| app.container | 锁定架构/基础镜像/依赖的应用产物与SBOM | 不替应用实现厂商SDK；权限与网络能力须在目标运行环境实际验证 |
| composite.bundle | 可追踪子资产与可恢复组合 | 后续评估，不进入第一版承诺 |

动作类检查单位、坐标、关节顺序、限位、时间连续性、速度/加速度及目标允许的约束。裁剪限位不能自动证明动力学可行或不会跌倒。支持 BVH/SMPL-X/FBX 等仅是候选输入，未接入的格式不得标为已支持。

应用默认非 root、只读文件系统、资源受限、禁 privileged/host network。若厂商通信要求与默认隔离冲突，先提交最小能力/网络例外及验证，不能直接给第三方程序开放宿主网络、设备或完整 SDK 对象。

## 6. 数据模型建议（未迁移）

| 对象 | 必须补充或冻结的内容 |
|---|---|
| skill_build | build_key、源/清单摘要、builder、toolchain_digest、profile摘要、最终资产/哈希、最近run；保留历史构建事实 |
| skill_build_run | attempt、租约代次、worker、输入/工具链/输出摘要、检查结果、时间、脱敏日志和错误 |
| skill_assets | 保持最终资产记录；P-04决定封装与carrier映射，不能假定现有file规则已覆盖其他载体 |
| 审核与验证记录 | 绑定最终资产哈希及适用环境；HIL追加为外部证据，不改包内元数据 |
| deploy_task | 冻结执行对象、签名协议、序号/期限、授权策略、坏版本和恢复目标；刷新URL不重新选择产物 |
| 设备journal/outbox | task_id、指令摘要、接收/执行检查点、event_id/seq、待ACK事件、实际激活资产和恢复证据 |
| 平台对账记录 | 保留原任务结果、设备独立观测、差异、修复动作与审计；不把旧timeout直接抹成success |

以上是拟议字段/记录，名称和迁移由 Phase 1 冻结。所有迁移使用 additive 方案；测试仅用 `summon_mecha_step10_test`，禁止对 root fresh/truncate/seed。

## 7. 设备端恢复机制

### 7.1 进程与权限

Agent 核心与厂商插件分进程、最小权限；具体语言、监督方式和负责人待 P-05。安装管理凭据不传给技能，插件异常不得拖垮 Agent。第三方源代码不能与签名或宿主控制面共享权限。

### 7.2 两套状态，持久化边界

平台任务状态与设备本地执行状态分别维护，映射需评审；下列是**拟议本地状态**，不是已有 phase：

```text
VERIFIED -> RECEIVED_DURABLE -> DOWNLOADING -> STAGED
-> INSTALLING -> HEALTH_CHECKING -> COMMITTED -> REPORTED_ACKED
任意阶段 -> FAILED / RECOVERY_REQUIRED
RECOVERY_REQUIRED -> OBSERVED -> RESUMED / RESTORED / DISABLED / MANUAL_REQUIRED
```

每次外部调用前写意图，完成后写观测结果和待发事件；原子替换一个 installed.json 不能代替任务 journal。持久化须定义原子提交、fsync/磁盘错误处理与重启恢复；仅做进程重启测试不能宣称断电安全。

同设备安装/卸载/启停与冲突执行串行，独立能力按§17.6受控资源租约协调；同 task_id 重复到达不重复副作用。恢复未完成任务仍须复核期限、撤销与实际设备状态，不能以 journal 已存在为由继续执行已失效安装。过期任务可以补报已发生的事实，不能重新安装或启用。任务序号、取消与撤销具有明确优先级；已提交安装不能通过删除任务假装取消，须另发处置任务并保留原事实。

接受更高序号的撤销、停用、卸载或回收后，受影响的旧安装/启用任务只能对账、补报或安全退出，不得从 journal 恢复并重新激活。恢复良好版本需要新的明确恢复授权，不能重用已被后续处置覆盖的旧安装意图。

### 7.3 安装事务与恢复表

```text
验签/身份/时效/授权预检 -> journal持久接收
-> 下载限量包并验hash -> 解包安全检查 -> 独立staging
-> 插件prepare及目标环境复核 -> 安装 -> 独立observe/health
-> 持久提交激活事实和待发done -> 重试直到平台ACK
```

ZIP 必须检查越界、符号链接、体积/文件数上限、非法文件及权限；禁止执行包内任意安装脚本绕过声明权限。失败时保留已有良好版本及恢复依据。

| 故障边界 | 重启后必须做什么 |
|---|---|
| 收到命令但未持久接收 | 依据平台投递/接收协议重取；不能因delivered_at已写就永远丢任务 |
| 解包或安装中断 | 读journal，独立检查staging、厂商资源与激活版本；不得把残留目录当完成 |
| 厂商调用返回前进程退出 | observe外部实际副作用；按幂等令牌确认/补偿，不能盲目重试不可逆操作 |
| 本地提交后done丢失 | 从outbox重报原event_id；平台按事件去重，不重新安装 |
| 平台已timeout，设备后来报告已安装 | 记录迟到事实并对账；保留原timeout，决定继续、恢复或停用 |
| 无法确认厂商状态/恢复失败 | 标记MANUAL_REQUIRED、禁止新启用并告警；按厂商安全退出，不伪造回滚成功 |

### 7.4 InstallAdapter 与真实回滚

提案接口：`prepare / install / observe / enable / disable / uninstall / rollback / health`。输入必须包含 task_id/幂等令牌、成品摘要和目标环境；输出为实际资源ID、激活版本/路径、摘要、健康与恢复结果。

每个插件先声明：是否支持独立观测、幂等安装、安全停用、恢复旧版、撤销资源注册；给出不可逆步骤与补偿方法。缺失能力不能靠统一接口名称补齐。

**坏版本与恢复目标分开：**`revoked_version_id` 指待撤回版本；`restore_asset_id/restore_sha256` 指获批良好成品，不能把坏版本再次作为恢复包发送。无包本地恢复也要定位到真实存在、哈希正确、兼容且未撤销的旧安装，并切换实际激活路径。

首装没有良好旧版时，按获批安全停用/卸载策略处理，结果不得写成“已恢复旧版”。成功条件包括独立 observe、实际payload摘要与健康检查，不仅是 rolled_back 标签。平台安装记录应分别记录撤回对象和真实恢复对象。

显式恢复可与普通安装防降级策略区分，但必须有有效的回滚授权和良好目标证据；不能借回滚重装已撤销或不兼容的产物。当前代码已有OP_ROLLBACK防降级豁免，不得误写为“现有防降级一律阻止回滚”。

### 7.5 试用、离线、撤销和熔断

D4 保持**二十分钟、仅在线交付通道可试用**。P-03 只决定起算事件及中途断网后的安全执行规则，不改变时长，不给 manual 增加试用。

当前 TrialService 从发放时起算；本轮尚无端侧准时停用证据。不得同时保留“云端发放起算”和“设备上线后另算二十分钟”。采用何种起算，均须由平台与设备共享已签名的策略、起算事实和绝对截止时间。

未能取得可信期限/时钟时禁止新试用和续启用；重启、时钟回拨、断网不得重新赠送时长。若首次合法激活起算获批，必须先完成激活确认及租约发放，不能离线自行起算。若无法在断网后可靠执行既定期限，保持试用关闭，不能宣称强制回收已完成。

到期、撤销与kill switch调用厂商允许的安全退出/停用流程，不等于直接杀运控进程或硬件断电。物理停用能力、延迟和失败处理须按插件验证；不承诺设备所有者具有root权限时授权绝不可绕过。

kill switch对新安装/启用即时阻断；离线设备无法即时收到新撤销，须下次上线先处理。manual安装当前不能自动卸载。买断离线策略待P-03确认，不得引入订阅或定期付费续期。

### 7.6 健康与独立观测

上报Agent/插件/固件/SDK版本、架构、磁盘、进程/容器状态、激活资产摘要、启动失败码。健康是只读观测，不得夹带遥操作。installed.json仅是本地记录，验收需独立读取厂商资源/实际目录及运行状态；进程存在不等于动作可安全执行。

## 8. 按操作分闸门

所有操作先通过设备身份、签名、时效、防重放及操作授权；处置任务也不能绕过这些安全条件。

| 操作 | 额外条件 | 不得误阻断的情形 |
|---|---|---|
| 安装/升级 | 成功构建、有效成品、审核发布、目标组合验证、bound、有效授权、允许agent且未熔断；创建/领取/执行均复查 | 任一失败拒绝，不回退到任意原始上传文件 |
| 启用 | 当前实际产物有效、兼容和授权有效、未撤销/熔断；复查运行环境 | 不能因过去安装成功就跳过当前限制 |
| 执行（7，提案） | 已安装且启用的获准target release、能力/参数/资源范围、有效授权、执行期限、未撤销/熔断；参数值及摘要均受签名约束 | 不能以enable或包安装成功代替一次执行；不能执行源XML包 |
| 取消执行（8，提案） | 有效身份、完整签名、明确execution_id和处置权限；观察安全停止 | 不因使用授权过期、包缺失、新安装关闭而挡取消；不全局停止其他应用 |
| 回滚 | 明确撤回对象和良好恢复目标；有包目标通过成品/审核/兼容检查，本地目标也校验哈希和撤销；获批恢复权限 | 可绕过普通版本降级限制，但不能绕过目标安全检查；无良好旧版走安全停用/卸载 |
| 禁用/卸载/回收 | 真实设备、签名、操作与受控范围；按厂商安全退出 | 不因买断/试用授权过期、构建产物缺失、发布关闭或熔断本身被挡住 |

取消绑定后不继续安装；已有处置意图保留。设备凭据吊销导致无法通信时须受控重新配对或维修，不能恢复共享key作为后门。

单机型L1只适用于指定产物与已验证组合；有来源/profile却缺成品或验证时仅为L2；无可用路径为L3。一次pass-through或一个机型L1不等于自动转码，也不等于项目M4三厂真机完成。

## 9. 平台接口与最小改造

已有接口复用：`/api/agent/enroll`、`register`、`heartbeat`、`commands`、`evt`、`self-update`、`packages/{versionId}`，具体方法和鉴权以现有路由为准。

拟新增内部构建查询/取消/run历史与v2持久接收、事件ACK、对账契约，均**尚未实现**。构建POST异步返回202，不允许调用方写success；扩展evt必须先冻结消息类型、错误码及ACL，不能直接向旧phase塞新值。

优先扩展现有构建协调、资产选择、任务签名和Agent实现。只有在职责无法收敛时再拆服务；不因本蓝图的逻辑分层新增一套重复任务/权限系统。

## 10. 可证伪验收与外部判据

### 10.1 本轮与后续实验分开

§2.2是本轮已执行结果。下列E1—E4为**未执行的整链/重建/真机门槛**，不能打勾；后续修订须补命令、输入摘要、原始输出、执行者和固定代码版本。

| 编号 | 实验与反例 | 通过门槛 |
|---|---|---|
| E1 成品链 | 独立库登记获准载体真实包，审核→发布→建任务→下载；替换资产或变更环境 | 审核、任务锁定、实际下载/安装字节一致；缺包、错hash、错机型与不适用证据全部阻断；manifest校验不是完整交付验收 |
| E2 身份/期限 | 改operation/did/期限/profile，重放已完成/过期任务；吊销后尝试共享入口；试用跨期限再上线及重启/回拨 | 副作用前拒绝；吊销后所有旧认证路径失效；过期安装/启用不执行；处置通路仍可用 |
| E3 恢复 | 在收包、持久接收、厂商调用、激活提交、done发送/ACK各边界中断；abort→commands→Agent→evt真实回滚 | 设备实际版本/字节/健康与平台收敛；任务不丢、事件可重报且副作用幂等；首装无旧版验证安全停用；不能仅数回滚任务 |
| E4 构建/硬件 | 两隔离环境主动重建；只改变工具链/依赖/profile；指定厂商接口SIL/HIL | 应变输入改变构建身份；字节可复现；HIL绑定产物及环境，环境变化不自动继承L1 |

安全负例另覆盖路径穿越、符号链接、解压炸弹、超时/磁盘不足、worker崩溃、权限逃逸、错误固件及SDK。重启、进程终止与断电分开记录，故障注入只能在隔离环境和获批测试设备进行。

### 10.2 第三方依据及证明范围

- [SLSA v1.2 验证规则](https://slsa.dev/spec/v1.2/verifying-artifacts)：核对构建者、输入和产物，不证明运行正确或运动安全。
- [TUF v1.0.36](https://theupdateframework.github.io/specification/v1.0.36/)：用于旧版重放、冻结、混搭及密钥风险检查；不替应用实现安装恢复。借鉴判据不代表已部署TUF或通过认证。
- [Reproducible Builds定义](https://reproducible-builds.org/docs/definition/)：约定输入/环境/指令下逐字节重建；缓存相同不算独立重建。
- [宇树G1 LocoClient，9f4c017](https://github.com/unitreerobotics/unitree_sdk2_python/blob/9f4c017ef05e10138167873c0adc7af29aa45633/unitree_sdk2py/g1/loco/g1_loco_client.py)：可核查高层接口，不能据此假定存在任意BVH资源安装服务；目标固件支持须另确认。
- [unitree_mujoco，021d7bc](https://github.com/unitreerobotics/unitree_mujoco/blob/021d7bc72dca0551eba0dc6b2bc38e9abd74b1be/readme.md)：该版本声明主要支持低层开发，不能直接代替高层安装/启停插件或真机验收。

独立人员/机器可复跑软件实验；厂商接口支持、实际运行和安全交付还须指定版本资料、设备侧原始观测及有范围声明的HIL见证。签名、SBOM或一次HIL均不能单独证明所有场景正确。

## 11. 分阶段退出条件

各阶段为建议顺序，不把外部依赖写成已承诺P0；未审批项不得因本蓝图出现而自动排期。

| 阶段 | 最小交付 | 退出条件 |
|---|---|---|
| Phase 0 决策冻结 | ADR及§15决策记录、首个可取得的厂商成品/接口/设备组合 | 负责人明确批准范围；未批项有阻断边界 |
| Phase 1 契约收口 | 成品映射、完整构建身份、profile、v2签名与跨语言向量、事件ACK/对账、回滚、试用策略、迁移preflight | 契约评审通过；不写生产业务接口；不可将示例或原型记为实现完成 |
| Phase 2 成品导入MVP | 一个真实厂商成品、不可变资产、构建和最终字节审核、待下发任务 | E1通过；不宣称自动动作转码、真机安装或M4 |
| Phase 3 Agent闭环 | 真实参考Agent扩展或新核心、可控插件模拟器、journal/outbox、授权及恢复 | E2/E3在独立库与隔离设备模拟环境通过；用原始设备事实判定，非仅mock数据库状态 |
| Phase 4 单机型HIL | 固定机型/固件/SDK/Agent/插件/产物，厂商允许的安装/启停/退出 | 真实恢复、断网/断电和安全措施证据；只对获批组合标L1，才考虑其生产agent开关 |
| Phase 5 第二品牌行为交付 | 同一标准源、第二品牌binding/profile、目标应用及证据；对应B6 | 同源能力轨迹等价，第二品牌独立HIL；本阶段不依赖动作重定向，不能复用首机证据冒充零改动 |
| Phase 6 动作专项与后续机型 | 受控算法/格式、运行时/策略桥、黄金动作集；其他SKU逐个扩展 | P-01先批准DEV-04范围变更；E4、规定SIL/HIL及适用动作范围通过；不得凭抽样覆盖全部动作 |
| Phase 7 实时接管 | 另立ADR与项目 | 不属于本次批准范围 |

单机型成品导入只能证明对应组合的交付能力。原项目M4（三厂真机）与本蓝图阶段结果分别报告，未经负责人另批不得改写里程碑。

## 12. 运维、回退与审计

记录build/run/key、工具链/profile/成品摘要、审核/验证ID、task/event/设备ID、安装与恢复目标、ACK/对账状态及错误；日志不打印密钥、完整签名URL和私有包内容。监控积压、未知安装结果、回报滞留、回滚/安全停用失败，不能只统计success数量。

回退顺序：停新构建和新安装/启用发布；保留历史成功构建与run；用独立撤销/发布状态标记失效产物；撤销尚未执行的安装；保留可信禁用/卸载/回收及对账通路；必要时回到manual。离线设备等待上线，manual仍不可自动卸载。

回退不得覆盖成功历史或修改原产物；受影响设备必须有处置结果或明确未处理原因。关闭agent“新安装”开关不等于关闭全部Agent命令接口，否则kill switch和恢复也被一起切断。

## 13. 风险与停止条件

| 风险 | 停止条件/应对 |
|---|---|
| 无真实厂商安装接口或有效设备组合 | 停在成品/契约实验，不能伪造生产InstallAdapter |
| 成品类型、签名或授权契约不一致 | 不开放生产agent；先通过E1/E2 |
| 回报丢失或回滚只有标签 | E3未通过前不承诺自动恢复；保留未知状态和人工处置 |
| 动作算法/profile质量未证实 | 未审批不立项，未验证不标L1；范围外动作拒绝 |
| 设备端隔离与厂商通信冲突 | 提交最小权限例外评审及测试，不能直接privileged |
| worker、存储、密钥或撤销策略未就绪 | 生产开关关闭；不得用本机探针替代部署验收 |

## 14. 验收清单（全部未验收）

- [ ] E1：公开载体与外层封装映射获批，审核/构建/任务/下载绑定同一字节。
- [ ] 构建来源、完整工具链/profile摘要和独立重建证据齐全；success与发布/撤销分离。
- [ ] E2：生产device身份强制、旧共享入口关闭，v2签名覆盖完整意图，无协议降级。
- [ ] 试用统一起算及截止规则获批并通过断网、重启、回拨验证；manual无试用。
- [ ] E3：任务持久接收、事件ACK、迟到事实对账及跨故障恢复真实通过。
- [ ] 回滚目标为实际良好产物；无旧版时安全停用/卸载，不报假恢复。
- [ ] 处置操作不被构建缺失、授权过期、熔断或新安装关闭误阻断。
- [ ] E4：指定产物和环境组合HIL、D6留痕及厂商安全退出证据；环境变更重新判断。
- [ ] 单机型L1、项目M4、动作转码能力分别报告；不把SIL/签名/文件生成写成安全可交付。
- [ ] 生产部署、常驻进程、密钥信任根与恢复值守独立签收；文档改完不勾功能完成。

## 15. 单独提交负责人拍板的事项

下表与ADR使用相同编号，**全部待决**。技术建议用于形成可审核方案，不代替批准；通过一项不自动通过其他项。

本轮需求已进一步明确为“一份标准源，多机型生成并执行成品”；§17—§21给出推荐实施栈、受限节点库和B0—B7工单。它不自动立项完整通用SDK/CLI或批准低层运控改造；P-01评审范围与旧裁决差异，普通工程实现按本方案拆任务。

| 编号 | 决策与建议 | 未批准时的边界 |
|---|---|---|
| P-01 | 受限标准行为/能力编译与执行的范围、与完整SDK/CLI未立项的差异；成品封装与DEV-02、受控motion.retarget与DEV-04的责任差异及是否立项 | 既有裁决继续有效；不自动承诺任意代码转译、低层运控或Phase 6动作专项排期 |
| P-02 | 按§15.1的优先候选品牌确定首个真实设备/固件/SDK/厂商安装接口、可取得包和验证场景；批准单机型L1范围并保持原M4三厂口径 | 品牌方向已明确，具体组合仍待批准；只做隔离契约/模拟，不宣称真机或L1 |
| P-03 | 二十分钟试用从云端发放还是首次合法激活起算；断网/时钟异常的安全停用规则、买断离线策略 | 沿用既有D4时长及通道边界，不新增未验证的试用承诺或订阅 |
| P-04 | 推荐外层file+内层载体映射及不可变目标发布快照；确认源/产物角色、审核scope迁移和DEV-02封装边界 | 不改isFile语义，不自动再封装，不用旧上传槽覆盖成品，不绕过已发布版本状态锁 |
| P-05 | 按§20.1推荐Python Agent＋SQLite＋固定BT.CPP runner＋厂商能力子进程；明确实现、SDK接入、HIL及恢复值守责任 | 推荐栈尚未生产验收；不把参考Agent或SDK接受响应当成技能运行完成 |
| P-06 | 构建证明/任务签名是否分钥、启动信任根和轮换撤销；profile及密钥维护责任 | 仅临时密钥隔离实验；生产密钥与profile不得自行生成批准记录 |

### 15.1 用户指定的优先候选品牌（2026-11-03）

目标方向为**主流品牌的热门机型**，优先候选包括宇树、智元、优必选、众擎、小米；“重擎”暂按前文众擎（EngineAI）理解。具体热门程度和接入顺序须结合客户需求、实际可取得设备与开发权限判断，不以公开开发资料替代销量或保有量证据。下表是技术候选入口，不是已适配清单，也不表示全部型号均进入首期承诺。

| 品牌 | 当前可核验的候选入口 | 进入实施前的限制 |
|---|---|---|
| 宇树 Unitree | G1 EDU、R1 EDU；四足评估Go2 EDU，见[官方开发文档中心](https://support.unitree.com/home/en/developer/) | G1/R1普通版不能继承EDU二开；Go2 X为部分开放，须单独核验；锁准确计算单元、固件、SDK与关节配置 |
| 智元 AgiBot | X2 Ultra/旗舰焕新版见[AimDK开发模式](https://x2-aimdk.agibot.com/zh-cn/latest/quick_start/prerequisites.html)；远征A2/A3见[官方文档入口](https://open.agibot.com/docs/home) | X2 EDU须外接开发计算，不能在PC1二开；A2/A3动作资源与全身舞蹈权限分开；不因此接入厂商云 |
| 优必选 UBTECH | 明确候选Walker S2 EDU探索者，见[官方SDK部署指南](https://docs.ubtrobot.com/walker-s2-edu/docs/sdk/5/) | 已有SSH/ROS2容器与自定义程序依据；不能套给所有工业Walker SKU；开发者模式退出内置运控，不当普通技能导入 |
| 众擎 EngineAI | PM01-EDU、T800，见[官方开放平台](https://en.engineai.com.cn/open)及[官方GMR](https://github.com/engineai-robotics/GMR) | T800 Pro独立profile；GMR输出与Native SDK消费者要桥接，运行策略及模式另验，不能承诺任意轨迹直接执行 |
| 小米 Xiaomi | CyberDog 2，见[官方产品说明](https://agreement.cyberdog.xiaomi.com/guide.html) | 该候选为四足；不能据此推定小米人形产品已可接入。用户是否将四足纳入首期、具体二开权限与部署条件仍待明确 |

先选能取得合规成品、开发接口和测试设备的一个组合完成E1—E4，再按同样标准扩展。品牌名单不批准DEV-04范围变更，不改变L1/L2/L3、D6及原M4验收口径；不能把“同一品牌”当作跨型号兼容依据。

## 16. 本次修订交付与回退

本次修订本文件和ADR，并增加docs下的源契约样例与校验记录：纠正基线、补齐可移植编译/执行、恢复机制与开发工单；未修改业务代码、API、迁移、部署开关或真实设备。所有新能力仍为待实现，审批状态仍为Draft/Proposed。

原稿为 `f176d64` 的两份文档；本机备份 `.claude/backup/agent-doc-review-20261103/source-f176d64.zip` 不提交。需要回退时先保留当前文档，再从固定提交分别读取这两个路径恢复，禁止全仓reset或git add -A。

本轮0.3之前的工作区稿另备份在 `.claude/backup/portable-skill-full-plan-20261103/`；可只恢复这两份文档。新样例没有被业务注册或执行，保留不影响业务；需要移除时仅按确切docs样例路径处理，不作全仓清理。

## 17. 可移植技能：一份标准源，多机型成品

### 17.1 产品承诺与第一版边界

本节是本轮补齐的核心开发规格。目标为：**开发者提交一份不可变标准源，平台为获准目标自动完成能力检查、编译、审核和交付；各机型通过共同的行为验收。** 平台维护可复用的机型适配，开发者不必为每个新技能重复上传各品牌实现。

首期支持三条路径，不能混称同一种转码：

| 路径 | 标准源 | 自动化工作 | 完成定义 |
|---|---|---|---|
| P 行为适配（首期主线） | 受限 BehaviorTree.CPP XML4＋标准能力节点＋媒体资产 | 解析、能力匹配、语义降低、生成目标应用 | 同一源摘要生成至少两个不同品牌成品，抽象行为轨迹与源契约一致 |
| V 厂商成品导入 | 开发者已有且有权分发的合规厂商包 | 原样核验、目标封装/元数据、审核、交付 | 真实目标安装及恢复；不计为几何动作转码 |
| M 动作重定向（独立专项） | 自有/获授权BVH，后续再增其他来源 | 骨架/时序规范化、重定向、目标导出、运行时/策略绑定 | 转换产物进入获准执行链，完成规定静态、SIL与HIL，不仅生成文件 |

任意 Python/C++ 程序自动跨厂商翻译、任意厂商包互转、四足与人形全动作等价不在首期承诺。对不满足能力的机型准确拒绝，是产品正确结果；不能为了“全兼容”改变原技能含义。

**第一验收场景：**选定G1 EDU与智元一个可取得SDK/样机的SKU，用同一份标准源构建两个品牌成品；先采用双方均能被独立观测的已获准高层动作/媒体能力。建议迎宾作为业务场景，但不预设某个SDK已支持精确挥手次数、音频完成检测或所有源参数。

### 17.2 源格式、标准节点与示例

外层继续既有 `.skill` ZIP和 `zhaohuan.skill/v2`，不新增 `.rsp` 或另一套公开包格式。内部采用已有 **BehaviorTree.CPP XML4** 的受限配置；新增的是能力语义与校验profile，不能宣称“现有manifest形状能接受便已实现可移植”。

```text
source.skill（开发者自己提交完整合规包）
  manifest.json
  behavior/main.xml
  assets/welcome.wav
  licenses/...
```

建议源 manifest 的 runtime 子对象：

```json
{
  "source_format": "btcpp/xml4",
  "source_entrypoint": "behavior/main.xml",
  "capability_contract": "portable-actions/v1"
}
```

source_entrypoint 不是可执行入口；源清单不以 runtime.entrypoint 把XML伪装成程序。目标成品另写实际runner入口、源摘要和能力/绑定/runtime摘要。manifest kind 使用现有composite，权限沿用现有 motion_control/audio_play/expression 等词表。

[清单样例](./portable-skill-spec/examples/greeting/manifest.json)与[行为树样例](./portable-skill-spec/examples/greeting/behavior/main.xml)用于契约评审，尚缺真实音频文件、目标binding和真机证据，**不是可直接上架的完整技能包**。精确三次挥手是严格要求：目标只能调用一次预置挥手、却不能证明侧别/次数时，必须拒绝该样例；不得悄悄改成“一次动作调用”。

第一版节点白名单为 Sequence、注册Action及有界SM_Wait；不支持任意脚本、XML外部实体、include、动态库加载、动态ROS接口名、无限循环、Parallel、自动重试或未约束Blackboard表达式。以后增加控制节点须有独立取消/重启语义测试。解析器禁DTD/实体展开，资源路径必须包内相对路径，深度、节点数、属性长度和总执行预算均有限制。

### 17.3 标准能力契约 v1（提案）

| 能力／节点 | 输入与权限 | 可观察成功条件 | 不可替代的限制 |
|---|---|---|---|
| gesture.wave@1／SM_GestureWave | hand枚举、正整数cycles、有界timeout；motion_control | 指定侧别、计数和完成/释放状态满足已审定义 | 无手臂、次数不受支持、只有SDK接受响应而无完成依据则拒绝；不替换为鞠躬或抬腿 |
| audio.play@1／SM_AudioPlay | 包内音频hash、wait、有界timeout；audio_play | 目标编码存在，按profile的可靠完成条件结束 | 不把发出播放请求等同于播完；停止仅作用本执行流，不停止其他应用 |
| expression.show@1／SM_ExpressionShow | 包内媒体/表情引用、时长；expression | 显示设备及目标格式满足合同并完成 | 无显示能力不得静默改成语音/灯效 |
| wait.duration@1／SM_Wait | 有界毫秒值；无新增设备权限 | 单调时间预算完成 | 可立即取消；重启不能重赠完整等待时间 |
| motion.play@1／SM_MotionPlay | 获准目标动作、runtime/policy/profile摘要；motion_control | 正确消费实际产物、完成且安全退出 | M专项接入后开放；没有获准运行时不能直发关节流 |

每项能力还有公共字段：契约版本、参数类型/单位/边界、权限、效果类别、资源占用、开始前提、完成观测、取消方式、最大持续时间、恢复策略和证据ID。阈值必须有目标资料或试验来源，缺失不能猜测。

默认 strict。可替代行为必须由开发者显式声明、由产品确认等价范围，并形成独立lowering/审核记录。第一个版本建议不开放自动降级。平台资源锁只协调本平台的执行，不承担全机器人关节实时仲裁，也不能保证约束其他厂商进程。

### 17.4 编译器与IR

```text
PackageInspector/ManifestValidator
-> PortableSourceValidator（XML/资产/权限/预算）
-> SourceParser（不可变语法树）
-> CapabilityResolver（型号+SDK+能力版本+参数支持）
-> SemanticLowerer（标准能力到已审厂商binding）
-> TypedPlanIR（无任意代码、无shell命令）
-> TargetAppBuilder（XML/资源/绑定/固定runner）
-> 目标语义模拟与产物检查 -> skill_assets -> 目标审核/发布
```

IR至少记录source_node_id、能力ID/版本、类型化参数、源媒体hash、目标媒体hash、权限、时间预算、资源占用、binding摘要、失败/取消/恢复策略。与执行有关的值不可从未签名运行参数任意覆盖。

推荐实际采用BehaviorTree.CPP runner，而非自制一个宣称兼容所有BT语义的解释器。XML格式固定4，库版本单独锁定；可先验证官方4.9.0候选tag及目标ABI，再固定确切commit、依赖和镜像摘要。Agent沿用Python基础负责监督，不要求与C++ runner同语言。构建机可以Python3.10工具链，目标解释器/编译器/ABI必须逐profile固定，不能假定所有机型预装同一Python或Ubuntu。

现有SkillBuildService只按kind选择第一个builder，MotionCarrierBuilder也接受composite；必须改成 `kind + source_format + builder_key` 明确选择，不得让portable builder吞掉旧composite，也不得让未知格式回退pass-through。保留一个 `config/skill_builders.php` 注册表。

**新增可移植构建身份：**在§4.3基础上加入能力契约/节点库、IR版本、lowering规则、binding、runner/插件、媒体编码器及其配置摘要。同源版本不同target各有build_key；同目标语义或SDK变化须重新判断构建和验证，不能只靠skill version号缓存。

### 17.5 机型能力绑定与首批顺序

| 目标 | 第一版可采用的绑定依据 | 必须补的具体实验 |
|---|---|---|
| G1 EDU，明确DoF/PC2 | 官方动作列表、按ID预置动作或按名示教动作、AudioClient播放/停止 | 枚举真实资源；验证侧别/次数/完成/取消与音频格式；不把未公开的示教导入协议补成猜测 |
| X2 Ultra／旗舰焕新版 | 开发计算单元容器，已部署灵创资源查询/播放及音频能力 | 核验资源ID/权限、播放结束/安全取消及跨固件留存；X2 EDU需外接开发计算，不能安装PC1 |
| A2旗舰／A3 Ultra（可替换第二目标） | 本地上肢动作资源管理/迁入迁出/播放 | 先做原厂资源往返及恢复，再评估新动作内容协议；全身舞蹈“技能”不可据此创建/迁移 |
| PM01-EDU | 应用侧程序、原厂已有能力；M专项另接官方跟踪runtime | 确认高层完成/取消；涉及替换运控服务的Runner不纳入普通应用插件权限 |
| Walker S2 EDU | ROS2容器自定义程序和官方预置动作库 | 实际观察普通模式下动作与取消；developer_mode会退出内置运控，不据此证明普通动作包可用 |
| T800/Pro、R1 EDU | 各自准确SKU/SDK及样机 | T800与Pro独立profile；R1不继承Galaxea R1 Pro的映射 |
| Go2 EDU、CyberDog2 | 独立四足应用/原厂能力分支 | 不继承人形动作等价结论；小米按具体二开权限与功能条款核验 |

建议交付顺序：G1 EDU → 智元一款（应用优先X2 Ultra；动作成品导入优先A2/A3）→ PM01 → Walker S2 EDU；第二阶段按真实需求扩展其他SKU。动作研究先选PM01和G1；T800随后，Pro单独验证。顺序以实际可取得的样机/SDK调整，不按未经核验的销量或采购成本排序。

### 17.6 执行运行时：安装之后如何真正使用

enable只表示允许调用，不自动触发实体动作。安装、启用和一次技能执行必须区分；否则平台会再次停留在“包已落盘”而没有技能使用入口。

拟新增 `skill_execution` 记录业务执行事实，复用deploy_task运输和Agent事件，不再另造设备消息队列。**操作码提案**：OP_EXECUTE=7、OP_CANCEL_EXECUTION=8；现有1—6不改值，新增值须在实现批次纳入唯一OPERATIONS、错误码与契约快照。旧Agent禁止接收新码。

v2 signed使用§4.5唯一字段表，绑定execution_id、target_release_id、实际installed_asset_id/sha256、plan_digest、binding/runtime摘要、类型化参数值及摘要、execution_deadline和所需资源；取消任务另带被取消execution_id。所有默认值在签名前规范化；客户不能指定SDK方法、资产绝对路径、topic/service或shell片段。普通用户只可运行自己有有效授权、已安装且启用的目标release。

首个portable-actions/v1交付固定参数技能，execution_params必须为空对象；示例的侧别、次数和音频不能由客户调用时覆盖。后续参数化版本必须先定义源声明、类型、允许值与上下界、语义/权限预算及签名规范，再扩展白名单，不能以“已类型化”代替对允许参数的审查。

执行/取消不重新下载包：package_url等取包字段为null，已安装成品身份用上述installed字段校验；v2按operation明确分支，不能沿用参考Agent“有hash就下载”的判定。后台与设备的取包操作白名单同步，但保持旧1—6含义不变。

执行幂等键为execution_id；客户nonce只合并同一次请求重试，不吞掉不同nonce发起的下一次合法执行。取消按被取消execution_id去重。现有findOpenTask的did/version/operation去重仅用于原生命周期操作，不得直接用于7/8；数据库唯一约束及并发测试必须覆盖同技能两次执行与重复取消。

```text
创建执行（HTTP 202） -> 设备持久接收 -> 再核授权/期限/环境
-> 获取平台资源租约 -> 独立runner执行
-> 能力broker校验并调用厂商高层接口
-> 观察节点结果 -> 持久事件/ACK -> execution最终结果
```

runner仅能调用白名单能力。推荐通过受限本地IPC连接厂商插件，设置独立UID/容器、最小挂载和连接身份；插件不把Agent凭据或完整SDK对象交给技能。CPU/内存/运行时长受限；未经批准的宿主网络、Docker socket、设备节点或privileged均禁止。

执行状态：ACCEPTED/RUNNING/CANCEL_REQUESTED/SUCCEEDED/FAILED/CANCELED/UNKNOWN。RPC接受、BT节点halt或ROS取消接受都不等于实体动作已停止；在独立observe确认安全终态前保留资源锁，禁止下一次冲突动作；失败时转UNKNOWN/人工处置，不以超时自动解锁继续运动。

取消只影响明确execution_id，不发送全局“停止全部ROS goals”。禁用、卸载、回收先持久冻结新执行准入并提升该技能/设备的撤销代次，再取消活跃执行、观察安全退出，最后释放资源和删除文件；旧排队7指令或旧journal不能在此后重新激活。授权策略的revocation_epoch与设备持久代次一起检查，恢复需要新明确授权。禁止先rmtree或强杀厂商运控。Agent重启时不盲重放有副作用的节点：能够查询原goal结果则恢复观察；不能确认则UNKNOWN并安全退出。来源契约允许且可证明幂等的等待/纯计算才可继续。

DeployTaskService记录安装事实时必须改为显式操作白名单，不能让新增7/8落入当前default安装分支；执行成功不新增install_record。取消和事实补报不被使用授权过期挡住，仍要设备身份、签名、作用域与去重。

### 17.7 语义等价验收

至少两个品牌使用同一source_sha256；目标成品hash可以不同，但抽象观测必须满足同一合同，例如：

```text
gesture.wave(right,3)开始 -> 指定次数并安全完成
-> audio.play(源音频摘要)开始 -> 按合同完成
-> execution SUCCEEDED
```

逐项核验顺序、参数、计数、媒体内容、取消和完成条件；不要求不同机器人关节角完全相同。若源音频经过编码，验证来源到目标媒体的确定性转换与音频内容，而不是要求不同编码文件hash相等。

正向金标至少包括双方实际支持的两个组合技能；拒绝金标包括无右臂、无屏、次数不支持、仅有接受ACK无完成观测、权限声明不足和不支持的源节点。适配失败必须逐目标给原因，其他目标可继续构建；不得把部分成功呈现成全机型支持。

## 18. 动作转码与真机执行桥开发规格

### 18.1 实际格式与禁止混用的地方

以下来自2026-11-03公开作者/厂商源码；main/master未冻结为生产版本，实施时必须记录确切commit并保存golden输入输出。

| 环节 | 已取得的格式事实 | 工程处理 |
|---|---|---|
| GMR BVH输入 | loader含cm→m和坐标旋转，依赖具体骨骼命名；部分路径把身高写为1.75；CLI默认fps不等于源时间轴重采样 | SourceProbe独立保存Frame Time、骨架和源标定；不能对任意BVH直接调用默认参数 |
| GMR PKL输出 | fps、root_pos[N,3]、root_rot[N,4]保存为xyzw、dof_pos[N,D] | 可信worker产物转换为纯数值MotionIR；不能把PKL当厂商技能包或加载外部任意pickle |
| Native SDK named-joint CSV | 按active_joint_names读取表头，csv_dt与runner_period参与插值 | 独立named-joint exporter；锁关节名、单位、目标采样周期及loader版本 |
| Native SDK NPY | 当前loader约束NPY v1、2D和34/36/40列，包含固定25关节布局 | 不直接套PM01 24DoF或Pro；未证实布局前拒绝，首桥优先显式CSV映射 |
| RL-Lab CSV | 无表头[root_pos3,root_quat_xyzw4,joints...] | 与上一种CSV使用不同exporter和格式ID；禁止同扩展名自动猜解析器 |
| RL-Lab NPZ / SDK dance | joint_pos、joint_vel、body_quat_w为wxyz；body可能是[N,B,4]，消费者取指定body | 明确float32、数组维数/shape、body索引、joint/observation顺序、policy摘要；不能只改字段名 |
| 智元动作MCAP | 文档以record_file_path创建资源，输出示例mcap，部分注释拼macp；内部消息协议公开信息不足 | MCAP仅容器；取得获授权样本检查channel/schema/encoding/时间戳/关节/压缩，禁止把任意MCAP当可播放动作 |

### 18.2 规范化MotionIR

平台内部数值表示采用SI单位：位置m、角度rad、时间s；明确右手坐标系、world/base语义、关节名序和源到目标变换。四元数内部固定xyzw，边界转换为wxyz时有显式版本化规则，禁止靠数组位置猜含义。

建议用JSON元数据＋非object数值数组，不引入新的公开包后缀。元数据包含时间戳/源fps、模型摘要、joint_names、dtype、shape、坐标/单位、root/contact策略和来源许可。禁止NaN/Inf、重复/逆序时间、未知关节、维度错配；NPZ读取关闭pickle，限制压缩比、总字节及数组数量。

源关节轴向/零点/身体尺度是可验证输入；缺失时报 SOURCE_SKELETON_UNSUPPORTED / SOURCE_TIMING_UNKNOWN / TARGET_JOINT_MAPPING_MISSING。规范化与重采样要保留总时长及首尾帧，位置插值、旋转SLERP、导数算法、接触约束和容差分别锁版本。

### 18.3 转换、质量与执行绑定

```text
SourceProbe -> 单位/坐标/时间/骨架规范化
-> 固定GMR与IK/model -> MotionIR
-> TargetExporter -> RuntimeBinding
-> 静态检查 -> 运动学预览 -> 动力学SIL
-> 指定范围HIL -> 目标成品审核发布
```

检查目标关节位置/速度/加速度、时间连续性、自碰撞/足接触、初末姿态及过渡。限位裁剪不是“安全修复”；影响动作语义或动力学时须拒绝或形成新的已审变体。容差来自目标profile，不能编造跨全部机型通用的安全阈值。

众擎官方公开链为：目标CSV → csv_to_npz → whole-body tracking训练/评估 → policy.mnn → NPZ/MNN/配置 → Native SDK。当前文档固定Isaac Lab 2.3.2的 `c22775241e28f465fe345fa1a482ad6d29d712b0`；不能直接随latest升级。csv_to_npz默认30→50fps且做插值、导数与模型FK；设置仿真状态回放不等于动力学稳定性通过。

**策略不是免费附带：**未证明通用tracker可覆盖新动作前，把每动作所需训练/策略适配作为独立构建子任务、预算和证据；不得宣称所有BVH几秒内生成可上架技能。策略、模型、observation/action顺序和动作轨迹共同锁进成品及HIL范围。

运行时桥只接获准厂商高层资源或另经审批的厂商跟踪runtime。涉及停止robotics.service、开发者模式退出内置运控、替换运动策略等，必须独立范围审批及实机验证，不能由普通Agent安装插件默认执行；需要平台自研实时控制器则停止在该边界并另立项。

### 18.4 五个必须完成的取数/验证任务

| 编号 | 执行者 | 具体输入与实验 | 退出条件／失败后的能力边界 |
|---|---|---|---|
| R1 源动作金标 | 动作工程＋QA | 自采/获授权的静立、慢抬臂/复位、左右挥手；同动作30/60fps、m/cm、四元数/关节错序夹具 | 时长、首尾、单位/坐标转换有机器比对；不使用无商用权研究数据替代正式素材 |
| R2 G1新动作入口 | 设备工程＋厂商支持 | SDK对应版本动作列表/停止，取得示教资源写入说明或获准tracker；完成一个新动作写入/执行/取消/恢复 | 成功才开放G1新动作转码；取不到则保留既有动作语义映射，不伪造导入接口 |
| R3 智元内容协议 | 设备工程＋资源提供方 | 一个有权使用的录制/导出资源；mcap元数据探针；原样迁入→查询→播放→删除/恢复 | 原样往返且内容协议可解释；未获schema时仅原厂成品迁移/既有资源调用 |
| R4 PM01执行桥 | 动作工程＋设备工程 | 锁GMR/SDK/RL-Lab、模型/关节表、示例轨迹与MNN；两类CSV分别导出，官方消费者/SIL读取比对 | shape/关节/时间/四元数与IR一致，策略适用范围有证据；不猜固定25关节布局 |
| R5 运行时和硬件 | 厂商适配负责人＋QA | 模式切换约束、策略可用范围、急停/安全退出、断网/断电、旧策略与旧动作恢复 | 形成原始观测和责任签收；未通过则只标实验/SIL，不开放L1 |

R1/R4的公开材料已足以启动离线验证设计；R2/R3/R5仍缺有权取得的实物/私有协议/设备数据，公开资料未给出时不能靠更多文字填成事实。负责人应把厂商请求包列为型号、固件、SDK、所需接口、样本、许可、实验与停止条件；无外部数据仍可完成不依赖它的编译器和模拟测试。

### 18.5 动作负例矩阵

2/3/N帧、空/单帧、首尾帧丢失；30/60fps同动作；m/cm；xyzw/wxyz；左右关节交换；缺骨骼、身高/零点错误；NaN/Inf、越限、重复时间；CSV两格式混用；错误MNN/observation顺序；wrong runtime/profile；初末姿态不匹配；取消后仍运动。每项有固定错误与原始输出，不以视频看起来正常替代数值与状态验收。

## 19. 仓库改造与数据、发布、API闭环

### 19.1 必须先解决的存储与发布矛盾

当前AssetUploadService按 `(skill_version_id, carrier_type, sort_order)` 覆盖并删除旧文件，数据库有同槽唯一索引；published版本也禁止上传改包。PackageFingerprint对该版本file集合取指纹。若把一个源包和多个目标产物直接塞原槽，会覆盖文件、改变旧审核或被状态锁拒绝。

**本方案推荐选择：不可变源版本＋不可变目标发布快照。** 同源后续增加机型，只新增目标构建与目标成品审核/发布，不重写已发布目标。代价是下述角色和审核scope迁移；若首版不采用此迁移，只能明确退化为“所有目标一次构建发布，增加目标必须新版本”，不能同时宣称发布后无限追加目标。

| 数据对象（提案） | 字段／规则 |
|---|---|
| skill_assets增量 | asset_role=legacy/source/artifact、asset_key、immutable_at；旧行只回填legacy，源和产物不进旧上传槽；按摘要寻址且不覆盖已审核字节 |
| skill_build增量 | build_key唯一、source_asset_id、source/manifest/IR/profile/toolchain/binding/runtime摘要、source_format、last_run_id、完整output hash |
| skill_build_run新增 | attempt、lease_generation、worker、状态、输入/输出、检查与日志、started/finished；迟到失效worker不能覆盖新run |
| skill_target_release新增 | source version、target/profile、build/run、artifact ID/hash、review scope、验证范围、授权适用性；内容快照不可变，发布/撤销转换另留审计 |
| audit_record扩展 | scope=legacy/source/target及snapshot引用；复用审核，不另建独立审批系统；最终成品审核不能仅继承源审核 |
| deploy_task扩展 | target_release_id、签名版本/序号/期限、授权策略、恢复目标；执行任务增加execution_id/plan摘要；保持现有六操作兼容 |
| install_record扩展 | actual_asset_id/hash、runtime/profile摘要、observed_at；归档旧版不等于撤销，明确良好恢复快照 |
| skill_execution / event新增 | execution ID、task/release/entitlement/did、状态、参数摘要、期限、实际节点结果；事件ID唯一和append-only事实对账 |
| Agent本地SQLite | tasks、checkpoints、installed、outbox、revocations、execution/resource leases；事务/掉电边界与恢复逐项测试 |

源包不能出现在付费交付列表或Agent取包接口；客户买到的是授权对应的目标成品。C端SkillController目前已有isFileBacked列表/下载，改造须逐入口加角色与release检查，不能继续笼统说“所有下载只认file”。Agent解析/指纹/取包路径仍有isFile约束，外层file方案不等于可省略源隔离。

source_format及其契约摘要与源版本一起不可变，用于选择新审核/发布路径，禁止portable版本误走legacy全版本指纹。source/artifact角色由可信服务赋值，不能由客户端mass assignment任意声明；生成成品仅允许构建控制面追加，开发者上传API不能借artifact角色绕过published版本保护。

### 19.2 指纹与发布迁移

保留现有legacy版本指纹，不原地改算法使历史审核全部失效。新增source指纹与target-release指纹，目标指纹覆盖源、IR、能力、binding、runtime、最终包与适用验证范围。源审核可判声明/许可，目标审核必须检查真实成品；一个目标撤销不自动抹掉所有目标，源安全问题则可按影响范围撤销全部关联成品。

SkillPublisher发布新版本会归档旧版本；回滚目标只要是明确获准、未撤销、兼容且有恢复权限的历史release，不应仅因parent version已归档就拒绝。正常新安装仍遵守现行销售/发布/授权范围；目标快照不是绕过旧授权购买版本限制的入口。

迁移顺序：只读preflight → additive字段与新表 → role-aware读写双兼容 → 对旧行回填稳定asset_key/legacy → 核对引用/计数/摘要 → 切新source/artifact writer及scope → 最后替换旧槽位唯一规则。不得先删索引，再让旧upsert面对多个目标成品。回退保留新历史，旧客户端只读取legacy允许范围；不把source重新暴露为旧下载兜底。

### 19.3 精确代码改造地图

以下相对 `my-bagisto-store/`，均为将来实现任务：

| 真实入口 | 改造内容 |
|---|---|
| Shop `Services/SkillPackage/SkillManifest.php`、`ManifestValidator.php`、`PackageInspector.php` | 固定runtime source profile、调用PortableSourceValidator；现有脚本扫描不能代替沙箱；未知格式拒绝 |
| Shop `Services/SkillCompile/SkillBuildService.php`、`CarrierBuilder.php`、`BuildResult.php`、config/skill_builders.php | 异步协调、源格式选择、BuildContext、独占run；success必须有真实产物和证据，不接受success(null) |
| 新 Shop `Services/SkillCompile/Portable/` | SourceParser、CapabilityResolver、SemanticLowerer、TargetAppBuilder、SemanticTraceVerifier |
| Shop `Services/SkillSupply/AssetUploadService.php`、Models/SkillAsset.php | 源角色和不可变ArtifactWriter；替换旧覆盖槽的写法，不让源覆盖成品 |
| Shop `Services/SkillPackage/PackageFingerprint.php`、`Services/SkillAudit/AuditService.php`、SkillPublisher.php、ReviewGate.php | 版本化scope及target release；源审核和目标成品审核分开 |
| Shop `Services/SkillDelivery/SkillDeliveryAssetResolver.php`、CompatibilityService.php | 强制精确target release与环境；portable分支禁止fallback到源或唯一file；逐入口验证asset_id不能绕过 |
| Shop `Services/Deploy/DeployTaskService.php`、API/AgentController.php、AgentPackageController.php | v2签名、领取复核、ACK/对账、明确恢复目标；7/8不能误落安装记录 |
| Shop `Http/Controllers/AgentSetupController.php` | 将内嵌参考Agent提取为可独立测试/发布的代码包，控制器保留分发职责；实现SQLite、插件监督与runner |
| Shop `Services/SkillDelivery/TrialService.php`、`Services/Deploy/DeviceCredentialService.php`、VerifyAgentKey中间件 | 统一期限、强device身份和吊销；复用授权，不新建另一套购买/试用系统 |
| config/skill_delivery.php、config/queue.php、bootstrap/app.php | 复用私盘与database队列；专用worker、租约、资源/存储告警；不把Redis/COS设成无依据前提 |

### 19.4 接口、权限与三端交互

| 接口（已有／提案） | 角色与行为 |
|---|---|
| 已有 POST /api/skill-supply/versions/{versionId}/compile | internal.key；拟改异步202＋逐目标build ID/拒绝原因，调用者不能写success或可信hash |
| 已有 GET /api/skill-supply/versions/{versionId}/builds | 返回源/目标/构建/审核/发布/验证分开状态，不合成一个“成功” |
| 提案开发者版本preflight/build接口 | 仅自己的版本，共享DeveloperSkillService归属检查，不泄漏internal.key；具体路径在B0冻结 |
| 提案build run详情/取消、target release提审/发布/撤销 | 开发者/审核员/发布员分权，不能由运营手填构建或HIL成功；重复请求幂等 |
| 提案 POST /api/skills/entitlements/{id}/executions | customer；绑定本人设备与获准release，返回202＋execution ID；客户作用域nonce去重，同nonce改参数返回冲突；执行不新增收费/订阅，重试不重复动作 |
| 提案 GET /api/skills/executions/{id} | customer本人作用域；返回实际节点、成功/失败/取消/未知，脱敏日志 |
| 提案 POST /api/skills/executions/{id}/cancel | 仅有处置权的调用者；返回取消已受理，不伪称物理停止 |
| 已有 /api/agent/commands、evt、packages | v2扩展身份/执行/ACK/对账；未知新码不得发给旧Agent；禁止任意asset URL |

这些新增路径未实现，必须与路由、ACL、错误码、OpenAPI及 `utils/api.js` 同批更新，不能在界面提前显示已可用按钮。

开发者门户沿用 `Resources/views/dev/skills/show.blade.php`、edit页面：上传标准源、验证节点/资产/权限、选择目标、查看逐目标支持原因、构建日志与审核状态；不要求每个技能重传N份品牌包。

Admin沿用 `Admin/.../Catalog/SkillReviewController.php` 与 `catalog/skill-reviews/show.blade.php`：并排显示源、能力变化、lowering、最终成品及环境证据；敏感profile/关节限位变更走代码评审，运营不能随手调安全参数。

客户沿用 `pages/mine/skills.vue`、devices.vue、agent-setup.vue和唯一API入口：选绑定设备、看精确支持范围、安装/启用、启动一次执行、取消、查看未知状态与恢复结果。提供加载/异常/无适配成品状态，明确manual的人工限制。价格、买断、退款和真实支付仍依赖既有交易体系，本方案不把mock支付改称真实支付完成。

## 20. 可直接拆工单的开发批次、资源与验收

### 20.1 推荐实施栈

- 平台：现有Laravel/Bagisto模块化单体、MySQL、database队列、私有持久盘；异步构建在受控worker中，注册表唯一。
- 编译：Python隔离工具进程负责解析/媒体/动作工具编排；固定GMR/编码器/模型环境。没有新的常驻业务微服务要求。
- 设备：基于现有Python Agent提取生产包，SQLite journal/outbox，systemd或厂商允许的应用监督方式；目标准确版本、UID/容器/ABI由profile固定。
- 行为执行：固定BehaviorTree.CPP runner＋受控能力IPC＋厂商子进程；禁止技能任意发SDK命令。代码依赖许可、target ABI和跨语言黄金向量在B0实测后锁定。
- 动作计算：CPU/GPU分队列；仅当所选策略训练确需GPU时引入独立构建资源，不把GPU训练放到设备或Web请求。

### 20.2 主线批次与有效工程人日

估算为方案级范围，**不是已测性能或日历承诺**。前提：现代码可启动，两位熟悉项目的平台/设备工程师，兼职前端与QA，能取得两个目标的SDK、合规成品与测试设备。人日可并行，不等于人数乘固定周数；外部采购、权限、商业许可、厂商响应及安全签收等待不计入。

| 批次 | 具体工作与产出 | 退出条件 | 估算人日 |
|---|---|---|---:|
| B0 契约和数据锁定 | 源profile、能力节点/IR、完成/取消语义、样例、target profile、asset角色、发布scope、签名/执行码、迁移preflight | 审查能判断“支持/拒绝/未知”；明确旧裁决差异；样例不是生产能力证明 | 5–8 |
| B1 资产与构建底座 | additive迁移、不可变writer、build/run、幂等、租约、隔离worker、指纹scope | 同源多目标不覆盖；并发只一有效输出；失效worker和缓存反例通过 | 8–12 |
| B2 可移植编译器 | XML白名单→能力预检→IR→两个模拟target→真实runner构建 | 同源抽象轨迹一致，缺能力拒绝，恶意源拒绝；不用伪造vendor调用通过验收 | 10–16 |
| B3 Agent与执行可靠性 | 提取参考Agent、device/v2、SQLite、插件监督、7/8执行取消、补报/对账、安全退出 | E2/E3故障注入通过，执行和安装事实不混淆，未知副作用不重放 | 12–20 |
| B4 审核/三端接线 | target release审核发布、ACL、开发者构建、Admin证据、客户安装/执行/取消 | 源包不可下载执行；一目标撤销可追踪；旧版legacy兼容和越权测试通过 | 8–12 |
| B5 第一机型 | G1 EDU或实际可取得的首机型，固定高层节点/媒体，真实安装/取消/恢复 | 规定场景HIL及独立观测；不替换厂商运控，不用状态标签验收 | 8–15 |
| B6 第二品牌 | 智元一个明确SKU；复用同一源和节点契约，新增目标binding | 两品牌source hash相同、能力轨迹等价；不支持样例有明确拒绝 | 8–15 |
| B7 上线与运维 | 全链路、迁移/回退、备份、队列、密钥与值守；默认关闭、小范围放量 | E1—E3实际整链、部署签收、处置通路验证，回滚证据齐全 | 6–10 |

主线合计约 **65–108有效工程人日**，目标是“两品牌标准行为编排＋可信交付＋执行/恢复”。不得把这个数字用于承诺五品牌全部机型或任意全身动作转码。

B1与B2接口冻结后可并行；B3可并行于编译器；B4依赖发布scope；B5/B6只有拿到指定设备条件才执行。依赖外部条件的任务保持条件触发，不塞进当前P0固定日期。

### 20.3 动作专项与后续品牌

一个固定目标、已有获准runtime/策略、素材权利齐全时：源探针/profile 2–4人日；规范化/金标4–6；GMR封装/证据3–5；一个exporter/消费者桥4–7；离线/SIL检查4–7，合计 **17–29动作/适配工程人日**。与主线共享基础设施的部分不得重复计费；该估算不含按动作训练、GPU计算和HIL外部等待。

先用一个有权使用的短动作测出训练/推理/构建耗时、CPU/GPU内存、策略可复用范围、失败率，再给容量和成本。未做测量前不报每技能几秒、每小时处理量或训练费用。

第二轮品牌在B6模式上逐个增加PM01、Walker S2 EDU，再按实际需求扩展R1/T800/Pro；每个目标先交付profile和2–3个能力金标，再承诺更多类别，不把一个品牌SDK存在当全部机型已支持。

### 20.4 测试、发布与回退清单

| 测试层 | 必须覆盖 | 证据 |
|---|---|---|
| 单元/契约 | 节点参数/权限/预算、源与产物角色、错误码、格式选择、target能力、指纹/构建身份 | 机器输出＋固定夹具，源语义validator不能仅复用manifest形状结果 |
| 并发与隔离 | 同key并发、worker中断/租约代次、两目标不互盖、源与成品不混 | 独立测试库，多进程/存储故障；禁止使用root |
| 语义与模拟 | 两target顺序/参数/计数等价，取消接受但未停止，超时后仍运动 | runner事件轨迹＋独立模拟器观测；不把SDK mock当真机证据 |
| 设备故障 | 收到任务/持久接收/外部调用/提交/done/ACK每边界掉线重启；过期/吊销/撤销覆盖旧journal | 安装与execution实际事实对账；超时不抹历史 |
| 动作格式 | 帧/时长、单位、四元数、关节顺序、CSV两格式、MNN/obs不匹配 | 数值golden、格式消费者回读、静态与动力学SIL分开 |
| HIL | 固定SKU/固件/SDK/profile/runtime/动作/场景，取消安全退出、恢复、首装无旧版、断电 | 原始日志、设备摘要、实际观察、执行者和证据范围；一次抽样不覆盖全部后续动作 |
| 发布/回退 | 阶段开关、源码不泄漏、旧版manual/legacy、归档良好版本恢复、租约/密钥变化 | preflight、迁移expand/backfill/switch/recovery、值守签收 |

拟议开关按构建、portable交付、execution、新动作分别隔离，默认关闭；具体名称在B0进入现有配置与契约门禁。先内部账号和测试设备、再单目标小范围、最后按证据扩展。撤回时只停新构建/安装/执行，保留ACK/对账/取消/禁用/卸载/回收。禁止drop历史、覆盖success或关闭全部Agent入口。

角色分工：平台工程负责资产/构建/审核/授权；设备工程负责Agent/插件/observe；动作工程负责转换/策略/模型/质量；QA独立复现故障与HIL证据；运维负责worker、持久存储、信任根、备份与值守；产品负责人批准业务范围和语义替代，不替代技术验收。

## 21. 调研依据、证据边界与本次交付状态

### 21.1 原始资料索引

| 资料 | 用于哪项决策／已取得的事实 |
|---|---|
| [BehaviorTree.CPP XML4](https://www.behaviortree.dev/docs/learn-the-basics/xml_format/)、[异步动作](https://www.behaviortree.dev/docs/tutorial-basics/tutorial_04_sequence/)、[4.9.0候选发布](https://github.com/BehaviorTree/BehaviorTree.CPP/releases/tag/4.9.0) | 采用已有XML与实际runner；halt和完成是需要适配的异步语义；具体tag/ABI尚未编译验证 |
| [ROS2 Actions原始设计](https://raw.githubusercontent.com/ros2/design/gh-pages/articles/actions.md) | goal/result/feedback/cancel分离，取消接受不等于完成；只借鉴协议，不强迫所有厂商改ROS2 |
| [G1动作接口](https://github.com/unitreerobotics/unitree_sdk2/blob/main/include/unitree/robot/g1/arm/g1_arm_action_client.hpp)、[AudioClient](https://github.com/unitreerobotics/unitree_sdk2_python/blob/master/unitree_sdk2py/g1/audio/g1_audio_client.py) | 已登记动作执行/查询/停止、音频流播放/停止；不能推定任意动作导入或播放完成语义 |
| [AimDK X2开发模式](https://x2-aimdk.agibot.com/zh-cn/latest/quick_start/prerequisites.html)、[A2资源](https://open.agibot.com/docs/aimdk/a2/v1_3/dev_guide/07-13-resource_manager)、[A3资源](https://open.agibot.com/docs/aimdk/a3-ultra/v3_3/dev_guide/07-05-resource_manager) | 机载/外接计算区分，上肢动作资源与全身舞蹈技能权限区分，MCAP内容协议仍有缺口 |
| [GMR BVH导出](https://github.com/YanjieZe/GMR/blob/master/scripts/bvh_to_robot.py)、[BVH loader](https://github.com/YanjieZe/GMR/blob/master/general_motion_retargeting/utils/lafan1.py) | 输入骨架/单位/时间不能猜；输出xyzw/关节参考不是可执行厂商包 |
| [众擎GMR](https://github.com/engineai-robotics/GMR)、[CSV loader](https://github.com/engineai-robotics/engineai_robotics_native_sdk/blob/main/src/runner/rl_mimic_trajectory_example/src/csv_loader.cc)、[NPY loader](https://github.com/engineai-robotics/engineai_robotics_native_sdk/blob/main/src/runner/rl_mimic_trajectory_example/src/npy_loader.cc) | 官方转换模型与实际消费者存在，但格式/布局不同，必须分exporter |
| [Dance runner](https://github.com/engineai-robotics/engineai_robotics_native_sdk/blob/main/src/runner/rl_dance_example/src/rl_dance_example_runner.cc)、[RL-Lab](https://github.com/engineai-robotics/engineai_rl_lab/blob/main/README_EN.md)、[csv_to_npz](https://github.com/engineai-robotics/engineai_rl_lab/blob/main/scripts/csv_to_npz.py) | 完整到策略训练/导出链，dtype/shape/wxyz/obs及初末姿态是实质依赖，训练时间未测 |
| [Walker S2 EDU](https://docs.ubtrobot.com/walker-s2-edu/docs/sdk/5/)、[小米运动管理](https://github.com/MiRoboticsLab/blogs/blob/rolling/docs/cn/motion_manager_cn.md)、[二开条款](https://agreement.cyberdog.xiaomi.com/developer.html) | 部署入口并不自动开放任意动作；developer_mode、可视化自定义接口及功能限制需按产品边界使用 |
| [SMPL-X许可](https://smpl-x.is.tue.mpg.de/modellicense.html)、[AMASS许可](https://amass.is.tue.mpg.de/license.html) | 公开授权限定非商用研究，代码MIT不覆盖素材；首期选择自采/获授权BVH并留证 |

本索引是公开资料核验，不是厂商对本平台的适配认证。以main/master/latest读取的资料须在B0锁源码快照、版本、依赖/模型摘要与许可记录；不能把文档读取日期当作SDK版本。公开不足的接口对应§18.4明确取数任务，不伪造已取得数据。

### 21.2 本轮完成和未做

已完成：结合五品牌第一手资料和现仓库源码，形成P/V/M三路径、行为/能力/IR、目标绑定、实际执行与取消、不可变发布快照、精确代码/API/三端改造、B0—B7工单、动作格式桥及取数/验收/估算方案。源样例只做现有清单校验与XML结构检查。

未做：业务代码、迁移/API/新操作码、真实编译器与BT.CPP运行、完整示例音频、SDK编译、样机安装/动作、双环境重建、策略训练、HTTP/数据库整链及HIL；没有为这些项目补假成功记录。

本次只改方案/ADR并增加docs下的契约示例与校验记录；SDK/CLI未立项的旧约束仍有效。建议新增的是完成产品目标所需的受限能力节点和内部执行协议，完整通用SDK、CLI、任意代码转译及低层控制器仍不在默认范围。范围、语义替代、DEV-02/DEV-04差异和目标设备按P-01—P-06审查；无需把常规工程实现细节逐项转交负责人。
