# 机器页四类目图片修复（2026-10-29）

## 结论

租赁、购买、技能、配件四个 Tab 的商品卡图片已恢复；你设计的真实图优先使用后端 `/storage` 原图，其余缺图或加载失败统一使用召唤机甲 Logo。技能详情轮播也同步修复。兜底只发生在展示层，不修改数据库、价格、库存、上下架状态或可售性。

## 根因

1. Bagisto `ProductResource` 返回 `/cache/{size}/...` 缩略图地址；当前实例未注册可用的 `/cache/...` 路由，实际文件位于 `/storage/...`，因此有图商品也全部裂图。
2. 租用/购买卡片映射拿到图片 URL 后没有再走统一的 cache/localhost 归一化。
3. 技能详情只读取图集对象的 `url` 字段，但商品接口实际返回 `medium_image_url` / `large_image_url` 等字段。
4. 配件和技能无图时只显示 emoji；租赁/购买的真实图加载失败则直接留白。

## 改动

| 文件 | 改动 |
|---|---|
| `utils/product-catalog.js` | 新增图片 URL 归一化：cache 路由回退 storage、localhost 改写、相对路径补全；所有缺图兜底统一为召唤机甲 Logo |
| `pages/robot/index.vue` | 租用/购买/配件/技能目录映射统一归一化；无图与加载失败均回退本地图；场景卡也补加载失败兜底 |
| `components/SkillMarket.vue` | 技能卡统一 URL 处理与实拍图兜底 |
| `components/jiqi/peijian.vue` | 配件卡统一 URL 处理与实拍图兜底 |
| `pages/home/jineng.vue` | 技能详情兼容多种 Bagisto 图片字段，无真实图时展示兜底图 |
| `components/EcoCenter.vue` | 首页生态中心的配件卡同步修复 |
| `tools/tests/product-catalog.test.mjs` | 增加 cache→storage、localhost、兜底图测试 |

## 实测证据

- `node --test tools/tests/*.test.mjs`：**88/88 passed**（其中图片/价格专项 10/10）。
- `git diff --check`：通过。
- HBuilderX H5 编译：**编译成功**，输出 `D:/main/unpackage/dist/build/web`。
- 真实 Chrome，390×844：
  - 租用：6/6 卡片图片加载成功；
  - 购买：2/2；
  - 技能：4/4；
  - 配件：4/4；
  - 技能详情：1/1；
  - 页面异常、控制台 error、网络失败均为 0。
- 结果文件：`docs/catalog-image-fix-20261029/result.json`。
- 截图：
  - `docs/catalog-image-fix-20261029/robot-租用-390.png`
  - `docs/catalog-image-fix-20261029/robot-购买-390.png`
  - `docs/catalog-image-fix-20261029/robot-技能-390.png`
  - `docs/catalog-image-fix-20261029/robot-配件-390.png`
  - `docs/catalog-image-fix-20261029/skill-detail-390.png`

## 回滚

本次改动前备份位于：

```text
.claude/backup/catalog-images-20261029/
```

将对应文件复制回原路径后重新导出 H5。不要使用 `git checkout` 整文件回滚，因为工作区还有其它未提交改动。

## 未做 / 边界

- 未把兜底 Logo 写入商品库；后续运营补真实图后仍会自动优先显示真实图。
- 本次验证为 H5 真实 Chrome；未重新执行微信小程序真机与完整生态 e2e。
