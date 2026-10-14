<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 同类清扫：`skill_review.status` 原默认 `'visible'`（可见）。
 *
 * 与 `reconciliation.status` 默认 `'matched'` 是**同一类问题**（见迁移 000006）：
 * 默认值替一次还没发生的判断下了结论。
 *
 * 这张表是技能评价/晒单，`content` 是用户自由文本。默认 `visible` 意味着
 * **任何一条新评价，在内容安全还没跑过之前，就已经是对外可见的** ——
 * 而这个平台的立场恰恰是"先审后发"，还专门建了 `content_security_record` 记录扫描结果。
 * 两套标准不能并存。
 *
 * 改成 `'pending'`（待处理）。要可见必须**显式**写下 `visible` ——
 * 也就是某个环节（机审通过 / 人工放行）必须真的做一个动作。
 *
 * ⚠ 现状：`skill_review` 表 0 行、全仓零代码引用（表建了但功能未做）。
 *   正因为还没有写入方，现在改代价是零；等写入方先落地再改，就得追着造数据的老代码跑。
 *   这也是"先定对默认值，再写写入方"这个顺序的价值。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('skill_review')) {
            return;
        }

        Schema::table('skill_review', function (Blueprint $table) {
            $table->string('status', 16)->default('pending')->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('skill_review')) {
            return;
        }

        Schema::table('skill_review', function (Blueprint $table) {
            $table->string('status', 16)->default('visible')->change();
        });
    }
};
