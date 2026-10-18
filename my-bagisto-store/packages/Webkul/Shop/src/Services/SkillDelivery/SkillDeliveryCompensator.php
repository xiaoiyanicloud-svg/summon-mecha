<?php

namespace Webkul\Shop\Services\SkillDelivery;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Webkul\Category\Models\Category;
use Webkul\Product\Models\ProductProxy;
use Webkul\Sales\Models\OrderItemProxy;
use Webkul\Sales\Models\OrderProxy;
use Webkul\Shop\Jobs\RetrySkillDelivery;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Models\PaymentTransaction;
use Webkul\Shop\Models\SkillBlocklist;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\Deploy\KillSwitchService;
use Webkul\Shop\Services\Ops\AdminAlertService;
use Webkul\Shop\Services\Payment\PaymentManager;

/**
 * Bounded recovery of existing app entitlements. Never creates an entitlement,
 * changes a purchase version, or submits a device deployment task.
 *
 * meta.delivery_retry is the only business budget. The queue is only a wake-up
 * mechanism: duplicate delivery, scanner and CLI all claim this same row.
 */
class SkillDeliveryCompensator
{
    private const TERMINAL = ['succeeded', 'blocked', 'manual', 'exhausted'];

    public function __construct(
        protected SkillDeliveryManager $manager,
        protected KillSwitchService $kills,
        protected AdminAlertService $alerts,
    ) {}

    public function executionAllowed(): bool
    {
        // The verified lock protocol and a deployment opt-in are both required.
        // Unvalidated deployments must keep the execution opt-in disabled.
        return config('skill_delivery.retry.execution_enabled') === true
            && config('skill_delivery.retry.safety_verified') === true;
    }

    public function hasFailureState(SkillEntitlement $entitlement): bool
    {
        $meta = (array) $entitlement->meta;
        if (($meta['delivery_retry']['state'] ?? null) === 'succeeded'
            && ! $this->hasException($meta) && ($meta['delivery']['ok'] ?? null) !== false) {
            return false;
        }

        return is_array($meta['delivery_retry'] ?? null)
            || $this->hasException($meta)
            || ($meta['delivery']['ok'] ?? null) === false;
    }

    /** Called inside the listener's existing transaction; never resets a cycle. */
    public function recordFailure(int $entitlementId, Throwable $error): void
    {
        $this->locked($entitlementId, function ($entitlement) use ($error) {
            if (! $entitlement) {
                return;
            }

            $meta = (array) $entitlement->meta;
            $meta['delivery_failed'] = [
                'at'      => now()->toDateTimeString(),
                'class'   => $error::class,
                'channel' => preg_match('/^[a-zA-Z0-9:_-]{1,24}$/', (string) $entitlement->delivery_channel)
                    ? $entitlement->delivery_channel : 'invalid-channel',
                // Exception text can contain credentials/SQL. Keep the public
                // diagnostic deliberately bounded; report($error) owns detail.
                'message' => '技术异常；请按异常类型及服务端日志核查。',
            ];
            if ($entitlement->delivery_channel === 'app') {
                $meta['delivery_retry'] ??= $this->newLedger();
                if ($error instanceof \InvalidArgumentException) {
                    $meta['delivery_retry']['state'] = 'manual';
                    $meta['delivery_retry']['reason_code'] = 'CHANNEL_CONFIGURATION';
                }
            }
            $entitlement->meta = $meta;
            $entitlement->save();
        });
    }

    /** Read-only: neither bootstrap a ledger nor consume the retry budget. */
    public function inspect(int $entitlementId): array
    {
        $entitlement = SkillEntitlement::find($entitlementId);
        if (! $entitlement) {
            return $this->answer('manual', 'ENTITLEMENT_MISSING', $entitlementId);
        }

        $guard = $this->guard($entitlement);
        $retry = (array) (((array) $entitlement->meta)['delivery_retry'] ?? []);
        if ($guard['state'] === 'ready' && in_array($retry['state'] ?? '', self::TERMINAL, true)) {
            $guard = ['state' => $retry['state'], 'reason_code' => $retry['reason_code'] ?? 'TERMINAL'];
        }
        $answer = $this->answer($guard['state'], $guard['reason_code'], $entitlementId, $retry);
        $answer['execution_enabled'] = $this->executionAllowed();

        return $answer;
    }

