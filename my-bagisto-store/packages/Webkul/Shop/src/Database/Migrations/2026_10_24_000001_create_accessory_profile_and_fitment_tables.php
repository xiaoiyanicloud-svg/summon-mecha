<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 配件皮肤 · P1 数据地基。
 *
 * 为什么单独建两张表，而不是把字段塞进 Bagisto 属性族：
 *  - 配件字段（安装方式/包装清单/质保/交期/定制起价）是**配件域**的语义，
 *    塞进全局属性族会让所有商品都多出一组无关字段；
 *  - 适配是「商品 × 机型」的二维关系，属性表表达不了；
 *  - 两张表都是 additive，不修改 products / product_flat 的任何列。
 *
 * 适配的机型真相源是 `device_model.model_code`（与技能兼容性同一口径），
 * 不另建品牌/机型字典。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accessory_profiles')) {
            Schema::create('accessory_profiles', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('product_id');
                $table->string('summary', 255)->nullable();
                $table->string('install_method', 16)->default('self');   // self / engineer / store
                $table->text('package_contents')->nullable();
                $table->unsignedSmallInteger('warranty_months')->nullable();
                $table->unsignedSmallInteger('lead_time_days')->nullable();
                $table->string('material', 64)->nullable();
                $table->boolean('official')->default(false);
                $table->boolean('custom_service')->default(false);
                $table->decimal('custom_price_from', 12, 4)->nullable();
                $table->text('compatibility_note')->nullable();
                $table->string('power_requirement', 128)->nullable();
                $table->timestamps();

                $table->unique('product_id', 'uk_accessory_profile_product');
            });
        }

        if (! Schema::hasTable('accessory_fitments')) {
            Schema::create('accessory_fitments', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('product_id');
                $table->string('model_code', 64);                         // → device_model.model_code
                $table->string('fit_level', 16);                          // direct / adapter / custom / unsupported
                $table->string('adapter_sku', 64)->nullable();
                $table->string('note', 255)->nullable();
                $table->string('source', 16)->default('platform');        // vendor / platform / measured
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();

                $table->unique(['product_id', 'model_code'], 'uk_accessory_fitment_product_model');
                $table->index(['model_code', 'fit_level'], 'idx_accessory_fitment_model_level');
                $table->index('product_id', 'idx_accessory_fitment_product');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accessory_fitments');
        Schema::dropIfExists('accessory_profiles');
    }
};
