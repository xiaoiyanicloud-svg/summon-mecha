<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3.4 步骤 3 · 数据底座 —— 复用表的扩展列。
 *
 * 只做「加列」,不改动已有列、不迁移数据。用 hasColumn 守卫保证可重复执行。
 *
 *  - skill_versions: 交付通道/风险/pricing/manifest 来源(见 计划 §3.1)
 *  - devices:        binding_id 关联全局绑定表(见 计划 §3.2 设备唯一真相源=binding)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('skill_versions')) {
            Schema::table('skill_versions', function (Blueprint $table) {
                if (! Schema::hasColumn('skill_versions', 'delivery_channels')) {
                    // 交付通道声明:["agent","vendor:unitree","manual"]
                    $table->json('delivery_channels')->nullable()->after('channel');
                }
                if (! Schema::hasColumn('skill_versions', 'risk_level')) {
                    $table->string('risk_level', 10)->nullable()->after('delivery_channels');
                }
                if (! Schema::hasColumn('skill_versions', 'pricing_mode')) {
                    // buyout / free （买断制用 pricing_mode 表达,不改 v1 的 license_mode）
                    $table->string('pricing_mode', 16)->nullable()->after('license_mode');
                }
                if (! Schema::hasColumn('skill_versions', 'manifest_schema')) {
                    $table->string('manifest_schema', 32)->nullable()->after('pricing_mode');
                }
                if (! Schema::hasColumn('skill_versions', 'source_format')) {
                    // native / unitree_unistore / agibot_aimdk / bvh / smpler / lerobot
                    $table->string('source_format', 32)->nullable()->after('manifest_schema');
                }
                if (! Schema::hasColumn('skill_versions', 'imported_meta')) {
                    $table->json('imported_meta')->nullable()->after('source_format');
                }
            });
        }

        if (Schema::hasTable('devices') && ! Schema::hasColumn('devices', 'binding_id')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->unsignedInteger('binding_id')->nullable()->after('product_id');
                $table->index('binding_id', 'idx_devices_binding');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('skill_versions')) {
            Schema::table('skill_versions', function (Blueprint $table) {
                foreach (['delivery_channels', 'risk_level', 'pricing_mode', 'manifest_schema', 'source_format', 'imported_meta'] as $col) {
                    if (Schema::hasColumn('skill_versions', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('devices') && Schema::hasColumn('devices', 'binding_id')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->dropIndex('idx_devices_binding');
                $table->dropColumn('binding_id');
            });
        }
    }
};
