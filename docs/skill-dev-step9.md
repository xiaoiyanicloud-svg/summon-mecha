# 技能平台 · 步骤 9 交付说明（开发者端门户）· 最小闭环

> 日期：2026-10-14 ｜ 依据 [skill-dev-steps.md](./skill-dev-steps.md) 第 9 步（**P1**）
> 状态：**本轮交付「入驻 → 建技能 → 传包 → 提审」主线**；Beta 轨 / 数据看板 / 凭证密钥 / 财务结算 / 工具链 / 团队 RBAC **只留位未实现**。
> 完成判据（计划原文）是「开发者自助走通 入驻→建技能→传包→提审→看数据→结算」——**本轮走通了前 4 段**，后 2 段属留位范围。

---

## 一、形态决策（为什么这么做）

计划 D7 写的是「独立开发者门户（Vue3 SPA），不塞进 Bagisto Admin」。本轮**保留"独立"，但首期改服务端渲染**：

| 决策 | 选择 | 理由 |
|---|---|---|
| 是否塞进 Admin | **不塞** | ①越权风险：开发者账号=Admin 账号，ACL 配错即暴露订单/客户数据；②心智错位：Admin 是运营后台；③将来拆不掉；④会被看出基于开源项目 |
| 前端形态 | **Blade SSR + 独立布局** | 硬约束「不改差项目健康度」——不引入 Vue 工程/构建/独立部署/另一套登录态 |
| 接口形态 | **按计划 `/api/dev/*` 定** | 将来换 SPA 或接小程序 web-view 时主要复用这些端点 |

> ⚠ **更正（2026-10-14，审计后）**：本文档早先写的是"换 SPA 时**数据层不用动**"——**这句话是错的**，已被一次对抗审计证伪。
> 当时 `/api/dev/*` 有三处硬缺口，纯前端根本跑不通闭环（无发 token 端点、入驻端点被 403 死锁、协议正文不返回）。
> 这三处**现已修复**（见 §四之二），并在 `DeveloperContractTest` 里锁死。正确表述是：
> **数据层骨架与安全设计可用，但切换前必须先补齐合同层**——这一步已做。

> ⚠ **诚实标注**：相对计划原文，这是一次**有时限的偏离**（SPA → SSR）。用 `/api/dev/*` 合同保住切换路径。

---

## 二、身份与鉴权

- **开发者 = 一行 `customers` + 一行 `developer_profile`**（`customer_id` 唯一），入驻时建 `sellers` 行（`code` 平台生成，**不接受前端传入**）。
- 新增 `developer` guard（session）+ `developers` provider，与买家会话**互不影响**；代码一律显式 `auth('developer')`。
- 三个中间件：

| 别名 | 类 | 作用 |
|---|---|---|
| `developer` | `AuthenticateDeveloper` | 登录 + 已入驻 + 状态正常；未登录跳 `/dev/login`（**不是 `/admin`**）；登录未入驻跳入驻页 |
| `developer.session` | `RequireDeveloperLogin` | **只要登录** —— 给入驻/协议页用，否则会与 `developer` 互相弹跳成死循环 |
| `api.developer` | `ResolveDeveloper` | `/api/dev/*` **只认 Bearer token** |

- **为什么 `/api/dev/*` 只认 token 不接受 session**：`bootstrap/app.php` 对整条 `api/*` 前缀豁免了 CSRF，若接受 cookie-session 就是 CSRF 洞。表单走 `/dev/*`（web 组，`@csrf` 生效）。

---

## 三、归属（本步的安全核心）

**锚点**：`products.seller_id` / `skill_versions.seller_id`，与 `developer_profile.seller_id` 同源。建技能/建版本时 `seller_id` **从已鉴权的 profile 取，绝不从请求体取**。

> ⚠ **红线**：既有 `SkillAdminController::resolveSellerId()` 是**从客户端读 `seller_code`** 再查 —— 那是 internal.key 通道（单一平台密钥信任整条通道）的设计。开发者通道**没有复用它**。

- 跨租户一律 **404**（不是 403）—— 403/404 的差异可以被用来枚举他人资源 id。
- 列表**在查询层**带 seller 过滤，不是查完再判。
- **平台自营 `seller_id = NULL`，开发者恒非 NULL** → 等值判定天然隔离两条通道。
- 额外挡了一层：`seller_id` 为空的畸形 profile **fail-closed**（`where('seller_id', null)` 在 Laravel 会退化成 `is null`，那就正好匹配到平台自营商品 —— 是越权）。

---

## 四、交付清单

### 新增（`my-bagisto-store/packages/Webkul/Shop/src/`，集中在 `Dev` 子命名空间）

