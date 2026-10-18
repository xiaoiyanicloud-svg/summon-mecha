<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3 · 适配证据链第 2 环 —— 真机/仿真/静态/人工四类证据。
 *
 * 一条记录 = 一次"某版本在某机型/固件/SDK/Agent 组合上被验证过"的事实，
 * 并钉住当时用于验证的产物（asset_id + asset_hash）。
 *
 * ⚠ `source=device` **只表示上报来源**，平台无法核验上报者真的是那台机器 ——
 *   这与 `DeviceBinding` 自报同级的诚实边界。对外文案必须据此降级，
 *   不得把 simulation/manual 说成"真机已验证"。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('skill_compatibility_evidence')) {
            Schema::create('skill_compatibility_evidence', function (Blueprint $table) {
                $table->increments('id');

                // 跨表引用一律 unsignedInteger，不建外键（与既有 skill_* 表一致）
                $table->unsignedInteger('skill_version_id');
                $table->unsignedInteger('product_id')->nullable();

                $table->string('device_did', 64)->nullable();
                $table->string('model_code', 64);
                $table->string('development_version', 64)->nullable();
                $table->string('firmware_version', 64)->nullable();
                $table->string('sdk_version', 64)->nullable();
                $table->string('agent_version', 32)->nullable();

                $table->string('carrier_type', 32)->nullable();
                $table->unsignedInteger('asset_id')->nullable();
                $table->string('asset_hash', 64)->nullable();

                // device / simulation / static / manual
                $table->string('source', 16);
                // install / runtime / lab_test / manual_review
                $table->string('evidence_type', 24);
                // match / mismatch / inconclusive
                $table->string('result', 16);

                $table->string('summary', 1000)->nullable();
                $table->string('verified_by', 64)->nullable();
                $table->timestamp('observed_at')->nullable();

                $table->timestamps();

                $table->index(['skill_version_id', 'model_code', 'result'], 'idx_sce_version_model_result');
                $table->index('asset_id', 'idx_sce_asset');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_compatibility_evidence');
    }
};