# 配件排序头部下拉（2026-10-29）

## 改动

- 机器页配件目录原有「综合 / 价格↑ / 价格↓」三个平铺入口收为一个「综合排序」下拉。
- 下拉选项：综合排序、好评优先、销量优先、最低价格、最高价格。
- 选择后由服务端重新查询、分页，不在当前页内做假排序。

## 排序口径

- 好评优先：只统计 `product_reviews.status=approved`，按平均评分降序，同分按评价数降序。
- 销量优先：按 `qty_invoiced - qty_refunded` 汇总，排除已取消订单后降序。
- 最低/最高价格：按 `COALESCE(NULLIF(special_price, 0), price)` 排序。

## 验证

- `php -l AccessoryCatalogController.php`：通过。
- 独立测试库 `summon_mecha_step10_test` 专项测试：2 passed / 4 assertions。
- H5 导出：成功。
- 浏览器实测：下拉可展开，选择「销量优先」后头部文案与列表请求状态正确；运行时无 error/warning。
- Node 回归：37 passed。

## 回滚

改动前文件备份在 `.claude/backup/accessory-sort-dropdown-20261029/`。

## 补充：分类栏与筛选行顶部对齐

- 左侧分类栏上移 `80rpx`，保持完整高度，使「全部」与「机型 / 综合排序」同一中线。
- 浏览器实测中心线：筛选行 `183.20px`，首个分类项 `183.43px`，误差小于 `0.25px`。
- 排序下拉展开正常，未被主体容器裁切；运行时无 error/warning。
- 改动前备份：`.claude/backup/accessory-sidebar-align-20261029/`。

## 补充：综合排序紧邻机型

- 移除排序控件的 `margin-left: auto`，使其紧跟机型选择器。
- 浏览器实测两者间距为 `5.1px`（12rpx），顶部误差 `0.4px`；下拉展开与选项切换正常。
- 改动前备份：`.claude/backup/accessory-sort-next-to-model-20261029/`。
