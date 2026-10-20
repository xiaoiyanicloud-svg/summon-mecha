# 订单仓储事务修复与验收边界

日期：2026-10-20。SC3-05 的代码前置修复；独立 MySQL 和完整 Bagisto 监听器验收仍未完成，不能开放场景订单转换。

## 复现问题

原 `OrderRepository::createOrderIfNotThenRetry()` 捕获 `Exception` 后回滚并递归重试，随后每层 `finally` 都调用 `DB::commit()`：

- 嵌套调用失败时可能提交调用方仍需回滚的外层事务。
- `Error` 等 `Throwable` 不被捕获，执行 `finally` 后可能保留订单头、支付、地址或订单项等部分记录。
- 普通业务或监听器错误也重试三次，造成没有必要的重复执行。

通过真实仓储 `create()` 方法复现，不是复制一段事务代码另行测试。SQL 依赖使用专门夹具：订单头/支付/地址为 Eloquent 关系，订单项、库存、自定义选项、下载及监听器依赖写入内存 SQL 表，可在每个位置注入 `RuntimeException` 或 `Error`。

旧实现基线为 `546a6a0` 中的仓储代码；修复前同一组 30 项中 4 项通过、26 项失败，见 [order-before.json](sc1-sc5-batch2-evidence/order-before.json)。

## 修复

沿用 Laravel 的 `DB::transaction()`，由框架负责 savepoint、`Throwable` 回滚与提交。顶层仅对框架识别的数据库并发错误最多尝试三次；嵌套仓储不自行重试，失败向外层事务传播。删除递归和 `finally` 无条件提交。错误日志只记录异常类及重试上限，不输出 SQL、原始异常或客资。

保留既有事件名称、触发位置、订单状态和订单行处理顺序。`SkillDelivery` 仅更新注释说明；其既有异常补偿和授权语义没有改变。

## 实测

```powershell
php -d extension=pdo_sqlite tools/tests/order-transaction.php
```

修复后 30/30 通过，见 [order-after.json](sc1-sc5-batch2-evidence/order-after.json)。覆盖 13 个注入位置的两种异常、嵌套失败后外层继续写入/回滚、嵌套成功后由外层决定提交、afterCommit 不提前执行及回滚不触发、顶层并发错误有界重试和超限无残留。

这里的并发异常由夹具注入，验证框架重试分支，不是真实 MySQL 死锁。没有加载 `.env` 或业务 bootstrap，数据库硬断言为 SQLite `:memory:`；不会写入本机或另一台电脑的业务库。

## 未做与回退

未验证：MySQL 多连接/真实死锁/锁超时；真实订单号生成；完整库存、优惠券、发票、通知和技能授权监听器；监听器外部副作用；真实订单行/SKU 映射；外层业务审计/outbox/资源/项目同事务故障注入。回调在实际提交后抛异常的处理也需要完整应用演练，不能根据本夹具认定业务已回滚。

因此 `SceneOrderConversionService` 仍硬阻断，`order_conversion_available` 仍为 false。修复共享订单仓储本身不等于 SC3-05/06 已签收。

本批修改前的仓储及监听器已备份在 `.claude/backup/scene-sc1-sc5-batch2-20261020/` 对应相对路径。未部署时可按清单恢复这两个文件；已部署时优先停用场景写入口并保留业务记录，不执行数据库 down 或清库。合并另一台电脑时单独审查此共享仓储变更，并运行其既有普通租买/技能/赛事订单回归。
