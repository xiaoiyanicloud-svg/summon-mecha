# 技能平台 · 步骤 8 交付说明（Agent 真机下发）· 完成

> 日期：2026-10-14 ｜ 依据 [skill-dev-steps.md](./skill-dev-steps.md) 第 8 步
> 状态：**✅ 完成** —— 平台侧（上一轮）+ 本轮补齐的**一键下发闭环 / 灰度自动回滚 / 参考 Agent**，全部实测。
> **设备端 Agent 本体（装到宇树/智元/众擎真机上）与 MQTT/EMQX 真传输** —— 仍属外部依赖，见 §七。

---

## 一、本轮补齐了什么

上一轮已交付平台侧（签名 / 状态机 / Agent 接入 / 熔断 / HTTP 轮询传输），但留了三处断点；
逐行复核时又发现一个**足以让"一键下发"名存实亡**的阻塞点，一并修掉：

### ★ 隐藏阻塞点：DID 不一致（最高优先级）

- 用户登记设备时 `DeviceBindingService` 建的是 `did = 'sn_'.$sn`（带 owner / devices_id）；
- Agent 自注册时 `AgentController::register` 建的是 `did = 'did_'.sha1($sn)`（owner = NULL）。

同一台物理机器因此分裂成**两条 binding**：`DeployController` 找到的是 `sn_` 那条，
`deploy_task.did` 写的是 `sn_`，而 Agent 只拉自己 `did_` 那条 → **任务永远送不到**；
且 `HttpPollAgentGateway::isOnline()` 看的是 `sn_` 那条的 `last_online_at`，永远是"离线" → 任务卡在 `pending_online`。

**修法**：`AgentController::register` 与 `DeviceBindingService::ensureForDevice` **双向按 `sn` 归并**
（各自都先查既有 binding，命中则复用/回填，不再新建）。语义统一为：**同一台机器只应有一条 binding**。

### 三处断点

| 断点 | 现象 | 修法 |
|---|---|---|
| **C 端无下发入口** | `pages/mine/deploy.vue` 进度页存在，但全 App 没有任何跳转，也没"下发"按钮 → 是孤儿页 | `skills.vue` 已指派设备卡片加「一键下发」；`SkillController::index` 补 `skill_version_id`/`agent_deliverable`/`device_online`；`DeployController::store` 解析 NULL 版本 |
| **灰度/自动回滚是孤儿代码** | `GrayReleaseService` 无任何路由调用；且 `install_record.status='updated'` **从不被写入** → `abort()` 遍历 0 台设备，自动回滚空转 | 新增 `GrayReleaseController`（internal.key）四接口；`recordInstall` 改 operation-aware；修 `abort` 的 `skillId:0` 硬编码；加 `onEvent` 惰性自驱 |
| **Agent 安装命令是死链** | `agent-setup.vue` 让用户 `curl .../agent/install.sh`，但脚本与路由都不存在（对用户的假承诺） | 新增 `AgentSetupController` 输出 `setup.sh` / 公钥 / 参考 Agent；新增一次性 enrollment token 流程 |

---

## 二、交付清单（本轮）

### 后端（`my-bagisto-store/packages/Webkul/Shop/src/`）

