# WBS 无外部硬件依赖工作包 · 第四批交付（2026-11-05）

> 范围：**B3 目标发布审核 Admin API + 四角色 ACL + 真实双进程并发撤销探针**。
> 环境：PHP 8.4.24 / Python 3.12.14 / MySQL 5.7.26（隔离库 `summon_mecha_step10_test`）。

## 0. 结论

- B3 已完成：Admin 可对**某一机型的具体构建产物**执行 approve / reject / revoke；
  `build_key + artifact_asset_id + target_model_code + target_profile_digest` 必须同时匹配。
- 四角色 ACL 矩阵已测：viewer / reviewer / revoker / super；越权直接 403。
- 真实双进程并发撤销：**一个成功、一个 `TARGET_RELEASE_NOT_APPROVED`**；终态 revoked，审计事件仅 1 条。
- 本批 **37 项测试全过**（B3 11 + 既有 Admin ACL/角色回归 26）；门禁全绿。
- **诚实缺口**：HTTP 层并发竞态未单独压测；Admin 是平台级身份，“跨 seller”以显式 `seller_id` 作用域校验实现；真机 HIL 仍 BLOCKED。

## 1. 改动清单

### 权限与入口
- `packages/Webkul/Admin/src/Config/acl.php`：新增
  - `catalog.target-releases`（列表）
  - `catalog.target-releases.view`（详情）
  - `catalog.target-releases.approve`
  - `catalog.target-releases.reject`
  - `catalog.target-releases.revoke`
- `menu.php`：新增「目标发布审核」入口。
- `Routes/catalog-routes.php`：5 条路由（index/show/approve/reject/revoke）。
- `User/Http/Middleware/Bouncer.php`：对 `admin.catalog.target-releases.*` 权限不足直接 **403**（与生态发布工作台同口径），不再落到统一 401。

### 控制器与页面
- 新增 `Admin/Http/Controllers/Catalog/TargetReleaseController.php`：
  - 列表/详情；approve/reject/revoke；
  - 复用 `SkillTargetReleaseService`（快照校验 + 只追加 `TargetReleaseEvent`）；
  - 驳回/撤销必须给理由；
  - 可选 `seller_id` 作用域校验：与版本归属不一致 → 409；
  - 每动作写 `operation_log`；
  - JSON 请求返回 409/422/404，表单请求重定向并 flash。
- 新增视图 `catalog/target-releases/{index,show}.blade.php`：
  - 列表筛选（状态/机型/seller）；
  - 详情展示 build_key / artifact / profile digest / 审计事件；
  - 动作按钮按 `bouncer()->hasPermission(...)` 显示。

### 测试与并发
- 新增 `Admin/tests/Feature/Catalog/TargetReleaseAdminTest.php`（11）。
- 新增并发探针：
  - `qa/wbs-20261105/probes/b3_revoke_setup.php`
  - `b3_revoke_worker.php`
  - `b3_revoke_verify.php`

## 2. 四角色权限矩阵（实测）

| 角色 | 列表/详情 | approve | reject | revoke |
|---|---|---|---|---|
| viewer | ✅ | 403 | 403 | 403 |
| reviewer | ✅ | ✅ | ✅ | 403 |
| revoker | ✅ | 403 | 403 | ✅ |
| super (`permission_type=all`) | ✅ | ✅ | ✅ | ✅ |

> 菜单渲染要求同时具备父级 `catalog` 与叶子权限；这与 Bagisto 既有 ACL 树一致。

## 3. 证据

