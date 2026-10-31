<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 客服协作能力（2026-10-27）：挂起标记 + 快捷回复使用统计。
 *
 * 只做**加法**：
 *   · 挂起不改既有状态机 —— `held_at` 与 open/processing/resolved/closed 正交，
 *     用户端仍能看到「等待补充」，但 DataGrid、SLA、结单判断不受影响；
 *   · 快捷回复的「插入 / 发送」两类事件单独落表，不塞进 ticket_message ——
 *     话术统计不是会话内容，混进消息流会污染用户可见的对话。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_ticket') && ! Schema::hasColumn('support_ticket', 'held_at')) {
            Schema::table('support_ticket', function (Blueprint $table) {
                $table->timestamp('held_at')->nullable()->after('ended_at')->comment('挂起时间，空=未挂起');
                $table->string('held_reason', 200)->nullable()->after('held_at')->comment('内部挂起原因，不对用户展示');
                $table->index('held_at', 'idx_ticket_held_at');
            });
        }

        if (! Schema::hasTable('support_quick_reply_event')) {
            Schema::create('support_quick_reply_event', function (Blueprint $table) {
                $table->increments('id');
                $table->string('reply_key', 64);
                $table->string('event', 16)->comment('insert=点击插入，send=实际发出');
                $table->unsignedInteger('admin_id')->nullable();
                $table->unsignedInteger('ticket_id')->nullable();
                $table->string('category', 64)->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['event', 'created_at'], 'idx_qr_event_time');
                $table->index(['reply_key', 'event'], 'idx_qr_event_key');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_quick_reply_event');

        if (Schema::hasTable('support_ticket') && Schema::hasColumn('support_ticket', 'held_at')) {
            Schema::table('support_ticket', function (Blueprint $table) {
                $table->dropIndex('idx_ticket_held_at');
                $table->dropColumn(['held_at', 'held_reason']);
            });
        }
    }
};
