<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 修一个**会说谎的默认值**：`reconciliation.status` 原默认 `'matched'`（已平）。
 *
 * 这意味着任何一行新插入的对账记录，**在还没核对过任何东西的时候**就已经写着"已平"。
 * 对账的全部意义就是"平台账"与"渠道账"能不能对上 —— 给一个默认成立的结论，
 * 等于把这个功能变成了装饰：真出了差额，页面照样显示"已平"。
 *
 * 改成 `'pending'`（未核对）。核对过才允许出现 matched / diff 这类结论值。
 *
 * ⚠ 这类"默认值即结论"的问题值得单独记一笔：它不像崩溃那样显眼，
 *   但危害更大 —— 崩溃会被人发现，"默认正确"不会。
 *   同类检查：凡是 status/result 默认值**代表一个需要通过才能得到的结论**的列，
 *   默认都应该是 pending/unchecked。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reconciliation')) {
            return;
        }

        Schema::table('reconciliation', function (Blueprint $table) {
            $table->string('status', 24)->default('pending')->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('reconciliation')) {
            return;
        }

        Schema::table('reconciliation', function (Blueprint $table) {
            $table->string('status', 24)->default('matched')->change();
        });
    }
};