    /** Recovery entry shared by Job and explicit CLI execution. */
    public function execute(int $entitlementId): array
    {
        if (! $this->executionAllowed()) {
            return $this->answer('disabled', 'EXECUTION_NOT_VERIFIED', $entitlementId);
        }

        $claim = $this->locked($entitlementId, function ($entitlement) use ($entitlementId) {
            if (! $entitlement) {
                return $this->answer('manual', 'ENTITLEMENT_MISSING', $entitlementId);
            }
            $meta = (array) $entitlement->meta;
            $retry = $meta['delivery_retry'] ?? $this->newLedger();
            $guard = $this->guard($entitlement, true);
            if (! isset($meta['delivery_retry'])
                && ($entitlement->delivery_channel !== 'app' || ! $this->hasException($meta))) {
                return $this->answer($guard['state'], $guard['reason_code'], $entitlementId);
            }
            if ($guard['state'] !== 'ready') {
                return $this->settle($entitlement, $retry, $guard);
            }
            if (in_array($retry['state'] ?? '', self::TERMINAL, true)) {
                return $this->answer($retry['state'], $retry['reason_code'] ?? 'TERMINAL', $entitlementId, $retry);
            }
            if (($retry['lease_until'] ?? 0) > now()->timestamp) {
                return $this->answer('running', 'LEASE_BUSY', $entitlementId, $retry);
            }
            if ((int) ($retry['attempts'] ?? 0) >= $this->budget()) {
                return $this->settle($entitlement, $retry, ['state' => 'exhausted', 'reason_code' => 'RETRY_EXHAUSTED']);
            }
            if (($retry['next_retry_at'] ?? 0) > now()->timestamp) {
                return $this->answer('waiting', 'BACKOFF', $entitlementId, $retry);
            }

            $retry['attempts'] = (int) ($retry['attempts'] ?? 0) + 1;
            $retry['state'] = 'running';
            $retry['lease_token'] = (string) Str::uuid();
            $retry['lease_until'] = now()->timestamp + max(90, (int) config('skill_delivery.retry.lease_seconds', 120));
            $retry['dispatch_until'] = null;
            $retry['dispatch_token'] = null;
            $this->writeLedger($entitlement, $retry);

            return ['token' => $retry['lease_token']];
        });

        if (! isset($claim['token'])) {
            return $claim;
        }

        // The claim is durable before work starts. A killed process consumes its
        // attempt; a later lease holder cannot receive a fresh three attempts.
        try {
            return $this->locked($entitlementId, function ($entitlement) use ($claim, $entitlementId) {
                if (! $entitlement) {
                    return $this->answer('manual', 'ENTITLEMENT_MISSING', $entitlementId);
                }
                $retry = (array) (((array) $entitlement->meta)['delivery_retry'] ?? []);
                if (! $this->owns($retry, $claim['token'])) {
                    return $this->answer('stale', 'LEASE_LOST', $entitlementId, $retry);
                }
                if (! $this->executionAllowed()) {
                    return $this->settle($entitlement, $retry, ['state' => 'waiting', 'reason_code' => 'EXECUTION_NOT_VERIFIED']);
                }
                $guard = $this->guard($entitlement, true);
                if ($guard['state'] !== 'ready') {
                    return $this->settle($entitlement, $retry, $guard);
                }

                $result = $this->manager->channel('app')->deliver($entitlement, ['device_id' => $entitlement->device_id]);
                // AppChannel currently changes only local columns, while this
                // transaction holds the entitlement lock through result write.
                $entitlement = SkillEntitlement::query()->whereKey($entitlementId)->lockForUpdate()->firstOrFail();
                $meta = (array) $entitlement->meta;
                $current = (array) ($meta['delivery_retry'] ?? []);
                if (! $this->owns($current, $claim['token'])) {
                    throw new \RuntimeException('Compensation lease changed during local delivery.');
                }
                $meta['delivery'] = $result->toArray();
                if ($result->ok) {
                    $meta['delivery_failed'] = null;
                }
                $entitlement->meta = $meta;
                $entitlement->save();

                return $this->settle($entitlement, $current, [
                    'state'       => $result->ok ? 'succeeded' : 'manual',
                    'reason_code' => $result->ok ? 'APP_DELIVERED' : 'DELIVERY_REJECTED',
                ]);
            });
        } catch (Throwable $error) {
            report($error);

            return $this->locked($entitlementId, function ($entitlement) use ($claim, $error, $entitlementId) {
                if (! $entitlement) {
                    return $this->answer('manual', 'ENTITLEMENT_MISSING', $entitlementId);
                }
                $meta = (array) $entitlement->meta;
                $retry = (array) ($meta['delivery_retry'] ?? []);
                if (! $this->owns($retry, $claim['token'])) {
                    return $this->answer('stale', 'LEASE_LOST', $entitlementId, $retry);
                }
                $meta['delivery_failed'] = ['at' => now()->toDateTimeString(), 'class' => $error::class,
                    'channel'                    => 'app', 'message' => '补偿技术异常，请检查服务端日志。'];
                $entitlement->meta = $meta;
                $entitlement->save();
                $exhausted = $retry['attempts'] >= $this->budget();
                $retry['next_retry_at'] = now()->timestamp + $this->delay((int) $retry['attempts']);

                return $this->settle($entitlement, $retry, [
                    'state'       => $error instanceof \InvalidArgumentException ? 'manual' : ($exhausted ? 'exhausted' : 'pending'),
                    'reason_code' => $error instanceof \InvalidArgumentException ? 'CHANNEL_CONFIGURATION' : ($exhausted ? 'RETRY_EXHAUSTED' : 'TECHNICAL_EXCEPTION'),
                ]);
            });
        }
    }

