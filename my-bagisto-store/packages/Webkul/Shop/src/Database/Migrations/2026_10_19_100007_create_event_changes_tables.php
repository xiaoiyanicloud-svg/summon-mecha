<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G2-08 · 活动变更与报名人确认（G2 实施计划 §3.1）。
 *
 * `event_changes` 是不可变的变更事实：改期、取消、调额、关闭/恢复都留版本。
 * 需要确认的变更必须同时配置：
 *   · `ack_deadline`；
 *   · `decline_releases_seat`；
 *   · `no_response_action`（none / release）。
 * 任一缺失时服务层拒绝发布该变更，不用代码默认值替业务补政策。
 *
 * `event_change_acknowledgments` 只记录真实响应；未响应不是 accepted，
 * 到期由扫描按已公布政策处理，绝不自动推定接受。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('event_changes')) {
            Schema::create('event_changes', function (Blueprint $table): void {
                $table->id();

                $table->unsignedBigInteger('event_id');
                $table->unsignedInteger('change_version');

                // reschedule / cancel / capacity / close / reopen
                $table->string('type', 20);
                $table->string('summary', 500);

                // 变更前后事实；只放结构化字段，不放联系人。
                $table->json('details')->nullable();

                $table->boolean('requires_ack')->default(false);
                $table->dateTime('ack_deadline')->nullable();
                $table->boolean('decline_releases_seat')->default(false);
                $table->string('no_response_action', 20)->default('none');

                // draft / published
                $table->string('status', 20)->default('draft');

                $table->unsignedBigInteger('created_by');
                $table->dateTime('published_at')->nullable();

                $table->timestamps();

                $table->unique(['event_id', 'change_version'], 'uk_event_changes_event_version');
                $table->index(['event_id', 'status'], 'idx_event_changes_event_status');
                $table->index(['event_id', 'type'], 'idx_event_changes_event_type');

                $table->foreign('event_id', 'fk_event_changes_event')
                    ->references('id')->on('events')
                    ->onDelete('restrict')->onUpdate('restrict');
            });
        }

        if (! Schema::hasTable('event_change_acknowledgments')) {
            Schema::create('event_change_acknowledgments', function (Blueprint $table): void {
                $table->id();

                $table->unsignedBigInteger('change_id');
                $table->unsignedBigInteger('registration_id');
                $table->unsignedBigInteger('attempt_id');

                // accepted / declined / no_response
                $table->string('response', 20);
                $table->dateTime('responded_at')->nullable();
                $table->text('note')->nullable();

                $table->timestamps();

                $table->unique(['change_id', 'attempt_id'], 'uk_event_change_acks_change_attempt');
                $table->index(['attempt_id', 'response'], 'idx_event_change_acks_attempt_response');

                $table->foreign('change_id', 'fk_event_change_acks_change')
                    ->references('id')->on('event_changes')
                    ->onDelete('restrict')->onUpdate('restrict');
                $table->foreign('registration_id', 'fk_event_change_acks_registration')
                    ->references('id')->on('event_registrations')
                    ->onDelete('restrict')->onUpdate('restrict');
                $table->foreign('attempt_id', 'fk_event_change_acks_attempt')
                    ->references('id')->on('event_registration_attempts')
                    ->onDelete('restrict')->onUpdate('restrict');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_change_acknowledgments');
        Schema::dropIfExists('event_changes');
    }
};
