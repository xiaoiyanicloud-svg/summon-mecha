<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 评价域（加固计划 §四 A1）—— 标题、处置理由、一人一技能一条。
 *
 * `skill_review` 建表后一直零写入方，本轮补评价写入方与处置台。落代码时发现三处
 * 表结构跟不上的地方，都在这里补齐（表当前 0 行，无历史数据冲突）：
 *
 * ① **`title` 列不存在**。表里只有 `content`。评价的"一句话标题"是小程序评价卡片的
 *    主文案，与正文是两件事，塞进 `content` 第一行会让"标题"永远没法单独截断/单独审。
 *
 * ② **处置理由无处可写**。人工把一条评价隐藏/恢复，`status` 之外没有任何地方记
 *    "为什么" —— 与 `skill_report.handling_note` 同一个理由（事后能被问的就是这个）。
 *
 * ③ **一人一技能一条**。评价是可累积的公开内容，没有约束就能反复提交刷分/刷屏。
 *    唯一键放在库级而不是只写在控制器里：并发双击（弱网重试）在应用层判完还是会漏，
 *    这个项目已经在 A2/A3 上踩过同一类坑。
 *    ⚠ 代价：本轮**没有**修改历史评价的入口 —— 想改只能等后续加"编辑/申诉"
 *      （会与这条唯一键一起设计）。这是刻意的取舍，不是漏做。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('skill_review') || Schema::hasColumn('skill_review', 'title')) {
            return;
        }

        Schema::table('skill_review', function (Blueprint $table) {
            $table->string('title', 191)->nullable()->after('order_id');
            $table->string('moderation_note', 500)->nullable()->after('replied_at');

            $table->unique(['customer_id', 'skill_id'], 'uk_skill_review_customer_skill');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('skill_review') || ! Schema::hasColumn('skill_review', 'title')) {
            return;
        }

        Schema::table('skill_review', function (Blueprint $table) {
            $table->dropUnique('uk_skill_review_customer_skill');
            $table->dropColumn(['title', 'moderation_note']);
        });
    }
};
