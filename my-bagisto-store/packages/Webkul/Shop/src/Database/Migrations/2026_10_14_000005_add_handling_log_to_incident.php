<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 给 `incident` 单独开一列存**平台处理记录**。
 *
 * 为什么不能塞进 `evidence`：那一列存的是**报案人提交的证据**，
 * 而平台的处理日志（谁在什么时候改了什么状态、定了谁的责、写了什么备注）
 * 是**内部材料**。两者的可见范围完全不同 —— 混在一列里，
 * 将来任何一次"把 evidence 返回给报案人"都会顺带泄露内部定责意见。
 *
 * 这不是洁癖：事故定责涉及赔偿与法律责任，内部讨论如果被当作"用户证据"发出去，
 * 是实打实的风险。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('incident') || Schema::hasColumn('incident', 'handling_log')) {
            return;
        }

        Schema::table('incident', function (Blueprint $table) {
            $table->json('handling_log')->nullable()->after('trace');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('incident') || ! Schema::hasColumn('incident', 'handling_log')) {
            return;
        }

        Schema::table('incident', function (Blueprint $table) {
            $table->dropColumn('handling_log');
        });
    }
};
