<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-8（改判版）· 权限声明可见化 —— skill_versions 增 `permissions` 列。
 *
 * 为什么需要这一列：`manifest.permissions` 此前只在 L0 做**白名单校验**（SkillManifest::
 * PERMISSION_WHITELIST），**校验完即丢** —— 库里无处安放，于是"这个技能会用到摄像头"
 * 这类信息在下单前对用户完全不可见。
 *
 * ⚠ 本列**只用于可见化**，不用于任何"声明 vs 实际调用"的比对/强制（那是幻想，见
 *   AuditService::submitLayer0 的注释：设备能力无数据源 / motion 技能无代码可分析 /
 *   端侧受控执行不存在）。
 *
 * 与邻列（delivery_channels / risk_level）同处、同风格：json + nullable，
 * hasColumn 守卫保证可重复执行，down() 对称删除。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('skill_versions') && ! Schema::hasColumn('skill_versions', 'permissions')) {
            Schema::table('skill_versions', function (Blueprint $table) {
                // 开发者声明的权限白名单子集，如 ["motion_control","camera"]
                $table->json('permissions')->nullable()->after('risk_level');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('skill_versions') && Schema::hasColumn('skill_versions', 'permissions')) {
            Schema::table('skill_versions', function (Blueprint $table) {
                $table->dropColumn('permissions');
            });
        }
    }
};