| 路径 | 内容 |
|---|---|
| `../config/developer_portal.php` | 门户前缀 / 主体类型白名单 / **协议文档与版本**（正文进配置，改正文可被 git 追溯） |
| `Models/{DeveloperProfile,DeveloperAgreement,DeveloperTeamMember}.php` | 3 个模型（表早已建好，此步补模型） |
| `Http/Middleware/{AuthenticateDeveloper,RequireDeveloperLogin,ResolveDeveloper}.php` | 见上表 |
| `Services/Developer/DeveloperOnboardingService.php` | 建 seller+profile+owner team、协议签署留痕（同事务、幂等） |
| `Services/Developer/DeveloperSkillService.php` | 建技能/版本、**归属解析唯一入口**、传包、提审 |
| `Services/SkillSupply/{AssetUploadService,AssetUploadResult}.php` | **抽取**的上传安全核（见下） |
| `Http/Controllers/Dev/{PortalController,OnboardingController,SkillController}.php` | Blade 门户 |
| `Http/Controllers/API/Dev/{ProfileController,AgreementController,DeveloperSkillController,AuditController}.php` | `/api/dev/*` JSON 合同 |
| `Routes/dev.php` | 门户路由 |
| `Resources/views/dev/**` | 独立布局 + 8 个页面 |
| `tests/Feature/Dev/{DeveloperPortalTest,DeveloperAuthzTest}.php` | 闭环 + 越权 |

### 修改

| 路径 | 改什么 | 风险 |
|---|---|---|
| `config/auth.php` | 加 `developer` guard / `developers` provider | 低（纯新增） |
| `Shop/src/Providers/ShopServiceProvider.php` | 注册 3 个中间件别名 | 低 |
| `Shop/src/Routes/web.php` | `require 'dev.php'` | 低 |
| `Shop/src/Routes/api.php` | 追加 `/api/dev/*`（放在既有 `api` 组内，继承中间件） | 低 |
| `Shop/src/Models/Seller.php` | 加 `developerProfile()` 反查 | 低 |
| `Shop/src/Http/Controllers/API/SkillAdminController.php` | `storeAsset` **委托**给 `AssetUploadService` | ⚠ 唯一触碰既有通道的改动 |

> **关于 `storeAsset` 抽取**：目的是**只有一份 sha256/basename/私盘落盘实现**（复制成两份迟早漂移）。
> 做法是**先补回归测试再重构** —— `tests/Feature/API/SkillAdminAssetTest.php`（10 条：私盘落盘 / sha256 不符 422 / 越界文件名清洗 / carrier 白名单 / 幂等覆盖 / 鉴权 503·401 / 404），
> 抽取前后**原样通过**，这是"没改坏 internal.key 通道"的判据。

### 留位不做
模型 `DeveloperApp`/`ApiCredential`/`ApiCallLog`/`SkillReview`/`SkillReport`；路由 `/api/dev/{team,apps,credentials,analytics,finance,tools}/*`、`skills/{id}/beta`；功能 Beta 测试轨 / 数据看板 / 凭证密钥生命周期 / 财务结算提现 / 团队 RBAC / Admin 入驻审核 UI；小程序 web-view、独立 SPA。

---

## 四之二、合同层补齐（对抗审计后的修复）

一次独立审计（6 个 agent：模块覆盖 / 耦合度 / 接口合同 / 切换成本 / 对抗验证 / 综合判断）对 `/api/dev/*` 做了对抗验证，
**证伪了"数据层不用动"**，并找出三处硬缺口。以下是修复，全部有回归测试锁死：

| # | 缺口（审计发现） | 危害 | 修复 |
|---|---|---|---|
| 1 | **入驻端点是僵尸端点**：`POST /api/dev/profile` 挂在 `api.developer` 下，该中间件对"没有 profile"的用户返回 403 —— 而创建 profile 的正是这个请求 | **新开发者永远无法通过 API 入驻**（纯前端闭环第一环断裂） | 新增轻量中间件 `api.developer.login`（只要求 token 有效），入驻与 profile 查询改挂它；无 profile 时 `GET profile` 返回 `data: null`（"还没入驻"这一态） |
| 2 | **没端点发 token**：`/api/dev/*` 只认 Bearer，但没人发 token | 纯前端登不进来 | 新增 `POST /api/dev/auth/token`（+ `logout`）。**按 token 过期**（30 天，`createToken` 的 expiresAt），不动全局 `sanctum.expiration`（那是 null 且影响小程序买家） |
| 3 | **协议正文不返回**：`GET /api/dev/agreements` 只回 doc_type/title/version/signed | 签署页渲染不出来（而留痕哈希的正是正文） | index 增加 `text` 与 `text_hash` |

