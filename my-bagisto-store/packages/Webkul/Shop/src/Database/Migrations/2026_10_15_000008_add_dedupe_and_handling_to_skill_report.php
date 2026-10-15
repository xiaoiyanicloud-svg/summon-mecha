<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 举报域（加固计划 §四 A1）—— 去重槽位 + 处置结论。
 *
 * `skill_report` 是**违规技能唯一的用户侧举报通道**（`content_security_record`
 * 只留痕机器扫描，没有用户入口）。表建了但零代码，本轮补写入方与处置台。
 * 补代码时发现两件事必须落到库级，否则功能是残的：
 *
 * ① **重复举报去重需要一个原子的锚点**。
 *    "同一用户对同一对象、在上一单还没处置完之前只能有一条" ——
 *    只在应用层 `exists()` 判一遍就是 check-then-act：用户双击/弱网重试
 *    会并发出两条（而这正是本项目已经踩过的坑，见 A2/A3）。
 *    这里用 `open_slot`：未处置时值为 `'open'`，一旦处置就置 NULL。
 *    唯一键 `(reporter_customer_id, target_type, target_id, open_slot)`
 *    于是表达的就是"每个(举报人,对象)最多一条**未处置**记录"。
 *    ⚠ 依赖 MySQL/SQLite 唯一索引**允许多个 NULL** 的语义：已处置的历史记录
 *      open_slot 为 NULL，互不冲突 —— 处置完用户可以再举报（对象没改好就该能再报）。
 *
 * ② **处置结论无处可写**。`skill_report` 只有 status/handler_id/handled_at，
 *    没有理由列。驳回一个举报（认定"不成立"）是把风险收回来自己扛，
 *    没有书面理由事后无法解释 —— 与 `content_security_record.disposition_note`
 *    同一个道理，故补 `handling_note`。
 *
 * ⚠ 本迁移不改任何既有列，只加列 + 加唯一键；表当前 0 行，不存在历史数据冲突。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('skill_report') || Schema::hasColumn('skill_report', 'open_slot')) {
            return;
        }

        Schema::table('skill_report', function (Blueprint $table) {
            $table->string('open_slot', 16)->nullable()->after('status');
            $table->text('handling_note')->nullable()->after('handled_at');

            $table->unique(
                ['reporter_customer_id', 'target_type', 'target_id', 'open_slot'],
                'uk_skill_report_open'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('skill_report') || ! Schema::hasColumn('skill_report', 'open_slot')) {
            return;
        }

        Schema::table('skill_report', function (Blueprint $table) {
            $table->dropUnique('uk_skill_report_open');
            $table->dropColumn(['open_slot', 'handling_note']);
        });
    }
};
