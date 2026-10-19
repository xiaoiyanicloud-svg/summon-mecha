<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G1-02 · `ecosystem_outbox`（ADR-0001 §4.11 / G1-D07）。
 *
 * 与发布/撤回/举办状态变更的**业务写同一事务**落一条记录；业务回滚则这里也不留可消费记录。
 * G1 只建基础表与消费接口；报名通知扇出、分页游标、人工补偿入口留 G2-12。
 *
 * `payload` 只允许放必要 ID / 版本 / 原因码，**不得含手机号、证件、令牌、完整联系人**。
 * 这条不是命名约定，是数据边界；写入方（OutboxWriter）会在入口处拒绝 PII 键。
 *
 * `dedupe_key` 可空且唯一：
 *   - NULL 允许无限多行（MySQL 唯一索引不约束 NULL）；
 *   - 非空时同一业务事实重复入队应命中原行，而不是造第二条。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ecosystem_outbox')) {
            return;
        }

        Schema::create('ecosystem_outbox', function (Blueprint $table) {
            $table->id();

            // 聚合 = “哪个业务对象发生了什么”。event / content 由配置注册表白名单约束。
            $table->string('aggregate_type', 50);
            $table->unsignedBigInteger('aggregate_id');
            $table->string('event_type', 100);

            // 只放 ID / 版本 / 原因码；PII 在写入入口被拒绝。
            $table->json('payload');

            // 可空业务幂等键；唯一索引见下。
            $table->string('dedupe_key', 191)->nullable();

            // pending -> processing -> sent；可重试失败回 pending；超上限 dead。
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);

            // UTC；worker 下次可领取的时间。
            $table->dateTime('available_at');

            // G1-09 worker 租约字段；G1-02 先把列建好。
            $table->string('lease_owner', 100)->nullable();
            $table->dateTime('lease_until')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->unique('dedupe_key', 'uk_ecosystem_outbox_dedupe_key');
            $table->index(['aggregate_type', 'aggregate_id'], 'idx_ecosystem_outbox_aggregate');
            $table->index('event_type', 'idx_ecosystem_outbox_event_type');
            $table->index(['status', 'available_at'], 'idx_ecosystem_outbox_status_available');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecosystem_outbox');
    }
};