    /** Bounded query; ledger bootstrap also handles pre-feature failure markers. */
    public function dispatchDue(int $limit = 100): array
    {
        $summary = ['checked' => 0, 'dispatched' => 0, 'execution_enabled' => $this->executionAllowed()];
        $ids = SkillEntitlement::query()->where('delivery_channel', 'app')
            ->whereNotNull('meta->delivery_failed->class')
            ->where(function ($query) {
                $query->whereNull('meta->delivery_retry->state')
                    ->orWhereNotIn('meta->delivery_retry->state', self::TERMINAL);
            })
            ->where(function ($query) {
                $query->whereNull('meta->delivery_retry->next_retry_at')->orWhere('meta->delivery_retry->next_retry_at', '<=', now()->timestamp);
            })
            ->where(function ($query) {
                $query->whereNull('meta->delivery_retry->lease_until')->orWhere('meta->delivery_retry->lease_until', '<=', now()->timestamp);
            })
            ->where(function ($query) {
                $query->whereNull('meta->delivery_retry->dispatch_until')->orWhere('meta->delivery_retry->dispatch_until', '<=', now()->timestamp);
            })
            ->orderByRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.delivery_retry.next_retry_at')), '0') + 0")
            ->orderBy('id')->limit(max(1, min(500, $limit)))->pluck('id');
        foreach ($ids as $id) {
            $summary['checked']++;
            try {
                $summary['dispatched'] += (int) $this->dispatchOne((int) $id);
            } catch (Throwable $error) {
                // A marker expires if dispatch itself failed. No budget is lost.
                Log::error('Skill compensation scan failed', ['entitlement_id' => $id, 'class' => $error::class]);
                report($error);
            }
        }

        return $summary;
    }

    protected function dispatchOne(int $entitlementId): bool
    {
        return $this->locked($entitlementId, function ($entitlement) {
            if (! $entitlement) {
                return false;
            }
            $retry = ((array) $entitlement->meta)['delivery_retry'] ?? $this->newLedger();
            $guard = $this->guard($entitlement, true);
            if ($guard['state'] !== 'ready') {
                $this->settle($entitlement, $retry, $guard);

                return false;
            }
            if (in_array($retry['state'] ?? '', self::TERMINAL, true)) {
                return false;
            }
            $this->writeLedger($entitlement, $retry);
            if (! $this->executionAllowed()) {
                $retry['next_retry_at'] = now()->timestamp + 60;
                $this->writeLedger($entitlement, $retry);

                return false;
            }
            if (($retry['next_retry_at'] ?? 0) > now()->timestamp
                || ($retry['lease_until'] ?? 0) > now()->timestamp
                || ($retry['dispatch_until'] ?? 0) > now()->timestamp) {
                return false;
            }
            if ((int) ($retry['attempts'] ?? 0) >= $this->budget()) {
                $this->settle($entitlement, $retry, ['state' => 'exhausted', 'reason_code' => 'RETRY_EXHAUSTED']);

                return false;
            }
            $retry['dispatch_token'] = (string) Str::uuid();
            $retry['dispatch_until'] = now()->timestamp + max(90, (int) config('skill_delivery.retry.dispatch_seconds', 300));
            $this->writeLedger($entitlement, $retry);
            RetrySkillDelivery::dispatch((int) $entitlement->id)->afterCommit();

            return true;
        });
    }

