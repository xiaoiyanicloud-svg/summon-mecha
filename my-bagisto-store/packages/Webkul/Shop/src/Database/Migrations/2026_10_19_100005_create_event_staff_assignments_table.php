<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G2-14 · `event_staff_assignments`（活动人员范围）。
 *
 * 全局 ACL 只回答“这个人有没有某种能力”，本表回答“能不能作用于这个活动”。
 * 两者同时满足才放行；`permission_type=all` 的超管可越过范围，但每次动作仍写审计。
 *
 * capabilities JSON 词表（服务层白名单校验）：
 *   review（审核）、cancel（取消/纠正）、pii（解密联系人）、
 *   export（导出）、checkin（签到）、settings（scope 配置）。
 *
 * “人员不能自增范围”：本表只允许超管写入；有 settings 能力不等于能改本表。
 * admin_id 刻意不建外键（后台账号归档不应删除授权事实），写入时校验存在性。
 */
return new class extends Migration
{
    private const TABLE = 'event_staff_assignments';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('admin_id');

            // ["review","pii"] 等；服务层只接受白名单词。
            $table->json('capabilities');

            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_until')->nullable();
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->string('note', 255)->nullable();

            $table->timestamps();

            $table->unique(['event_id', 'admin_id'], 'uk_event_staff_assignments_event_admin');
            $table->index(['admin_id', 'valid_until'], 'idx_event_staff_assignments_admin_valid');
            $table->index(['event_id', 'valid_until'], 'idx_event_staff_assignments_event_valid');

            $table->foreign('event_id', 'fk_event_staff_assignments_event')
                ->references('id')->on('events')
                ->onDelete('restrict')->onUpdate('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
