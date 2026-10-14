<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3.4 步骤 6 · 技能导入留痕(计划 §3.2 skill_import)。
 *
 * 记录"他平台包/动捕 → 中立 manifest"的映射过程,含无法自动映射的 warnings。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('skill_import')) {
            Schema::create('skill_import', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('seller_id')->nullable();
                $table->string('source_format', 32);        // unitree_unistore / bvh / smplx / lerobot / native
                $table->string('source_ref', 255)->nullable(); // 源地址/上传包名/外部技能 ID
                $table->string('status', 16)->default('pending'); // pending/parsing/mapped/failed/imported
                $table->json('raw_meta')->nullable();       // 源清单原文
                $table->json('mapped_manifest')->nullable();// 映射后的中立 manifest
                $table->json('warnings')->nullable();       // 无法自动映射的字段
                $table->unsignedInteger('skill_id')->nullable();
                $table->unsignedInteger('skill_version_id')->nullable();
                $table->string('error_msg', 512)->nullable();
                $table->timestamp('imported_at')->nullable();
                $table->timestamps();

                $table->index(['seller_id', 'status'], 'idx_import_seller_status');
                $table->index(['source_format', 'source_ref'], 'idx_import_source');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_import');
    }
};
