<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A2 · 工单 `Idempotency-Key` 的落点。
 *
 * 客户端（小程序）在弱网重试、用户双击时会把同一个开工单请求发两遍。
 * 此前没有任何去重锚点：每发一遍就多一张工单，且 `ticket_no` 是秒级时间戳
 * （`T` + `ymdHis` + customer_id），同秒两次直接撞唯一键 → 500。
 *
 * 这里开一列存客户端传来的幂等键（**存 sha256，不存原文**：键是客户端自造的
 * 不透明令牌，原文对我们没意义，且不该让客户端可控内容直接落库）。
 * `(customer_id, idempotency_key)` 唯一 —— 按客户隔离，避免两个客户恰好用了同一个键。
 *
 * ⚠ MySQL 的唯一索引允许多个 NULL，所以历史工单（idempotency_key 为空）不受影响。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_ticket') || Schema::hasColumn('support_ticket', 'idempotency_key')) {
            return;
        }

        Schema::table('support_ticket', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('ticket_no');
            $table->unique(['customer_id', 'idempotency_key'], 'uk_ticket_customer_idem');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('support_ticket') || ! Schema::hasColumn('support_ticket', 'idempotency_key')) {
            return;
        }

        Schema::table('support_ticket', function (Blueprint $table) {
            $table->dropUnique('uk_ticket_customer_idem');
            $table->dropColumn('idempotency_key');
        });
    }
};