外加两项：
- **信封冻结**：成功一律 `{message, data}`、错误一律 `{message, code}`；404 也带 code（此前 404 无 code、列表只回 `{data}`，SPA 无法统一分支）。
- **`max_asset_kb` 补进配置**（`config/skill_delivery.php`）：此前三处调用全在吃硬编码兜底 51200（50MB），**而 PHP 实测 `upload_max_filesize=20M`** —— 应用层的 50MB 是假承诺，超限文件在进校验前就被 PHP 丢弃。现改为 20480 KB 并在注释里写明"必须先改 php.ini/nginx，再调大这里"。

---

## 五、协议留痕

`developer_agreement.sign_payload` 存 `{checked, doc_type, doc_version, agreement_text_hash, ip, user_agent, signed_at}`。

其中 `agreement_text_hash` 是关键：留的是"**当时那一版正文**"的 sha256 —— 将来改了协议，能证明开发者当年签的是哪一版。协议正文放 `config/developer_portal.php`，改正文是一次可被 git 追溯的改动，且**必须同时升 `version`**。

---

## 六、实测

| 项 | 结果 |
|---|---|
| `DeveloperPortalTest`（闭环） | **5 passed** —— 入驻（seller+profile+owner）/ 协议（漏签被拒、签全留痕、含正文指纹与 IP）/ 建技能（归属正确且 **flat 行已生成，不是残品**）/ 传包→提审（`audit_record` 出现 L0）/ 两道闸门（未签协议、未传包都拦） |
| `DeveloperAuthzTest`（越权） | **6 passed** —— 开发者 B 读写 A 的技能/版本/传包/提审**全部 404**；平台自营技能不可见；`/api/dev` 无 token 401；**买家身份但无开发者主体 403**；开发者 token 越权 404；未签协议提审 422 |
| `DeveloperContractTest`（**合同层**） | **6 passed** —— 错的凭据 401 且不区分邮箱/密码（防枚举）；未入驻也能拿 token 且 **profile 端点不再 403**（僵尸端点回归）；**纯 API 跑完整闭环**（登录→入驻→签协议→建技能→建版本→传包→提审→查审核）；登出吊销 token 行；该拦的仍拦（未入驻调 skills/agreements → 403）；信封冻结（成功 `{message,data}` / 404 带 `code`） |
| `SkillAdminAssetTest`（回归） | **10 passed** —— internal.key 通道行为未变 |
| **真实 HTTP 冒烟**（curl，非测试替身） | 发 token → 未入驻取 profile **200 `data:null`（不是 403）** → 未入驻调 skills **403** → 入驻 **201** → 协议正文可取（`text_len=84/74`，hash 一致）→ 签署 200 → 建技能 201 → 建版本 201 → **传包 multipart+Bearer 201 `file_ready:true`** → 提审 200 `SUBMITTED`（L0 通过）→ 审核记录可查 → 登出 200 → **同一 token 再请求 401**（真吊销，Pest 里因 guard 缓存验不了，只能这样验） |
| 语法 | 全部新增/改动 PHP `php -l` 通过 |

> **一个被探针抓出来的真问题**（已修）：`createSkill` 最初只建了 `products` 行，**没派发 Bagisto 的建品事件** →
> `Product@afterCreate → flatIndexer->refresh()` 不触发 → `product_flat` 零行 → **商品是残的**（列表查不到、详情空白）。
> 现在按 Admin 的写法派发 `catalog.product.create.before/after` 与 `update.before/after`，flat 正常生成。

### 关于全量测试套件的既有失败（如实记录）

`vendor/bin/pest packages/Webkul/Shop/tests` 全量跑：**471 passed / 27 failed**。

- **27 个失败全部在 stock Bagisto 的店面域**：`HomePageTest`（首页/比较列表）、`CartTest`（税率）、`CheckoutTest`（下单，多种商品类型）、`CategoryProductTest`（按价格排序）、`AccountTest`（校验）。
- **没有一条**落在本次改动涉及的范围（Skills / Dev / Deploy / SkillAdmin）——这些域的测试全绿。
- 抽查 `HomePageTest > returns the home page of the store`：**单独跑也失败**（该进程内没有本步的测试执行），报错是"期望原版**演示商店**的 HTML"——而本项目的前台早已被替换、停用。
- 本次改动与这些域的接触面：`auth.php`（纯新增 guard/provider）、`ShopServiceProvider`（纯新增别名）、`web.php`/`api.php`（新增路由全在 `/dev`、`/api/dev`，与它们无重叠）。
- ⚠ **无法给出"改动前基线"的干净对比**：这几个文件在工作区里相对 HEAD 早已被前几轮会话改过（部分新文件甚至未纳入版本控制），`git stash` 会退回 stock Bagisto 而不是"我改之前"。
  因此结论是**基于上述证据的判定**（域不重叠 + 单独跑亦失败 + 期望值指向已被替换的原版前台），**不是**一次严格的 A/B 对照。若需要严格基线，建议单独排一次。

