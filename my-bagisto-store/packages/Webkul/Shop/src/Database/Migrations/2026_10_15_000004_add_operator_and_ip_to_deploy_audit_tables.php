<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P10 · 给下发侧的安全动作补「操作者 + 来源 IP」。
 *
 * 缺口（审计不足以复盘"谁何时给哪台设备推了什么"）:
 *   · `rollback_record` 建表时**没有 created_by、也没有 IP** —— 灰度回滚是拿别人的
 *     设备去执行的动作,事后却查不出是谁触发的;
 *   · `skill_blocklist` 有 created_by,但值恒为字面量 `'admin'`（见 KillSwitchService::engage
 *     `:32` 的默认参数 + SkillSupplyController 不传）—— 一个**编造的身份**,
 *     比留空更坏:它看起来像有留痕。熔断是 D9 的安全动作,必须能追到真实调用方。
 *
 * 为什么 role 拆成两处:
 *   `skill_blocklist.created_by` 列宽 32,装不下 'internal:' + 长 operator,故控制器侧截断;
 *   这里只加列,不改宽(不改既有列的定义,避免影响已落库的数据)。
 *
 * 可空:历史行没有这个信息,不能凭空补。新写入由服务层保证带上。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rollback_record') && ! Schema::hasColumn('rollback_record', 'created_by')) {
            Schema::table('rollback_record', function (Blueprint $table) {
                $table->string('created_by', 32)->nullable()->after('affected_devices');
            });
        }

        if (Schema::hasTable('rollback_record') && ! Schema::hasColumn('rollback_record', 'ip')) {
            Schema::table('rollback_record', function (Blueprint $table) {
                $table->string('ip', 45)->nullable()->after('created_by');
            });
        }

        if (Schema::hasTable('skill_blocklist') && ! Schema::hasColumn('skill_blocklist', 'ip')) {
            Schema::table('skill_blocklist', function (Blueprint $table) {
                $table->string('ip', 45)->nullable()->after('created_by');
            });
        }
    }

    public function down(): void
    {
        foreach (['rollback_record', 'skill_blocklist'] as $table) {
            foreach (['created_by', 'ip'] as $column) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
    }
};