    protected function guard(SkillEntitlement $entitlement, bool $current = false): array
    {
        $meta = (array) $entitlement->meta;
        if ($entitlement->delivery_channel !== 'app') {
            return ['state' => 'manual', 'reason_code' => 'CHANNEL_OUT_OF_SCOPE'];
        }
        if ($entitlement->isTrial()) {
            return ['state' => 'manual', 'reason_code' => 'TRIAL_OUT_OF_SCOPE'];
        }
        if ($current ? ! $entitlement->getRelation('product') : ! $entitlement->product()->exists()) {
            return ['state' => 'manual', 'reason_code' => 'PRODUCT_MISSING'];
        }
        if (! $entitlement->isUsable()) {
            return ['state' => 'blocked', 'reason_code' => 'ENTITLEMENT_UNUSABLE'];
        }
        if ($entitlement->status === SkillEntitlement::STATUS_ACTIVE || $entitlement->granted_at || ($meta['delivery']['ok'] ?? false) === true) {
            return ['state' => 'succeeded', 'reason_code' => 'ALREADY_DELIVERED'];
        }
        if (! $this->hasException($meta)) {
            return ['state' => 'manual', 'reason_code' => 'NO_TECHNICAL_FAILURE'];
        }
        if (is_a($meta['delivery_failed']['class'], \InvalidArgumentException::class, true)) {
            return ['state' => 'manual', 'reason_code' => 'CHANNEL_CONFIGURATION'];
        }
        $categoryId = $current
            ? DB::table('category_translations')->where('slug', 'skill')->lockForUpdate()->first()?->category_id
            : Category::query()->whereHas('translations', fn ($query) => $query->where('slug', 'skill'))->value('id');
        $membership = DB::table('product_categories')->where('category_id', $categoryId)->where('product_id', $entitlement->product_id);
        if ($current) {
            $membership->lockForUpdate();
        }
        if (! $categoryId || ! $membership->first()) {
            return ['state' => 'manual', 'reason_code' => 'SKILL_CLASSIFICATION_MISSING'];
        }
        // A preliminary identity lookup can establish a REPEATABLE READ
        // snapshot. Mutation guards MUST use the current lock-read relations,
        // not issue fresh non-locking relation queries against that snapshot.
        $order = $current ? $entitlement->getRelation('order') : $entitlement->order()->first();
        $item = $current ? $entitlement->getRelation('orderItem') : $entitlement->orderItem()->first();
        if (! $order || ! $item || (int) $item->order_id !== (int) $order->id
            || (int) $item->product_id !== (int) $entitlement->product_id
            || ! $entitlement->customer_id || (int) $order->customer_id !== (int) $entitlement->customer_id) {
            return ['state' => 'manual', 'reason_code' => 'PURCHASE_IDENTITY_MISMATCH'];
        }
        if (in_array($order->status, ['pending', 'pending_payment'], true)) {
            return ['state' => 'waiting', 'reason_code' => 'WAITING_PAYMENT'];
        }
        if (! in_array($order->status, ['processing', 'completed'], true)) {
            return ['state' => 'blocked', 'reason_code' => 'ORDER_TERMINAL'];
        }
        $paymentQuery = PaymentTransaction::query()->where('order_id', $order->id)->where('status', PaymentTransaction::STATUS_PAID);
        if ($current) {
            $paymentQuery->lockForUpdate();
        }
        $payments = $paymentQuery->get();
        $payment = $payments->first(fn ($payment) => $payment->paid_at !== null
            && $payment->out_trade_no && $payment->transaction_id
            && abs((float) $payment->amount - (float) $order->grand_total) < 0.005);
        if (! $payment) {
            return ['state' => 'manual', 'reason_code' => 'PAID_TRANSACTION_MISSING'];
        }
        try {
            app(PaymentManager::class)->gateway($payment->gateway);
        } catch (Throwable $error) {
            return ['state' => 'manual', 'reason_code' => 'PAYMENT_GATEWAY_UNSAFE'];
        }
        $refundQuery = DB::table('refund_items')->where('order_item_id', $item->id);
        $afterSaleQuery = AfterSale::query()->where(function ($query) use ($entitlement, $item, $order) {
            $query->where('entitlement_id', $entitlement->id)->orWhere('order_item_id', $item->id)->orWhere('order_id', $order->id);
        });
        if ($current) {
            $refundQuery->lockForUpdate();
            $afterSaleQuery->lockForUpdate();
        }
        if ((float) $item->qty_refunded > 0 || (float) $item->qty_canceled > 0
            || $refundQuery->first() || (float) $order->grand_total_refunded > 0 || $afterSaleQuery->first()) {
            return ['state' => 'manual', 'reason_code' => 'AFTER_SALE_OR_REFUND'];
        }
        // Recovery is deliberately blocked by ANY active kill-switch intent.
        // The caller holds the product mutex shared by engage/lift. Native
        // refunds and after-sale writers share the preceding order mutex.
        // Current lock reads prevent a pre-lock RR snapshot hiding the intent;
        // future alternate writers must preserve this same protocol.
        $blocked = $current
            ? SkillBlocklist::query()->where('skill_id', $entitlement->product_id)
                ->where('status', SkillBlocklist::STATUS_ACTIVE)->lockForUpdate()->first() !== null
            : $this->kills->isBlockedFor((int) $entitlement->product_id, 'sale')
                || $this->kills->isBlockedFor((int) $entitlement->product_id, 'download');
        if ($blocked) {
            return ['state' => 'blocked', 'reason_code' => 'SKILL_BLOCKED'];
        }
        if (! config('skill_delivery.channels.app.class') || ! is_a(config('skill_delivery.channels.app.class'), DeliveryChannel::class, true)) {
            return ['state' => 'manual', 'reason_code' => 'CHANNEL_CONFIGURATION'];
        }

        return ['state' => 'ready', 'reason_code' => 'ELIGIBLE_APP_EXCEPTION'];
    }