| 文件 | 改动 |
|---|---|
| `Http/Controllers/API/AgentController.php` | `register()` 按 sn 归并；**新增 `enroll()`**（一次性 token 换 DID + 密钥） |
| `Services/SkillDelivery/DeviceBindingService.php` | `ensureForDevice()` 对称按 sn 归并 |
| `Http/Controllers/API/SkillController.php` | `index()` 补 `skill_version_id` / `agent_deliverable` / `delivery_channel` / `device_paired` / `device_online` |
| `Http/Controllers/API/DeployController.php` | 解析 NULL 版本（否则无 `install_record`）；`device_id` 统一按 `devices.id`（去掉双重解释） |
| `Services/Deploy/DeployTaskService.php` | `recordInstall` 改 operation-aware（installed/updated/rolled_back/uninstalled）+ 写 `current_version`；`onEvent` 成功后惰性自驱灰度评估 |
| `Services/Deploy/GrayReleaseService.php` | 修 `abort` 的 `skillId:0`；`abort(..., $trigger)` 支持 manual；新增 `evaluateFromRecords()` |
| `Http/Controllers/API/GrayReleaseController.php` | **新建**：灰度 4 接口（internal.key） |
| `Http/Controllers/AgentSetupController.php` | **新建**：`agent/setup.sh` / `agent/platform_public.pem` / `agent/agent.py` |
| `Http/Controllers/API/DeviceController.php` | 新增 `agentToken()`（签发一次性安装 token）；`format()` 补 `agent_paired` / `agent_online` |
| `Services/Deploy/SigningService.php` | `publicKeyPem()` 前先确保密钥对已生成 |
| `Routes/api.php` / `Routes/store-front-routes.php` | 接线（灰度 4 条、`agent/enroll`、`devices/{id}/agent-token`、`agent/*` 三个 GET） |
| `tests/Feature/API/AgentDeployTest.php` | **新建**：6 个用例（项目**第一处** internal.key 路由测试） |

**无需迁移**：enrollment token 复用 `device_binding.meta`（JSON 列）。

### 前端（`D:/main/`）

| 文件 | 改动 |
|---|---|
| `pages/mine/skills.vue` | 已指派设备卡片加「一键下发」（`agent_deliverable` 才显示）→ 建任务 → 跳进度页 |
| `pages/mine/devices.vue` | 设备卡加「Agent 已配对 / 在线」徽标 |
| `pages/mine/agent-setup.vue` | 选设备 → 生成真实一次性安装命令；**修正 `curl ... \| bash --token X` 的错误写法** |
| `utils/api.js` | 加 `deviceApi.agentToken(id)` |

---

## 三、实测

### 1. 端到端探针（tinker，事务内，30 项全绿）

DID 归并（同 sn 仍 1 条 binding、复用原行、source 升 agent）→ `mine()` 新字段 → 一键下发 token →
enroll（did 一致、回传密钥与公钥、**一次性不可重放**）→ 下发任务 `dispatching`（**非 `pending_online`**）→
Agent 取指令 + **验签通过** → 回报 done → `success` + `install_record=installed` + `current_version` →
升级到新版本 → **`updated`**（旧版本仍 `installed`）→ 灰度指标 70% → **自动回滚**（`trigger=auto`、
`affected=1`、回滚任务 `skill_id=7` **非 0**）→ 回滚回报 → **`rolled_back`** → 手动回滚 `trigger=manual`。

### 2. HTTP 冒烟（`artisan serve` + curl）

| 端点 | 结果 |
|---|---|
| `GET /agent/setup.sh` | 200 `application/x-sh` |
| `GET /agent/platform_public.pem` | 200 `application/x-pem-file` |
| `GET /agent/agent.py` | 200 `text/x-python` |
| `POST /api/agent/enroll`（无效 token） | 401 |
| `GET /api/agent/commands`（无 `X-Agent-Key`） | 401 |
| `GET /api/agent/commands`（正确 key） | 200 |

### 3. 真实 Python 参考 Agent（非 Mock）

下载平台下发的 `agent.py` + 公钥，直接跑：轮询取到指令 → **RSA-SHA256 验签通过** → dry install →
回报 downloading/installing/done → 任务 `success`、`install_record=installed`、`current_version=1.0.0`。

### 4. Pest

```
vendor/bin/pest packages/Webkul/Shop/tests/Feature/API/AgentDeployTest.php
Tests: 6 passed (16 assertions)
```

覆盖：enroll 无效/过期/合法（含 token 一次性）、灰度接口 503（未配 key）/401（错 key）、
install_record 的 installed → updated → rolled_back 三分支。

### 5. 语法

全部改动 `php -l` 通过；4 个 `.vue` 的 script 块 `node --check` 通过。

---

## 四、两个踩过的坑（已写进代码注释）

1. **`/agent/install.sh` 会被 Bagisto 拦掉**：Installer 包的全局中间件 `CanInstall`
   对**任何含 `/install` 的路径**都 `redirect('/admin')`。
   → 脚本路径改成 `agent/setup.sh`（**不是审美，是绕开它**）。