| CLAIM_ID | 内容 | 命令/退出码 | 原始文件 | SHA256 |
|---|---|---|---|---|
| B3-ADMIN-01 | B3 11 项全过 | `pest TargetReleaseAdminTest` / 0 | [b3-admin.txt](qa/wbs-20261105/b3-admin.txt) | 见 manifest |
| B3-REG-01 | 既有 Admin ACL/角色/评价台 26 项全过 | 4 个 Pest 文件 / 0 | [b3-admin-regression.txt](qa/wbs-20261105/b3-admin-regression.txt) | 见 manifest |
| B3-CONC-01 | 双进程并发撤销：一成功一 409，终态 revoked，1 条事件 | 两个独立 PHP 进程 / 0 | [b3-concurrency-evidence.json](qa/wbs-20261105/b3-concurrency-evidence.json) | `D9528A28…` |
| B3-ROUTE-01 | 5 条 Admin 路由注册 | `artisan route:list --path=target-releases` / 0 | [route-list-target-releases.txt](qa/wbs-20261105/route-list-target-releases.txt) | 见 manifest |
| B3-GATE-01 | 契约门禁通过 | `artisan ecosystem:contract-check` / 0 | [contract-check-b3.txt](qa/wbs-20261105/contract-check-b3.txt) | 见 manifest |
| B3-GATE-02 | 数据 preflight 通过 | `artisan ecosystem:data-preflight` / 0 | [data-preflight-b3.txt](qa/wbs-20261105/data-preflight-b3.txt) | 见 manifest |
| B3-LINT-01 | 6 个 PHP 文件 lint 全 OK | `php -l` / 0 | [php-lint-b3.txt](qa/wbs-20261105/php-lint-b3.txt) | 见 manifest |

完整哈希见 [evidence-manifest.json](qa/wbs-20261105/evidence-manifest.json)。

## 4. 状态机与安全语义

- **approve**：只有 `pending` 可进入 `approved`；已撤销 → 409。
- **reject**：必须给理由；已撤销 → 409。
- **revoke**：只有 `approved` 可撤销；非 approved → 409。
- **stale profile**：`release.target_profile_digest` 与当前 Profile 不一致 → 409（`TARGET_PROFILE_DIGEST_STALE`）。
- **跨 seller**：显式 `seller_id` 与版本归属不一致 → 409。
- **只追加**：每次动作写一条 `skill_target_release_events`，历史不覆盖。
- **撤销后交付**：`SkillDeliveryAssetResolver` 只认 `approved`，撤销即 fail-closed（第一批 B7 已测）。
- **并发**：服务层 `lockForUpdate` + 状态守卫；双进程实测一成功一 409，事件不重复。

## 5. 明确未做 / NOT_PROVEN

| 项 | 状态 | 原因 / 最小解除条件 |
|---|---|---|
| HTTP 层并发竞态压测 | **NOT_PROVEN** | 已做服务层双进程并发；未对同一 HTTP 路由做多请求压测 |
| 多租户 seller RBAC | **PARTIAL** | Admin 是平台级身份；跨 seller 以显式 `seller_id` 校验实现，未建 seller 级管理员模型 |
| 真机 HIL / 生产 MySQL/FPM | **BLOCKED** | 外部前提 |
| A3~A7 / B4~B6 | **NOT_RUN** | 不在本批 |
| red→green 单次日志 | **NOT_CAPTURED** | 缺陷以代码位置 + 新测试通过为证 |

## 6. 等级（本批结束时）

- 软件公共底座：**PARTIAL**。
- Agent：**PARTIAL**（A1 + A2.4 完成；真机观察器 BLOCKED）。
- Compiler：**PARTIAL**（B1/B2/B3/B7/B8 完成；B4~B6 未做）。
- 当前 SIL：**NOT_RUN**。
- HIL：**HARDWARE_UNVERIFIED**。
- Production Ready：**BLOCKED**。
- 五机型 GA：**BLOCKED**。

## 7. 回滚

本批无迁移。回滚 = 删除 `TargetReleaseController.php` 与两个 Blade 视图；还原 `catalog-routes.php`、`acl.php`、`menu.php`、`Bouncer.php`；删除 `TargetReleaseAdminTest.php` 与 B3 探针。

回滚后重跑 §3 的命令。未执行任何 git 写操作，未触碰 root 库。
