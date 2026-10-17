# 召唤机甲 · 机器人生态服务平台（SUMMON MECHA）

面向机器人场景服务的交易与交付平台：uni-app 多端前台 + Bagisto/Laravel 模块化后端。

> **开始改代码前请先读**：[CLAUDE.md](CLAUDE.md)（不可谈判的决策与边界）、[docs/ai-project-brief.md](docs/ai-project-brief.md)（项目理解手册）、[NAVIGATION.md](NAVIGATION.md)（代码定位）。

---

## 仓库结构

| 路径 | 内容 |
|---|---|
| `pages/` `components/` `static/` | uni-app 前端源码（微信小程序 / H5 / App） |
| `pages.json` `manifest.json` | uni-app 路由与多端配置 |
| `utils/` `tools/` | 前端工具与脚本 |
| `my-bagisto-store/` | 后端：Bagisto / Laravel 模块化单体 |
| `docs/` | 项目手册、技能平台计划、运行手册 |
| `coze-studio/` | Coze 开源项目的本地克隆，**不属于本仓库**（已在 `.gitignore` 中排除） |
| `my-bagisto-store2 备份/` | 历史备份，**不纳入版本库** |

## 环境要求

| 依赖 | 版本 |
|---|---|
| PHP | 8.2+（本机实测 8.4） |
| Composer | 2.x |
| MySQL | 5.7+ / 8.x |
| Node.js | 18+（仅用于后台主题资源构建；前台 uni-app 用 HBuilderX） |
| HBuilderX | 运行 uni-app 前端 |
| 微信开发者工具 | 调试小程序端（可选） |

## 后端启动（`my-bagisto-store/`）

```bash
cd my-bagisto-store

composer install
cp .env.example .env          # 然后填写 DB_DATABASE / DB_USERNAME / DB_PASSWORD
php artisan key:generate

php artisan migrate --seed    # 或 php artisan bagisto:install 走交互式安装
php artisan storage:link

# 本项目业务预置数据（幂等，可重复执行）
php artisan db:seed --class=DeviceModelSeeder
php artisan db:seed --class=SkillCatalogSeeder

php artisan serve             # http://127.0.0.1:8000 ，后台 /admin
```

异步任务（试用回收、通知、结算等依赖它们）：

```bash
php artisan queue:work
php artisan schedule:work
```

密钥相关（首次部署或本地联调需要）：

```bash
php artisan agent:keys:generate
```

> 目录内另有 Bagisto 上游 README，以本文件与 `docs/` 为准。

## 前端启动（仓库根目录）

1. 用 **HBuilderX** 导入仓库根目录（`manifest.json` 已就位）。
2. 运行 → 运行到小程序模拟器 / 浏览器 / 真机。
3. 微信小程序端也可用**微信开发者工具**直接导入根目录（`project.config.json` 已就位）。
4. 前端接口地址在 `utils/` 与各页面配置中，按需指向本地后端 `http://127.0.0.1:8000`。

## 不在版本库里的内容

`.gitignore` 已排除以下可重建 / 敏感内容，clone 后需自行生成：

- `my-bagisto-store/vendor/`、`node_modules/` —— 依赖，装回来的
- `my-bagisto-store/.env` —— 含密钥，**从未进入过 git 历史**
- `my-bagisto-store/storage/` —— 运行日志、缓存、上传文件、签名私钥
- `unpackage/` —— HBuilderX 构建产物
- `coze-studio/`、`my-bagisto-store2 备份/` —— 独立上游仓库与历史备份

## 分支与协作

- 主分支：`main`（受保护，改动走 Pull Request）
- 分支命名：`feat/<主题>`、`fix/<主题>`、`docs/<主题>`
- 提交信息：沿用 Conventional Commits（`feat:` / `fix:` / `refactor:` / `docs:` / `test:`）
- 涉及 `CLAUDE.md` 中 D1–D11 已拍板决策的改动，先报批再动手