2. **`curl ... | bash --token X` 是错的**：`--token` 会被 bash 当自己的选项吃掉。
   → 正确写法 `curl -fsSL <server>/agent/setup.sh | bash -s -- --token <TOKEN>`。

---

## 五、接口契约

### 灰度（internal.key，`X-Internal-Key` 头）

| 方法 | 路由 | 入参 | 出参 |
|---|---|---|---|
| POST | `api/skill-supply/gray-releases` | `{skill_version_id, strategy?, batch_no?}` | `{id,status,metrics,...}` |
| GET | `api/skill-supply/gray-releases/{id}` | — | 同上 |
| POST | `api/.../{id}/metrics` | `{installs, successes, failures}` | `{action, reason, release}` |
| POST | `api/.../{id}/abort` | `{reason?}` | `{affected_devices, release}` |

评估阈值：成功率 < 90% 暂停、< 80% 自动回滚。
**自动回滚有两条触发路径**：设备回报后由 `DeployTaskService` 自驱（本项目无 cron，这是主路径）；平台端主动 POST metrics。

### Agent 接入

| 方法 | 路由 | 鉴权 |
|---|---|---|
| POST | `api/agent/enroll` | 一次性 enrollment token（**故意不挂 agent.key**） |
| POST | `api/agent/register` / `heartbeat` / `evt` | `X-Agent-Key` |
| GET | `api/agent/commands?did=` | `X-Agent-Key`（轮询即心跳） |
| POST | `api/devices/{id}/agent-token` | 登录用户（30 分钟、一次性） |

**enrollment 流程**：用户在「我的设备 → 安装 Agent」选设备 → 平台签发一次性 token（只存哈希到 `device_binding.meta`）
→ Agent 用 `POST /api/agent/enroll` 换 DID + 共享密钥 + 平台公钥 → 之后一律用 `X-Agent-Key`。

---

## 六、参考 Agent 用法

```bash
# 用户视角(命令由平台生成,勿手写)
curl -fsSL http://<平台域名>/agent/setup.sh | bash -s -- --token <TOKEN>
```

脚本会：下载 `agent.py` + 公钥 → enroll 换身份 → 写 `config.json` → 装 systemd 服务（无 systemd 则 `nohup` 后台）。

**⚠ 这是参考实现**，用途是让下发链路可被平台侧端到端验证（当"模拟设备"跑）。
生产 Agent（装到宇树 G1 / 智元 / 众擎真机上）由设备端团队实现，**契约与此一致**：
`canonical = task_id | skill_id | version | package_hash | ts`，`RSA-SHA256` 签名，公钥来自 `/agent/platform_public.pem`。

---

## 七、仍属外部依赖（本轮无法平台侧验证）

| 项 | 原因 |
|---|---|
| **设备端 Agent 本体**（真机） | 外部开发；本轮用参考 Agent 当模拟设备验证 |
| **MQTT/EMQX + Go 服务真传输** | 需 EMQX 与独立 Go 服务；`deploy_gateways.default=mqtt` 仍注释，抽象层已就位，切换业务零改动 |
| **真实技能包下载/落地** | `package_url` 平台侧恒为空 → 参考 Agent 只能 dry install。真实载体分发需要 **Agent 侧签名限时下载端点**（本轮范围外） |
| **per-device 证书 / PKI** | enroll 回传的仍是**共享密钥**，只用于开发期；生产应换成 per-device 证书（§4.22 F1），enroll 即替换点 |
| 固件级防降级（Uptane/TUF） | 计划 F3 |

---

## 八、诚实边界

- 参考 Agent 的 `dry install` 表示**没有真的拉取并安装技能包**——平台侧尚无面向 Agent 的签名限时下载端点。链路其余部分（签名、状态机、回报、落库、灰度）都是真实的。
- `AgentSetupController` 的三个端点是**纯文本 GET、无鉴权**：`setup.sh` 与 `agent.py` 是公开脚本（可接受），
  但 `platform_public.pem` 是公钥（本就公开）。**私钥永不外发**。
- 灰度**不做**"按比例自动筛选下发目标"（本轮既定范围）：灰度只管**评估与回滚**，下发由各通道发起。
