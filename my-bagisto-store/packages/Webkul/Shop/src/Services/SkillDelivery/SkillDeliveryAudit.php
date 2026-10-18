<?php

namespace Webkul\Shop\Services\SkillDelivery;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Webkul\Category\Models\Category;
use Webkul\Shop\Models\PaymentTransaction;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\Ops\AdminAlertService;

/** Detect missing purchase facts; never invent an entitlement or a version. */
class SkillDeliveryAudit
{
    public function __construct(protected AdminAlertService $alerts) {}

    public function inspect(int $limit = 100, bool $advance = false): array
    {
        $limit = max(1, min(500, $limit));
        if (! $advance) {
            // Default CLI inspection is read-only, including the progress cache.
            return $this->inspectBatch($limit, [])[0];
        }
        $key = 'skill-delivery-audit:cursor:v1:'.sha1(DB::connection()->getDatabaseName());

        return Cache::lock($key.':lock', 60)->block(2, function () use ($key, $limit) {
            [$issues, $cursors] = $this->inspectBatch($limit, (array) Cache::get($key, []));
            // Progress is reconstructible scheduling state, never a sent marker
            // or a transaction fact. After a crash/failed notification, a later
            // full rotation revisits the same durable issue; cache flush starts
            // from zero and Notification deduplication still applies.
            Cache::put($key, $cursors, now()->addDays(7));

            return $issues;
        });
    }

    protected function inspectBatch(int $limit, array $cursors): array
    {
        $issues = [];
        // The callback may have persisted its paid row while the notification
        // insert failed. Re-inspect durable facts instead of trusting a log.
        // This flags a review condition; it does not infer real money receipt
        // or prove that a callback arrived after cancellation.
        $paymentQuery = DB::table('payment_transaction as p')->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('p.status', PaymentTransaction::STATUS_PAID)
            ->whereIn('o.status', ['canceled', 'closed', 'fraud']);
        [$payments, $cursors['payments']] = $this->nextBatch($paymentQuery, 'p.id', $limit,
            (int) ($cursors['payments'] ?? 0), ['p.id', 'p.order_id', 'p.gateway', 'o.status as order_status']);
        foreach ($payments as $payment) {
            $issues[] = ['reason_code'   => 'TERMINAL_ORDER_WITH_PAID_TRANSACTION',
                'payment_transaction_id' => $payment->id, 'order_id' => $payment->order_id,
                'order_status'           => $payment->order_status, 'gateway' => $payment->gateway];
        }
        $categoryId = Category::query()->whereHas('translations', fn ($query) => $query->where('slug', 'skill'))->value('id');
        if (! $categoryId) {
            $issues[] = ['reason_code' => 'SKILL_CATEGORY_MISSING'];
        } else {
            $itemQuery = DB::table('order_items as items')->join('orders', 'orders.id', '=', 'items.order_id')
                ->join('product_categories as pc', 'pc.product_id', '=', 'items.product_id')
                ->leftJoin('skill_entitlements as e', 'e.order_item_id', '=', 'items.id')
                ->where('pc.category_id', $categoryId)->whereNull('e.id')
                ->whereIn('orders.status', ['processing', 'completed'])->whereNotNull('orders.customer_id');
            [$items, $cursors['items']] = $this->nextBatch($itemQuery, 'items.id', $limit,
                (int) ($cursors['items'] ?? 0), ['items.id', 'items.order_id']);
            foreach ($items as $item) {
                $issues[] = ['reason_code' => 'ENTITLEMENT_MISSING', 'order_id' => $item->order_id, 'order_item_id' => $item->id];
            }
        }
        $entitlementQuery = SkillEntitlement::query()->where(function ($query) {
            $query->whereNotNull('meta->delivery_failed->class')
                ->orWhere('meta->delivery->ok', false)
                ->orWhereIn('meta->delivery_retry->state', ['manual', 'blocked', 'exhausted']);
        });
        [$entitlements, $cursors['entitlements']] = $this->nextBatch($entitlementQuery, 'id', $limit,
            (int) ($cursors['entitlements'] ?? 0));
        foreach ($entitlements as $entitlement) {
            $meta = (array) $entitlement->meta;
            $retry = (array) ($meta['delivery_retry'] ?? []);
            $issues[] = ['reason_code' => $entitlement->delivery_channel === 'app'
                ? ($retry['reason_code'] ?? 'APP_EXCEPTION_REQUIRES_INSPECTION') : 'CHANNEL_OUT_OF_SCOPE',
                'order_id'       => $entitlement->order_id, 'order_item_id' => $entitlement->order_item_id,
                'entitlement_id' => $entitlement->id, 'attempts' => (int) ($retry['attempts'] ?? 0),
                'cycle_id'       => $retry['cycle_id'] ?? 'legacy',
                'channel'        => $entitlement->delivery_channel, 'state' => $retry['state'] ?? 'untracked'];
        }

        return [$issues, $cursors];
    }

    /** Each category has an independent cursor and eventually wraps to zero. */
    protected function nextBatch($query, string $column, int $limit, int $cursor, array $columns = ['*']): array
    {
        $rows = (clone $query)->where($column, '>', max(0, $cursor))->orderBy($column)->limit($limit)->get($columns);
        if ($rows->isEmpty() && $cursor > 0) {
            $rows = (clone $query)->orderBy($column)->limit($limit)->get($columns);
        }

        return [$rows, $rows->count() < $limit ? 0 : (int) $rows->last()->id];
    }

    public function notify(array $issues): int
    {
        $sent = 0;
        foreach ($issues as $issue) {
            if (isset($issue['payment_transaction_id'])) {
                // Same order lock and dedup key as PaymentCallbackController.
                // A failed notification insert leaves no sent marker, so the
                // next inspection attempts delivery again.
                $sent += DB::transaction(function () use ($issue) {
                    $order = DB::table('orders')->where('id', $issue['order_id'])->lockForUpdate()->first();
                    if (! $order || ! in_array($order->status, ['canceled', 'closed', 'fraud'], true)) {
                        return 0;
                    }

                    return $this->alerts->alert('payment.review_required', '支付成功但订单状态禁止推进',
                        '订单 '.$order->id.' 状态为 '.$order->status.'，成功流水需人工核查（本地 mock 不代表真实到账）。未恢复订单、未交付、未自动退款。',
                        'payment-order-state:'.$issue['payment_transaction_id'], $issue, AdminAlertService::LEVEL_URGENT);
                });

                continue;
            }
            // Pending retries are diagnostics, not terminal operator alarms.
            if (($issue['channel'] ?? '') === 'app' && in_array($issue['state'] ?? '', ['pending', 'running', 'waiting'], true)) {
                continue;
            }
            $key = isset($issue['entitlement_id'])
                ? 'skill-delivery:'.$issue['entitlement_id'].':'.($issue['cycle_id'] ?? 'legacy')
                : 'skill-inspection:'.$issue['reason_code'].':'.($issue['order_item_id'] ?? 'config');
            $sent += $this->alerts->alert('skill_delivery', '技能交付巡检需人工核查',
                '原因：'.$issue['reason_code'].'。巡检不会自动创建授权、猜测购买版本或创建 Agent 下发任务。',
                $key, $issue, AdminAlertService::LEVEL_WARN);
        }

        return $sent;
    }
}