---

## 七、仍需外部 / 后续

| 项 | 说明 |
|---|---|
| **独立备案域名 + ICP** | 计划要求门户独立域名；首期跑在同域 `/dev`，域名到位再切（只改路由加载，业务代码不感知） |
| 微信扫码 / 手机号验证码登录 | 依赖开放平台与备案域名；本轮用邮箱密码（账号即 customers 表） |
| **企业实名审核** | 审核动作属平台端（Admin 入驻审核 UI **本轮未做**）→ `realname_status` **如实停在 `pending`，且不据此拦流程**，**没有伪造 approved** |
| 内容安全 `msgSecCheck` | L0 机审的关键字表仍是占位（第 6 步遗留） |
| Beta 测试轨 / 看板 / 凭证 / 结算 | 计划 P1 后段，本轮留位 |
| 对象存储 | 技能包仍落本地 `private` 盘（第 3 步遗留） |

---

## 八、怎么用（本机）

```bash
# 起服务
cd my-bagisto-store && D:/php.exe artisan serve --port=8899

# 浏览器打开
http://127.0.0.1:8899/dev/login
```

开发者账号 = 平台 C 端账号（customers 表）。登录后：入驻 → 签署两份协议 → 创建技能 → 建版本 → 上传 `.skill` 包 → 提交审核 → 在「审核状态」看 L0 结果与驳回理由。

---

## 九、要不要切换成"完全独立门户"？（审计结论，2026-10-14）

**结论：现在不切，Blade 继续推进。**

**为什么现在不切**（三条均有实证）：

1. **物理前提不存在**：独立门户第一要求是独立域名，而项目当前没有生产域名（`APP_URL=http://localhost`），ICP 备案是月级周期且未定 —— **没有部署目标**。
2. **切了不解锁任何近期能力**：Blade 的 8 个页面与 SPA 功能等价；现在切只会把"跨域登录态 + CORS 收敛 + 上传链路硬化 + 构建部署"的成本一次性叠加。
3. **该补的合同层在 Blade 时代补，两边都受益** —— 这正是 §四之二 做的事。

**什么时候该切**（触发条件，满足其一即启动）：

- **ICP 备案通过 + 生产域名就绪**（小程序 web-view 尤其硬依赖这条）
- **公开注册立项**，或活跃外部开发者 **≥ 10–20 家**（邀请制人肉运营阶段不需要）
- **出现一个 Blade 明确撑不住的功能进排期**：数据看板（交互图表/筛选）、B1 富编辑（截图/多语言/预览视频）、或 **>20MB 大包上传的进度/断点**需求
- **前后端发布节奏解耦**（改一次文案就要发一次 Laravel 版，成本开始累积）
- 合同层已补齐（**已满足**）—— 补完那天即最佳切换起点

**Blade 的天花板**（先痛顺序）：
① **大包上传体验**（最先痛，且当前 PHP 20M 是硬闸门）→ ② 数据看板（每加一个筛选维度都要改后端）→ ③ 富编辑（整页刷新的摩擦累积）→ ④ **发布节奏**（组织税，可能比功能更早痛：改文案要发版，且门户会成为没人愿维护的孤岛）。

**将来要切时，什么能搬、什么必须重写**：

| 能原样搬 | 必须重写 |
|---|---|
| `Services/Developer/*`、`Services/SkillSupply/*`（含上传安全核）、Models、迁移、全部安全设计（seller_id 锚点 / 跨租户 404 / Bearer-only）、`/api/dev/*` 控制器与中间件 | 登录流程（session attempt → token 获取/存放/刷新）、上传交互（整页表单 → XHR 进度/跨域 multipart）、错误处理映射（按冻结信封） |

**红线**：不得为了省事给 `/api/dev/*` 打开 session 接受 —— `api/*` 全局豁免 CSRF，路径级豁免不区分来源，开了立即是 CSRF 洞。
**不需要做**：拆独立 Laravel 工程（后端零收益）、动 Admin。路由名 `shop.api.dev.*` 冻结为合同标识，只增不改。