    protected function hasException(array $meta): bool
    {
        $failure = $meta['delivery_failed'] ?? null;

        return is_array($failure) && is_string($failure['class'] ?? null)
            && is_a($failure['class'], Throwable::class, true);
    }

    protected function newLedger(): array
    {
        return ['cycle_id'  => (string) Str::ulid(), 'state' => 'pending', 'attempts' => 0,
            'next_retry_at' => now()->timestamp + $this->delay(0), 'lease_token' => null,
            'lease_until'   => null, 'dispatch_token' => null, 'dispatch_until' => null,
            'reason_code'   => 'TECHNICAL_EXCEPTION'];
    }

    protected function budget(): int
    {
        return min(3, max(1, (int) config('skill_delivery.retry.max_attempts', 3)));
    }

    protected function delay(int $attempt): int
    {
        $delays = (array) config('skill_delivery.retry.backoff_seconds', [60, 300, 900]);

        return max(1, (int) ($delays[$attempt] ?? 900));
    }

    protected function owns(array $retry, string $token): bool
    {
        return ($retry['state'] ?? null) === 'running' && ($retry['lease_token'] ?? null) === $token
            && ($retry['lease_until'] ?? 0) > now()->timestamp;
    }

    protected function writeLedger(SkillEntitlement $entitlement, array $retry): void
    {
        $meta = (array) $entitlement->meta;
        $meta['delivery_retry'] = $retry;
        $entitlement->meta = $meta;
        $entitlement->save();
    }

