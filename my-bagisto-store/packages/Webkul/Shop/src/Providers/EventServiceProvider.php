<?php

namespace Webkul\Shop\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Webkul\Shop\Listeners\Customer;
use Webkul\Shop\Listeners\GDPR;
use Webkul\Shop\Listeners\Invoice;
use Webkul\Shop\Listeners\Order;
use Webkul\Shop\Listeners\Refund;
use Webkul\Shop\Listeners\Shipment;
use Webkul\Shop\Listeners\SkillDelivery;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        /**
         * Customer related events.
         */
        'customer.registration.after' => [
            [Customer::class, 'afterCreated'],
        ],

        'customer.password.update.after' => [
            [Customer::class, 'afterPasswordUpdated'],
        ],

        'customer.subscription.after' => [
            [Customer::class, 'afterSubscribed'],
        ],

        'customer.note.create.after' => [
            [Customer::class, 'afterNoteCreated'],
        ],

        /**
         * GDPR related events.
         */
        'customer.account.gdpr-request.create.after' => [
            [GDPR::class, 'afterGdprRequestCreated'],
        ],

        'customer.account.gdpr-request.update.after' => [
            [GDPR::class, 'afterGdprRequestUpdated'],
        ],

        /**
         * Sales related events.
         */
        'checkout.order.save.after' => [
            [Order::class, 'afterCreated'],

            // 技能交付:订单落库后自动产生授权。
            // ⚠ 该事件在建单事务内、DB::commit() 之前触发,监听器必须吞异常且幂等,
            //   否则 OrderRepository 的重试逻辑会重复建单 —— 详见 Listeners/SkillDelivery.php
            [SkillDelivery::class, 'afterOrderCreated'],
        ],

        'sales.order.cancel.after' => [
            [Order::class, 'afterCanceled'],

            // 订单取消 → 对称撤销技能授权
            [SkillDelivery::class, 'afterOrderCanceled'],
        ],

        'sales.order.comment.create.after' => [
            [Order::class, 'afterCommented'],
        ],

        'sales.invoice.save.after' => [
            [Invoice::class, 'afterCreated'],
        ],

        'sales.invoice.send_duplicate_email' => [
            [Invoice::class, 'afterCreated'],
        ],

        'sales.shipment.save.after' => [
            [Shipment::class, 'afterCreated'],
        ],

        'sales.refund.save.after' => [
            [Refund::class, 'afterCreated'],
        ],
    ];
}
