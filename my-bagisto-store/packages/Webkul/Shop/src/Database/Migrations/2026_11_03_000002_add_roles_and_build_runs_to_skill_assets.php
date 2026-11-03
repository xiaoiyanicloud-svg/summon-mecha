<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 第二阶段标准技能编译器核心加性迁移：
 * 1. skill_assets 补充角色（legacy/source/artifact）、asset_key、目标机型与构建键；
 * 2. skill_build_runs 记录每次独立构建 Run 历史，保证不可变追溯与防覆盖；
 * 3. skill_target_releases 独立管理各目标机型成品的审核准入与发布快照。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('skill_assets')) {
            Schema::table('skill_assets', function (Blueprint $table) {
                if (! Schema::hasColumn('skill_assets', 'asset_role')) {
                    $table->string('asset_role', 24)->default('legacy')->after('carrier_type');
                }
                if (! Schema::hasColumn('skill_assets', 'asset_key')) {
                    $table->string('asset_key', 64)->nullable()->after('asset_role');
                }
                if (! Schema::hasColumn('skill_assets', 'target_model_code')) {
                    $table->string('target_model_code', 64)->nullable()->after('asset_key');
                }
                if (! Schema::hasColumn('skill_assets', 'build_key')) {
                    $table->string('build_key', 128)->nullable()->after('target_model_code');
                }
                if (! Schema::hasColumn('skill_assets', 'source_digest')) {
                    $table->string('source_digest', 128)->nullable()->after('build_key');
                }
                if (! Schema::hasColumn('skill_assets', 'review_status')) {
                    $table->string('review_status', 16)->default('approved')->after('source_digest');
                }
            });

            // 补充索引（使用 try/catch 防御已存在）
            try {
                Schema::table('skill_assets', function (Blueprint $table) {
                    $table->index(['skill_version_id', 'asset_role'], 'idx_skill_assets_role');
                    $table->index(['skill_version_id', 'target_model_code', 'asset_role'], 'idx_skill_assets_target_role');
                });
            } catch (\Throwable $e) {
                // 忽略已存在索引
            }
        }

        if (! Schema::hasTable('skill_build_runs')) {
            Schema::create('skill_build_runs', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('skill_version_id');
                $table->string('target_model_code', 64);
                $table->string('build_key', 128);
                $table->unsignedInteger('run_number')->default(1);
                $table->string('source_digest', 128)->nullable();
                $table->string('target_profile_digest', 128)->nullable();
                $table->string('status', 16)->default('pending'); // pending/building/success/failed
                $table->string('carrier_type', 24)->nullable();
                $table->unsignedInteger('artifact_asset_id')->nullable();
                $table->text('build_log')->nullable();
                $table->string('error', 512)->nullable();
                $table->timestamps();

                $table->unique(['build_key', 'run_number'], 'uk_build_runs_key_run');
                $table->index(['skill_version_id', 'target_model_code'], 'idx_build_runs_version_model');
            });
        }

        if (! Schema::hasTable('skill_target_releases')) {
            Schema::create('skill_target_releases', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('skill_version_id');
                $table->string('target_model_code', 64);
                $table->unsignedInteger('artifact_asset_id');
                $table->string('build_key', 128);
                $table->string('review_status', 16)->default('pending'); // pending/approved/rejected/revoked
                $table->timestamp('reviewed_at')->nullable();
                $table->unsignedInteger('reviewer_id')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamps();

                $table->unique(['skill_version_id', 'target_model_code'], 'uk_target_releases_version_model');
                $table->index('review_status', 'idx_target_releases_status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_target_releases');
        Schema::dropIfExists('skill_build_runs');

        if (Schema::hasTable('skill_assets')) {
            Schema::table('skill_assets', function (Blueprint $table) {
                $columns = ['asset_role', 'asset_key', 'target_model_code', 'build_key', 'source_digest', 'review_status'];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('skill_assets', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
