<?php

/*
|--------------------------------------------------------------------------
| 生态 outbox 注册表（ADR-0001 G1-D07）
|--------------------------------------------------------------------------
|
| 这是 `ecosystem_outbox.aggregate_type` / `event_type` 的**单一事实源**。
| `Services\Ecosystem\OutboxWriter` 在落库前按本表校验；不在表内的类型直接拒绝。
|
| G1 冻结范围：
|   - 聚合只有 event / content；
|   - 事件只有发布、暂缓、撤稿三类 × 两个域。
|
| ⚠ 报名相关事件（registration.*）属于 G2-12，**不得**在此提前登记 ——
|   登记等于允许写入，而消费侧尚未准备。
|
*/
return [
    'aggregate_types' => [
        'event',
        'content',
    ],

    'event_types' => [
        'event.published',
        'event.withheld',
        'event.retracted',
        'content.published',
        'content.withheld',
        'content.retracted',
    ],
];
