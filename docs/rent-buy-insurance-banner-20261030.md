# 租赁/购买详情页保障区图片覆盖（2026-10-30）

## 结论

租赁、购买商品详情页原先的「大平台・有保障」文字服务区已整体替换为 `static/baoxian.png`。技能、配件详情页保持原样。

## 改动

| 文件 | 改动 |
|---|---|
| `pages/robot/index.vue` | `actionType` 为 `rent` / `buy` 时渲染保险保障图，替换原服务文字；配件等其它类型继续走原服务信息 |
| `static/baoxian.png` | 使用用户指定保障图片 |

样式采用 `mode="widthFix"`、100% 宽度、无额外内边距，图片区域与详情内容左右边距一致。

## 实测

- Node 全量测试：**88/88 passed**。
- `git diff --check`：通过。
- HBuilderX H5 编译成功。
- 真实 Chrome，390×844：
  - 租赁详情：保险图加载成功，原「全国包邮」文字不再出现；
  - 购买详情：保险图加载成功，原「全国包邮」文字不再出现；
  - 配件详情：仍显示原「平台严选・服务可追溯」服务信息，未被误覆盖；
  - 页面异常、控制台 error、网络失败均为 0。
- 结果：`docs/rent-buy-insurance-banner-20261030/result.json`
- 截图：
  - `docs/rent-buy-insurance-banner-20261030/rent-detail-390.png`
  - `docs/rent-buy-insurance-banner-20261030/buy-detail-390.png`

## 回滚

改动前备份：

```text
.claude/backup/rent-buy-insurance-banner-20261030/pages/robot/index.vue
```

复制回 `pages/robot/index.vue` 后重新导出 H5。不要对整文件执行 `git checkout`，工作区仍有其它未提交改动。
