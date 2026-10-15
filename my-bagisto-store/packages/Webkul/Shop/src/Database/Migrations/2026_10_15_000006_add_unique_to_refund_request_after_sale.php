<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3 · 退款重复的库级兜底。
 *
 * `AfterSaleService::approve()` 的状态守卫已经在**事务内 + 行锁**下(M7),
 * 并发 approve 会在锁上排队、后到的读到已处理状态 —— 应用层已经挡得住。
 *
 * 但"钱出去"这一步(落 `refund_request`)此前没有任何库级约束:
 * `refund_request` 表对 `after_sale_id` **没有唯一键**,同一张售后单理论上能落多条退款记录。
 * 一行锁是"当时没被绕过"的保证,不是"永远绕不过"的保证 —— 加唯一键让它变成后者。
 *
 * ⚠ 计划原文建议把唯一键加在 `after_sale(customer_id, entitlement_id, status)` 上。
 *   本仓**刻意不这么做**:同一授权允许先后存在多条售后单(M7 用例:「退款失败(failed)
 *   不占用实付额度,同一订单条目还能再发起一次退款」),该约束会把它挡死,
 *   也会让 `RefundTransactionBoundaryTest`/`RefundAmountTest` 的既有行为回归。
 *   真正与"重复退款"同一条轴的是"一条售后单只能有一条退款记录",故约束落在 refund_request。
 *
 * `after_sale_id` 可为 NULL(库允许),MySQL 唯一索引允许多个 NULL,历史行不受影响。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('refund_request') || $this->hasIndex('refund_request', 'uk_refund_request_after_sale')) {
            return;
        }

        Schema::table('refund_request', function (Blueprint $table) {
            $table->unique('after_sale_id', 'uk_refund_request_after_sale');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('refund_request') || ! $this->hasIndex('refund_request', 'uk_refund_request_after_sale')) {
            return;
        }

        Schema::table('refund_request', function (Blueprint $table) {
            $table->dropUnique('uk_refund_request_after_sale');
        });
    }

    protected function hasIndex(string $table, string $index): bool
    {
        foreach (Schema::getIndexes($table) as $existing) {
            if (($existing['name'] ?? null) === $index) {
                return true;
            }
        }

        return false;
    }
};
