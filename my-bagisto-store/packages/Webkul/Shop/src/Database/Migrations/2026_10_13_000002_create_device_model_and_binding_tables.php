<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3.4 步骤 3 · 设备两表(计划 §3.2)。
 *
 *  device_model   —— 机型库(兼容性校验的输入;种子见 docs/device-model-seed.md)
 *  device_binding —— 全局设备绑定(设备唯一真相源;下发只认它,见 §2.2 v3.3 定案)
 *
 * ⚠ compute_platform / firmware_versions 允许"未知"(nullable)——多数厂商不公开,校验逻辑须处理未知。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('device_model')) {
            Schema::create('device_model', function (Blueprint $table) {
                $table->increments('id');
                $table->string('brand', 64);
                $table->string('model_code', 64);          // 如 unitree_g1 / agibot_a2 / engineai_pm01
                $table->string('model_name');
                $table->string('vendor_code', 32)->nullable(); // 厂商 API 通道用
                $table->unsignedInteger('degrees_of_freedom')->nullable();
                $table->string('compute_platform', 64)->nullable(); // 允许"未知"
                $table->json('comm_channels')->nullable();  // ["agent","vendor","manual"]
                $table->json('firmware_versions')->nullable(); // 允许"未知"
                $table->string('min_agent_version', 32)->nullable();
                $table->string('icon')->nullable();
                $table->string('status', 16)->default('active');
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->unique('model_code', 'uk_device_model_code');
                $table->index('brand', 'idx_device_model_brand');
                $table->index('status', 'idx_device_model_status');
            });
        }

        if (! Schema::hasTable('device_binding')) {
            Schema::create('device_binding', function (Blueprint $table) {
                $table->increments('id');
                // did: Agent首联 did_{hash} / 厂商 vendor_{code}_{sn} / 人工 sn_{sn}
                $table->string('did', 64);
                // 工信部"一机一码"(29 位)—— 外部锚点,与行业对齐(计划 §10 结论2)
                $table->string('national_code', 40)->nullable();
                $table->string('source', 16)->default('manual');   // agent / vendor / manual
                $table->string('vendor_code', 32)->nullable();
                $table->string('sn', 64)->nullable();
                $table->unsignedInteger('model_id')->nullable();    // → device_model.id
                $table->unsignedInteger('owner_customer_id')->nullable();
                $table->unsignedInteger('devices_id')->nullable();  // 关联用户登记记录(可空)
                $table->string('bind_status', 16)->default('bound'); // bound/unbound/transferring
                $table->tinyInteger('online_status')->default(0);
                $table->string('online_channel', 16)->nullable();
                $table->timestamp('last_online_at')->nullable();
                $table->timestamp('bound_at')->nullable();
                $table->timestamp('unbound_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique('did', 'uk_device_binding_did');
                $table->index('owner_customer_id', 'idx_device_binding_owner');
                $table->index('model_id', 'idx_device_binding_model');
                $table->index('sn', 'idx_device_binding_sn');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_binding');
        Schema::dropIfExists('device_model');
    }
};
