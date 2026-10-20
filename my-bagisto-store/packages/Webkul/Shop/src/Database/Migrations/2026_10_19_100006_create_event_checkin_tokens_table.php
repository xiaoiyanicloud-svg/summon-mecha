<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G2-09 · `event_checkin_tokens`（免费入场凭证）。
 *
 * 原始 token 只出现在凭证响应/二维码里，数据库只存 SHA-256：
 *   · token 绑定 attempt，取消/重报/重发时旧 token 立即吊销；
 *   · 签到同时核验活动状态、轮次状态、变更确认与员工活动范围；
 *   · 重复扫描返回同一签到事实（幂等），不重复计数；
 *   · token 不含姓名/手机号，QR 内容不含 PII。
 */
return new class extends Migration
{
    private const TABLE = 'event_checkin_tokens';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('registration_id');
            $table->unsignedBigInteger('attempt_id');

            // 仅存哈希；原始 token 不落库。
            $table->char('token_hash', 64);

            $table->dateTime('issued_at');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();

            // 签发人/来源：customer（本人领取）或 admin（运营补发）。
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->string('issued_source', 20);

            $table->timestamps();

            $table->unique('token_hash', 'uk_event_checkin_tokens_hash');
            $table->index('attempt_id', 'idx_event_checkin_tokens_attempt');
            $table->index(['event_id', 'revoked_at'], 'idx_event_checkin_tokens_event_revoked');

            $table->foreign('event_id', 'fk_event_checkin_tokens_event')
                ->references('id')->on('events')
                ->onDelete('restrict')->onUpdate('restrict');
            $table->foreign('registration_id', 'fk_event_checkin_tokens_registration')
                ->references('id')->on('event_registrations')
                ->onDelete('restrict')->onUpdate('restrict');
            $table->foreign('attempt_id', 'fk_event_checkin_tokens_attempt')
                ->references('id')->on('event_registration_attempts')
                ->onDelete('restrict')->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
