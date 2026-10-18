<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C2 · 下发任务钉住具体交付物。
 *
 * 此前一条安装任务只记 (skill_version_id, package_hash)，而"发出去的是哪个文件"
 * 由下载端点的**默认排序**临时决定。多交付物版本上，一旦排序/资产集合变化，
 * 同一个任务重签 URL 时可能指向另一份字节 —— 端侧复算的 sha256 与任务声明
 * 就再也对不上了。
 *
 * `package_asset_id` 让任务把"当时决定发的那一个文件"钉住；
 * 历史任务该列为 NULL，读取时回退到默认文件（保持旧契约）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deploy_task') && ! Schema::hasColumn('deploy_task', 'package_asset_id')) {
            Schema::table('deploy_task', function (Blueprint $table) {
                $table->unsignedInteger('package_asset_id')->nullable()->after('package_hash');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('deploy_task') && Schema::hasColumn('deploy_task', 'package_asset_id')) {
            Schema::table('deploy_task', function (Blueprint $table) {
                $table->dropColumn('package_asset_id');
            });
        }
    }
};