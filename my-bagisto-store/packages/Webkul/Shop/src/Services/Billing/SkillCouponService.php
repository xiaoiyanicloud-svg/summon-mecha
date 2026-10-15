<?php

namespace Webkul\Shop\Services\Billing;

use Illuminate\Support\Carbon;
use Webkul\CartRule\Models\CartRule;
use Webkul\CartRule\Models\CartRuleCoupon;
use Webkul\CartRule\Models\CartRuleCouponUsage;
use Webkul\CartRule\Models\CartRuleCustomer;

/**
 * 技能单用券 —— B-18(计划 §4.18「优惠券」)。
 *
 * ── 为什么需要单独一层 ──
 *   Bagisto 原生用券链路(`Webkul\CartRule\Helpers\CartRule`)整体绑在**购物车**上:
 *   `collect($cart)` 遍历 `$cart->items`、`canProcessRule()` 读 `$this->cart->coupon_code`。
 *   而技能单走 `POST /api/tob-order` → `OrderRepository::create()` **不经购物车**,
 *   于是那张券空有一堆数据、却没有任何入口能让它作用到技能单。
 *
 * ── 本层刻意**不新造**的东西 ──
 *   · 券/规则仍用原生 `cart_rules` / `cart_rule_coupons` / `cart_rule_customer*` 表与模型,
 *     不引入任何新表、新模型、新后台 CRUD;
 *   · 用量(次数字段 `times_used`、按客记录)仍由**原生** `CartRule\Listeners\Order@manageCartRule`
 *     记账 —— 该监听挂在 `checkout.order.save.after`,而 `OrderRepository::create()` 会派发它。
 *     所以本服务**只算折抵、不写用量**;下单成功后原生监听自会 +1(前提:order 上有
 *     `discount_amount` + `applied_cart_rule_ids` / `coupon_code`,见 TobOrderController)。
 *
 * ── 诚实边界(不假装支持的,一律明确拒绝,绝不静默给折扣) ──
 *   · 带 `conditions`(按商品/类目/小计等限制)的规则**本期不评估** —— 评估它需要购物车上下文
 *     (原生 `Validator` 对 `cart|` 条件依赖 `$entity->cart`)。与其按错误口径放行,不如明说"暂不支持"。
 *   · `buy_x_get_y`(买赠)对技能单无意义(技能是虚拟商品),不支持。
 *   支持的 `action_type` 见 {@see self::SUPPORTED_ACTIONS}。
 */
class SkillCouponService
{
    /**
     * 技能单支持的优惠类型 —— 与原生 `CartRule::process()` 的 switch 取值同源。
     * 不在此列的(buy_x_get_y)会被解释为"暂不支持",而不是被忽略后当没折扣。
     */
    public const SUPPORTED_ACTIONS = ['by_percent', 'by_fixed', 'cart_fixed'];

