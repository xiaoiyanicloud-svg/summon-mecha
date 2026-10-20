<?php

namespace Webkul\Shop\Services\Scenes;

/** 没有通过独立 MySQL 事务故障注入前，禁止调用会嵌套提交/重试的订单仓储。 */
class SceneOrderConversionService
{
    public function convert(): never
    {
        throw new SceneWorkflowException('SCENE_ORDER_CONVERSION_NOT_VERIFIED', '报价接受已记录；订单和交付确认尚待事务验收，请由商务继续处理，当前不会创建订单。', 409);
    }
}
