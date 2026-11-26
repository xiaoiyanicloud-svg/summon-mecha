<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B1：构建命令的持久化幂等表。
 *
 * - `(seller_id, idempotency_key)` 唯一：同键同载荷返回原结果，同键异载荷 409；
 * - 命令行本身就是 outbox：事务内只落命令，构建在事务外执行；
 * - `status` 状态机：queued → running → completed/failed/canceled；
 * - `started_at` / `finished_at` 供 B8 只读指标派生（重试不重复计数）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('skill_build_commands')) {
            return;
        }

        Schema::create('skill_build_commands', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedBigInteger('seller_id');
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);

            $table->string('status', 16)->default('queued');
            $table->string('build_key', 128)->nullable();

            $table->unsignedInteger('skill_version_id');
            $table->unsignedInteger('source_asset_id');
            $table->string('source_digest', 128);
            $table->string('target_model_code', 64);
            $table->string('target_profile_digest', 128);
            $table->string('compiler_version', 64);
            $table->string('requested_by', 64);

            $table->json('payload_json')->nullable();
            $table->json('result_json')->nullable();
            $table->unsignedInteger('build_run_id')->nullable();

            $table->string('worker_id', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['seller_id', 'idempotency_key'], 'uk_build_commands_seller_idem');
            $table->index(['status', 'id'], 'idx_build_commands_status');
            $table->index('build_key', 'idx_build_commands_build_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_build_commands');
    }
};
