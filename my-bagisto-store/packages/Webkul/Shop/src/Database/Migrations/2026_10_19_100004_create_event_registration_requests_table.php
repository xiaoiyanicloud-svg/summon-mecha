<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G2-03 · `event_registration_requests`（幂等请求事实）。
 *
 * 客户端为每个写动作生成 `request_key`。同一 actor + action + key：
 *   · 载荷指纹相同 → 返回原结果引用，并读取轮次**当前**状态；
 *   · 载荷指纹不同 → 409 `REGISTRATION_REQUEST_CONFLICT`；
 *   · 不同 actor → 独立作用域，不共享结果。
 *
 * 该表只存引用与指纹，不存请求正文或联系人，避免把 PII 写进幂等日志。
 */
return new class extends Migration
{
    private const TABLE = 'event_registration_requests';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->string('actor_type', 20); // customer / admin / system
            $table->unsignedBigInteger('actor_id');
            $table->string('action', 30);     // submit / cancel / reapply / ack_change
            $table->string('request_key', 100);
            $table->char('payload_hash', 64);

            $table->string('target_type', 30); // event / registration / attempt / change
            $table->unsignedBigInteger('target_id')->nullable();

            $table->string('result_type', 30)->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->string('result_public_no', 32)->nullable();

            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['actor_type', 'actor_id', 'action', 'request_key'],
                'uk_event_registration_requests_actor_action_key'
            );
            $table->index(['target_type', 'target_id'], 'idx_event_registration_requests_target');
            $table->index('result_public_no', 'idx_event_registration_requests_result_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
