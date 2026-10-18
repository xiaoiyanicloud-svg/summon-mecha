<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3 · 适配证据链第 1 环 —— 版本级兼容性声明。
 *
 * 清单里的 `compatibility.models[]` 此前**校验完即丢**：买家/设备侧只能看到
 * "交付通道是否可装"，看不到"这个版本声明支持哪些机型/固件/SDK/Agent"。
 * 本列把开发者声明原样落库，作为"声明"一侧的**唯一事实源**；
 * 实际适配结果由 `skill_compatibility_evidence` 记录（证据一侧）。
 *
 * 为什么是 nullable JSON 而不是拆表：声明是随版本一起提交、一起替换的一份整体
 * （多机型数组），拆表会引入"同一声明被部分改写"的一致性缺口。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('skill_versions') && ! Schema::hasColumn('skill_versions', 'compatibility')) {
            Schema::table('skill_versions', function (Blueprint $table) {
                $table->json('compatibility')->nullable()->after('permissions');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('skill_versions') && Schema::hasColumn('skill_versions', 'compatibility')) {
            Schema::table('skill_versions', function (Blueprint $table) {
                $table->dropColumn('compatibility');
            });
        }
    }
};