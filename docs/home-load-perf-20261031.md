# 首页加载优化（2026-10-31）

> 问题：每次进入/切回首页，场景方案与技能市场都要重新加载，首屏等待约 5–6 秒。
> 范围：前端缓存与懒加载 + 后端 CORS 预检缓存（A–D 方案，用户已确认）。
> 不涉及：场景/技能的业务口径、接口协议、任何已拍板决策（D1–D11）。

---

## 1. 根因（实测，不是推断）

| 现象 | 证据 |
|---|---|
| 首屏同时发 5 个数据请求，场景还发 2 次 | `onLoad` + `onShow` 都调 `refreshScenes()`；EcoCenter `mounted` 里 `loadAccessory` + `loadEvents`；SkillMarket `mounted` 里 `refresh`；Dongtai `mounted` 里 `load` |
| 本机后端每个请求约 0.85–1.05s | `curl -w %{time_total}`：`/api/scenes` 0.88s、`/api/skill-catalog/search` 0.87s、`/api/accessory-catalog/search` 0.89s、`/api/events` 1.05s、连 `/up` 都要 0.93s（Laravel 引导开销，dev 环境无 opcache） |
| 单进程 `php -S` 串行处理，5 个请求 ≈ 5s | 上表逐个串行相加 |
| 每次 `onShow` 都绕开缓存 | `loadBackendScenes({ force: true })`；`refreshSkills()` 直接 `refresh()` |
| 刷新时先清空内容，造成"每次都在加载"的观感 | `scenesLoading = true` 无条件置位；`loadSkillMarket` 非追加模式先 `state.rows = []` |
| H5 请求数翻倍 | 带 `Authorization` 的跨域 GET 必发 OPTIONS 预检；`config/cors.php` 的 `max_age => 0` 让预检永不复用（实测单次预检 ~0.64s） |

---

## 2. 改动清单

| 文件 | 改了什么 |
|---|---|
| `pages/home/index.vue` | 删除重复的 `onLoad`（保留 `onShow` 统一入口）；`refreshScenes()` 不再 `force: true`，改走场景目录自带的 5 分钟 TTL；仅有空内容时才置加载态，已有卡片时静默刷新；接口失败仍按既有口径清空卡片并报错（不回退旧快照） |
| `components/EcoCenter.vue` | 删除 `mounted` 里的配件/赛事请求；改为 `switchTab()` 切到对应 Tab 才加载；新增 `eventLoaded`；`refreshSkills()` 改为 `refreshIfStale()` |
| `components/SkillMarket.vue` | 新增 2 分钟缓存（`_loadedKey/_loadedAt`）+ 同条件请求去重（`_pending`）；`refreshIfStale()`（切回页面用）与 `refreshNow()`（搜索/筛选/重试用）分开；同条件静默刷新保留旧卡片；查询/筛选变化才清空 |
| `components/shouye/dongtai.vue` | 新增 `active` prop；只在切到「动态资讯」时加载，独立使用（不传 prop）保持原行为 |
| `utils/skill-market.js` | `loadSkillMarket` 新增 `keepRows` 选项（默认 false，原行为不变） |
| `my-bagisto-store/config/cors.php` | `max_age` 0 → 86400（浏览器预检缓存 24 小时；小程序不走 CORS，不受影响） |
| `tools/tests/skill-market.test.mjs` | 新增 `keepRows` 用例 |

---

## 3. 实测证据

### 3.1 首屏请求数（Chrome CDP 抓 Network.requestWillBeSent）

优化后首次进入首页，数据请求只剩 2 个：

```json
"initial": [
  "/api/skill-catalog/search?page=1&limit=12",
  "/api/scenes"
]
```

切到「分类」再切回「首页」：

```json
"backToHome": []
```

> 优化前是 5 个接口（场景还被 `onLoad`/`onShow` 拉两次），每个 ~0.9s 串行 ≈ 5–6s；现在是 2 个 ≈ 1.8s，切回首页 0 请求。

### 3.2 生态中心四个 Tab 懒加载

| 操作 | 发出的请求 |
|---|---|
| 首屏（技能市场） | `/api/skill-catalog/search` + `/api/scenes` |
| 点「配件皮肤」 | `/api/accessory-catalog/search` |
| 点「赛事活动」 | `/api/events`（及封面图） |
| 点「动态资讯」 | `/api/content/posts` |
| 再切回「技能市场」 | **无请求**，原卡片保持显示 |

### 3.3 预检缓存生效

```
$ curl -X OPTIONS ... /api/scenes
HTTP/1.0 204 No Content
Access-Control-Max-Age: 86400
```

### 3.4 自动化测试

```
$ node --test tools/tests/skill-market.test.mjs tools/tests/scene-catalog.test.mjs
ℹ tests 24
ℹ pass 24
ℹ fail 0
```

### 3.5 页面实测

`docs/home-load-perf-home-2.png`：H5（390×844）首页场景卡片与技能卡片正常渲染。
Vue SFC 编译校验（HBuilderX 自带 `@vue/compiler-sfc`）：4 个改动的 `.vue` 全部 `OK`。

---

## 4. 回滚

```bash
git checkout -- pages/home/index.vue components/EcoCenter.vue components/SkillMarket.vue \
  components/shouye/dongtai.vue utils/skill-market.js my-bagisto-store/config/cors.php \
  tools/tests/skill-market.test.mjs
```

> `config/cors.php` 改的是 `max_age`，回滚后无需其他操作；dev 环境无 config 缓存，生产若开了 `config:cache` 需重新 `php artisan config:cache`。

---

## 5. 未做 / 已知边界

- ❌ **本地快照（E）未做**：冷启动（App/小程序被系统回收后重开、H5 首次打开）仍需真实请求 2 个接口；按用户选择只做到 A–D。
- ❌ **后端单请求 ~0.9s 未优化**：这是本机 `php -S` + 无 opcache 的引导开销，不是查询慢（`/up` 同样 0.93s）。生产 nginx + php-fpm + opcache 下会显著更低，未在本轮验证。
- ⚠ 场景数据最长 5 分钟、技能列表最长 2 分钟后才自动刷新；后台改了场景/上架技能，用户侧最多等这么久（下拉/点搜索可立即刷新技能）。
- ⚠ 场景接口失败仍清空卡片（既有口径：只展示服务端成功快照，不回退旧数据），本次未改。
- 未提交 git（按项目规则，提交需另行确认）。
