<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3 设备端 gauge（瞬时值）—— 新增表，纯追加，不改动既有列。
 *
 * 为什么 gauge 不能塞进 `deploy_metric_counters`：
 *   `outbox_pending_age_seconds` 是"现在最老的未确认事件有多旧"。它是**瞬时值**，
 *   每次心跳上报都应覆盖上一次；如果走累加表，数值会单调上涨，几小时后就会误报警。
 *   计数（只增）与 gauge（覆盖）混在一张表里，迟早有人把 gauge 也累加。
 *
 * 主键 `(did, metric_key)`：每台设备每个 gauge 只保留最新值 —— 基数 = 设备数 × 1，
 * 与运行时长无关。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deploy_agent_metric_gauges')) {
            Schema::create('deploy_agent_metric_gauges', function (Blueprint $table) {
                $table->increments('id');
                $table->string('did', 64);
                $table->string('metric_key', 64);
                $table->unsignedBigInteger('metric_value')->default(0);
                $table->timestamp('updated_at')->nullable();

                $table->unique(['did', 'metric_key'], 'uk_deploy_agent_metric_gauge');
                $table->index('metric_key', 'idx_deploy_agent_metric_gauge_key');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_agent_metric_gauges');
    }
};
