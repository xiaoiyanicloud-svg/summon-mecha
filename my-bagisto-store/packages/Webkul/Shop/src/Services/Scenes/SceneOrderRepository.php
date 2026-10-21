<?php

namespace Webkul\Shop\Services\Scenes;

/** 只覆盖场景单编号；建单、库存与事件仍执行既有仓储事务。 */
class SceneOrderRepository extends \Webkul\Sales\Repositories\OrderRepository
{
    public function generateIncrementId()
    {
        // 避免并发普通订单与场景拆单共享 lastId+1 导致编号碰撞。
        return 'SC'.strtoupper(bin2hex(random_bytes(12)));
    }
}