    /**
     * 校验一张券能否用于本笔技能单,并算出折抵金额(按 base 币种)。
     *
     * 校验口径与原生 `CartRule::canProcessRule()` + `getCartRuleQuery()` 对齐:
     *   券存在 / 规则启用 / 时间窗 / 渠道 / 客群 / 券总量 / 券按客量 / 规则按客量。
     *
     * @return array{ok:bool, message:string, rule:?CartRule, coupon:?CartRuleCoupon, discount:float}
     */
    public function resolve(
        string $code,
        ?int $customerId,
        ?int $customerGroupId,
        int $channelId,
        float $subtotal,
        int $totalQty,
    ): array {
        $code = trim($code);
        if ($code === '') {
            return $this->fail('请输入优惠码。');
        }

        $coupon = CartRuleCoupon::query()->where('code', $code)->first();
        if (! $coupon) {
            return $this->fail('优惠码无效。');
        }

        /** @var ?CartRule $rule */
        $rule = $coupon->cart_rule;
        if (! $rule) {
            return $this->fail('优惠码无效。');
        }

        if (! $rule->status) {
            return $this->fail('该优惠码已停用。');
        }

        $now = Carbon::now();

        if ($rule->starts_from && $now->lt(Carbon::parse($rule->starts_from))) {
            return $this->fail('优惠码尚未生效。');
        }

        if ($rule->ends_till && $now->gt(Carbon::parse($rule->ends_till))) {
            return $this->fail('优惠码已过期。');
        }

        if ($coupon->expired_at && $now->gt(Carbon::parse($coupon->expired_at))) {
            return $this->fail('优惠码已过期。');
        }

        // 渠道:原生 getCartRuleQuery() 用 inner join cart_rule_channels 限定,这里同义
        if (! $rule->cart_rule_channels()->where('channels.id', $channelId)->exists()) {
            return $this->fail('该优惠码不适用于当前渠道。');
        }

        // 客群:同理。未登录(无客群)不在技能单场景(技能单强制登录),但仍如实判空
        if ($customerGroupId && ! $rule->cart_rule_customer_groups()->where('customer_groups.id', $customerGroupId)->exists()) {
            return $this->fail('该优惠码不适用于你的账号。');
        }

        if ($coupon->usage_limit && $coupon->times_used >= $coupon->usage_limit) {
            return $this->fail('该优惠码已被领完。');
        }

        if ($customerId && $coupon->usage_per_customer) {
            $used = (int) CartRuleCouponUsage::query()
                ->where('cart_rule_coupon_id', $coupon->id)
                ->where('customer_id', $customerId)
                ->value('times_used');

            if ($used >= $coupon->usage_per_customer) {
                return $this->fail('你已使用过该优惠码。');
            }
        }

        if ($customerId && $rule->usage_per_customer) {
            $used = (int) CartRuleCustomer::query()
                ->where('cart_rule_id', $rule->id)
                ->where('customer_id', $customerId)
                ->value('times_used');

            if ($used >= $rule->usage_per_customer) {
                return $this->fail('你已使用过该优惠。');
            }
        }

        // 诚实拒绝:见类注释。有条件限制就不放行,而不是按忽略条件后的口径给折扣。
        if (! empty($rule->conditions)) {
            return $this->fail('该优惠码含暂不支持的条件限制,无法用于技能单。');
        }

        if (! in_array($rule->action_type, self::SUPPORTED_ACTIONS, true)) {
            return $this->fail('该优惠码的优惠类型暂不支持技能单。');
        }

        $discount = min($subtotal, $this->computeDiscount($rule, $subtotal, $totalQty));

        if ($discount <= 0) {
            return $this->fail('该优惠码对本单无可折抵金额。');
        }

        return [
            'ok'       => true,
            'message'  => '',
            'rule'     => $rule,
            'coupon'   => $coupon,
            'discount' => round($discount, 4),
        ];
    }

    /**
     * 折抵金额 —— 与原生 `CartRule::process()` 的算法逐一对齐(订单级汇总版)。
     *
     * 原生是**逐条目**算再累加;技能单这里按整单算,单条目时二者等价,
     * 多条目时 `by_fixed` 用总数量、`cart_fixed` 用整单小计 —— 见测试的公式锁。
     */
    protected function computeDiscount(CartRule $rule, float $subtotal, int $totalQty): float
    {
        switch ($rule->action_type) {
            case 'by_percent':
                $percent = min(100, (float) $rule->discount_amount);

                return $subtotal * ($percent / 100);

            case 'by_fixed':
                // 每件减固定额;discount_quantity 为"最多折抵件数"(原生同义,默认 1)
                $qty = $rule->discount_quantity
                    ? min($totalQty, (int) $rule->discount_quantity)
                    : $totalQty;

                return (float) $rule->discount_amount * $qty;

            case 'cart_fixed':
                // 整单立减固定额(封顶为小计)
                return (float) $rule->discount_amount;

            default:
                return 0.0;
        }
    }

    /**
     * @return array{ok:false, message:string, rule:null, coupon:null, discount:float}
     */
    protected function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'rule' => null, 'coupon' => null, 'discount' => 0.0];
    }
}
