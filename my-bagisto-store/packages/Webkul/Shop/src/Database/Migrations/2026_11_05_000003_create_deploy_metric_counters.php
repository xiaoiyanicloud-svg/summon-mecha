<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3 运行指标计数器（新增表，纯追加，不改动既有列）。
 *
 * `deploy_metric_counters`
 *   —— 平台侧计数器实体。**键集闭合**：只有 `DeployMetricCounter::METRIC_KEYS` 里的键能在这里出现，
 *      服务层对未知键直接抛异常。这条约束就是"基数有界"的实现方式 ——
 *      没有动态键，就不可能混进 device_id / task_no / skill_id 这类高基数标签。
 *
 * `deploy_agent_metric_batches`
 *   —— 设备端增量上报的幂等台账。Agent 每批增量带一个 `batch_id`；
 *      平台按 `(did, batch_id)` 去重，重复上报不会重复累加。
 *      没有这张表，"重试"就等于"多计数"，计数器会虚高。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deploy_metric_counters')) {
            Schema::create('deploy_metric_counters', function (Blueprint $table) {
                $table->increments('id');
                $table->string('metric_key', 64);
                $table->unsignedBigInteger('metric_value')->default(0);
                $table->timestamp('updated_at')->nullable();

                $table->unique('metric_key', 'uk_deploy_metric_key');
            });
        }

        if (! Schema::hasTable('deploy_agent_metric_batches')) {
            Schema::create('deploy_agent_metric_batches', function (Blueprint $table) {
                $table->increments('id');
                $table->string('did', 64);
                $table->string('batch_id', 64);
                $table->timestamp('applied_at')->nullable();

                $table->unique(['did', 'batch_id'], 'uk_deploy_agent_metric_batch');
                $table->index('applied_at', 'idx_deploy_agent_metric_applied');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_agent_metric_batches');
        Schema::dropIfExists('deploy_metric_counters');
    }
};