    protected function settle(SkillEntitlement $entitlement, array $retry, array $outcome): array
    {
        $retry['state'] = $outcome['state'];
        $retry['reason_code'] = $outcome['reason_code'];
        $retry['lease_token'] = $retry['lease_until'] = null;
        $retry['dispatch_token'] = $retry['dispatch_until'] = null;
        if (in_array($retry['state'], self::TERMINAL, true)) {
            $retry['next_retry_at'] = null;
        } elseif ($retry['state'] === 'waiting') {
            $retry['next_retry_at'] = now()->timestamp + 60;
        }
        if ($retry['state'] === 'succeeded') {
            // AppChannel may have committed granted_at before the listener's
            // diagnostic save failed. Reconcile that explicit local success;
            // do not keep a stale exception blocking confirmation forever.
            // Business rejection outcomes never enter this branch.
            $meta = (array) $entitlement->meta;
            $meta['delivery_failed'] = null;
            if (($meta['delivery']['ok'] ?? null) !== true) {
                $meta['delivery'] = DeliveryResult::ok(
                    '应用内授权已交付，已依据授权状态恢复诊断。',
                    ['source' => 'local_entitlement_state', 'reason_code' => $outcome['reason_code']],
                    'APP_DELIVERY_CONFIRMED',
                )->toArray();
            }
            $entitlement->meta = $meta;
        }
        $this->writeLedger($entitlement, $retry);
        if (in_array($retry['state'], ['manual', 'blocked', 'exhausted'], true)) {
            $payload = ['order_id' => $entitlement->order_id, 'order_item_id' => $entitlement->order_item_id,
                'entitlement_id'   => $entitlement->id, 'attempts' => $retry['attempts'] ?? 0,
                'reason_code'      => $retry['reason_code']];
            DB::afterCommit(function () use ($payload, $retry) {
                try {
                    $this->alerts->alert('skill_delivery', '应用内授权补偿需人工核查',
                        '原因：'.$payload['reason_code'].'。请先运行 php artisan skills:redeliver '.$payload['order_id'].'（默认只检查）。',
                        'skill-delivery:'.$payload['entitlement_id'].':'.($retry['cycle_id'] ?? 'legacy'), $payload, AdminAlertService::LEVEL_WARN);
                } catch (Throwable $error) {
                    // The delivery transaction already committed. Notification
                    // failure must not be reinterpreted as delivery failure;
                    // SkillDeliveryAudit retries from durable terminal facts.
                    try {
                        Log::error('Skill compensation notification failed', [
                            'entitlement_id' => $payload['entitlement_id'], 'class' => $error::class,
                        ]);
                    } catch (Throwable) {
                        // Even a broken logger cannot turn a committed business
                        // result into an exception in execute(). Audit can retry.
                    }
                }
            });
        }

        return $this->answer($retry['state'], $retry['reason_code'], (int) $entitlement->id, $retry);
    }

    protected function answer(string $state, string $code, int $id, array $retry = []): array
    {
        return ['entitlement_id' => $id, 'state' => $state, 'reason_code' => $code,
            'attempts'           => (int) ($retry['attempts'] ?? 0), 'max_attempts' => $this->budget(),
            'next_retry_at'      => $retry['next_retry_at'] ?? null];
    }

    /** Shared order -> item -> product -> entitlement mutation lock order. */
    protected function locked(int $id, callable $callback): mixed
    {
        return DB::transaction(function () use ($id, $callback) {
            $identity = SkillEntitlement::find($id, ['id', 'order_id', 'order_item_id', 'product_id']);
            if (! $identity) {
                return $callback(null);
            }
            $order = $identity->order_id
                ? OrderProxy::modelClass()::query()->whereKey($identity->order_id)->lockForUpdate()->first() : null;
            $item = $identity->order_item_id
                ? OrderItemProxy::modelClass()::query()->whereKey($identity->order_item_id)->lockForUpdate()->first() : null;
            $product = $identity->product_id
                ? ProductProxy::modelClass()::query()->whereKey($identity->product_id)->lockForUpdate()->first() : null;
            $entitlement = SkillEntitlement::query()->whereKey($id)->lockForUpdate()->first();
            if ($entitlement) {
                if ($entitlement->order_id !== $identity->order_id || $entitlement->order_item_id !== $identity->order_item_id
                    || $entitlement->product_id !== $identity->product_id) {
                    throw new \RuntimeException('Entitlement purchase identity changed while acquiring locks.');
                }
                $entitlement->setRelation('order', $order);
                $entitlement->setRelation('orderItem', $item);
                $entitlement->setRelation('product', $product);
            }

            return $callback($entitlement);
        });
    }
}
