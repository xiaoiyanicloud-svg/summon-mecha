<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3.4 步骤 3 · 技能资产与质量六表(计划 §3.3 / §4.20 / §4.21)。
 *
 *  skill_category / skill_tag / skill_tag_pivot —— 技能类目与标签(驱动筛选)
 *  skill_search_index   —— 搜索物化表(MySQL 5.7 不能索引 JSON,必须拍平成列)
 *  skill_build          —— 技能编译记录(中立定义 → 各机型载体,§4.20)
 *  skill_quality        —— 质量分级(过审后好/一般,§4.21;不合格不在此表)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('skill_category')) {
            Schema::create('skill_category', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('parent_id')->nullable();
                $table->string('code', 64);
                $table->json('name')->nullable();          // {zh_CN,en}
                $table->integer('sort_order')->default(0);
                $table->string('status', 16)->default('active');
                $table->timestamps();

                $table->unique('code', 'uk_skill_category_code');
            });
        }

        if (! Schema::hasTable('skill_tag')) {
            Schema::create('skill_tag', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 64);
                $table->string('type', 24)->default('scene'); // motion_type / scene / difficulty
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('skill_tag_pivot')) {
            Schema::create('skill_tag_pivot', function (Blueprint $table) {
                $table->unsignedInteger('skill_id');
                $table->unsignedInteger('tag_id');

                $table->primary(['skill_id', 'tag_id'], 'pk_skill_tag_pivot');
            });
        }

        if (! Schema::hasTable('skill_search_index')) {
            Schema::create('skill_search_index', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('skill_id');
                $table->unsignedInteger('version_id')->nullable();
                $table->string('name')->nullable();
                $table->string('brand', 64)->nullable();
                $table->json('model_codes')->nullable();
                $table->json('delivery_channels')->nullable();
                $table->string('resource_type', 24)->nullable();
                $table->string('risk_level', 10)->nullable();
                $table->json('tags')->nullable();
                $table->decimal('price', 12, 4)->nullable();
                $table->decimal('rating_avg', 3, 2)->nullable();
                $table->unsignedInteger('download_count')->default(0);
                $table->timestamp('updated_at')->nullable();

                $table->unique('skill_id', 'uk_search_index_skill');
                $table->index('resource_type', 'idx_search_resource_type');
                $table->index('risk_level', 'idx_search_risk');
                $table->index('brand', 'idx_search_brand');
            });
        }

        if (! Schema::hasTable('skill_build')) {
            Schema::create('skill_build', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('skill_version_id');
                $table->string('target_model_code', 64);        // → device_model.model_code
                $table->string('target_agent_type', 24)->nullable(); // unitree/agibot/engineai
                $table->string('status', 16)->default('pending');    // pending/building/success/failed
                $table->string('carrier_type', 24)->nullable();
                $table->unsignedInteger('artifact_asset_id')->nullable(); // → skill_assets.id
                $table->json('retarget_conf')->nullable();
                $table->text('build_log')->nullable();
                $table->string('error', 512)->nullable();
                $table->timestamp('built_at')->nullable();
                $table->timestamps();

                $table->index(['skill_version_id', 'target_model_code'], 'idx_build_version_model');
                $table->index('status', 'idx_build_status');
            });
        }

        if (! Schema::hasTable('skill_quality')) {
            Schema::create('skill_quality', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('skill_version_id');
                $table->decimal('sim_score', 5, 2)->nullable();
                $table->decimal('install_success_rate', 5, 2)->nullable();
                $table->decimal('rating_avg', 3, 2)->nullable();
                $table->decimal('uninstall_rate', 5, 2)->nullable();
                $table->string('grade', 16)->default('qualified'); // qualified/good/featured
                $table->timestamp('graded_at')->nullable();
                $table->timestamps();

                $table->index('skill_version_id', 'idx_quality_version');
                $table->index('grade', 'idx_quality_grade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_quality');
        Schema::dropIfExists('skill_build');
        Schema::dropIfExists('skill_search_index');
        Schema::dropIfExists('skill_tag_pivot');
        Schema::dropIfExists('skill_tag');
        Schema::dropIfExists('skill_category');
    }
};
