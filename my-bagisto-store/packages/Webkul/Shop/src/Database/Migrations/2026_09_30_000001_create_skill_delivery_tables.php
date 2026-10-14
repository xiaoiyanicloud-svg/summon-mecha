<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 技能交易的供需底座。
 *
 * 职责切分(不要混):
 *   products (simple + skill 分类) = 卖什么、多少钱、怎么展示
 *   skill_versions / skill_assets   = 交付什么、能下发什么
 *   skill_entitlements              = 谁拥有什么(唯一事实源)
 *   devices                         = 用户的机器实例
 *
 * 归属字段(每张表都有 seller_id, NULL = 平台自营)是为将来接多商户预留的。
 * 现在全库为 NULL,行为与不引入该列时完全一致。
 *
 * 注意几个刻意的选择:
 *  - 全部用普通索引不用外键约束:本仓库的订单会被 Bagisto 的重试/取消逻辑反复重建,
 *    加 FK 容易在删单路径上炸,收益不抵风险。
 *  - skill_entitlements.order_item_id 建唯一键,是"一个订单条目只发一次授权"的幂等兜底
 *    (建单监听器会在事务内触发,且 OrderRepository 失败会重试最多 2 次)。
 *  - devices 的唯一键是 (customer_id, sn) 而不是 sn:SN 由用户自填,全局唯一会让人抢注别人的 SN。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasTable('sellers')) {
            Schema::create('sellers', function (Blueprint $table) {
                $table->increments('id');
                $table->string('code', 64);
                $table->string('name');
                $table->string('status', 16)->default('active');
                $table->unsignedInteger('owner_customer_id')->nullable();
                $table->decimal('commission_rate', 5, 2)->nullable();
                $table->timestamps();

                $table->unique('code', 'uk_sellers_code');
            });
        }

        if (! Schema::hasTable('skill_versions')) {
            Schema::create('skill_versions', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('product_id');
                $table->unsignedInteger('seller_id')->nullable();
                $table->string('version', 32)->default('1.0.0');
                $table->string('channel', 16)->default('stable');
                $table->string('status', 16)->default('draft');
                $table->string('license_mode', 24)->default('per_device');
                $table->unsignedInteger('max_activations')->nullable();
                $table->unsignedInteger('valid_days')->nullable();
                $table->string('min_robot_version', 32)->nullable();
                $table->text('changelog')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->timestamps();

                $table->unique(['product_id', 'version'], 'uk_skill_versions_product_version');
                $table->index('status', 'idx_skill_versions_status');
            });
        }

        if (! Schema::hasTable('skill_assets')) {
            Schema::create('skill_assets', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('skill_version_id');
                $table->unsignedInteger('seller_id')->nullable();
                $table->string('carrier_type', 24);
                $table->string('file_path')->nullable();
                $table->string('file_name')->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->char('file_hash', 64)->nullable();
                $table->json('payload')->nullable();
                $table->string('entrypoint')->nullable();
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->unique(
                    ['skill_version_id', 'carrier_type', 'sort_order'],
                    'uk_skill_assets_version_carrier'
                );
            });
        }

        if (! Schema::hasTable('devices')) {
            Schema::create('devices', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('customer_id');
                $table->string('sn', 64);
                $table->unsignedInteger('product_id')->nullable();
                $table->string('model_code', 64)->nullable();
                $table->string('display_name')->nullable();
                $table->unsignedInteger('seller_id')->nullable();
                $table->string('bind_status', 16)->default('bound');
                $table->timestamp('bound_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->string('firmware_version', 32)->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['customer_id', 'sn'], 'uk_devices_owner_sn');
                $table->index('product_id', 'idx_devices_product');
            });
        }

        if (! Schema::hasTable('skill_entitlements')) {
            Schema::create('skill_entitlements', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('customer_id');
                $table->unsignedInteger('order_id');
                $table->unsignedInteger('order_item_id');
                $table->unsignedInteger('product_id');
                $table->unsignedInteger('skill_version_id')->nullable();
                $table->unsignedInteger('device_id')->nullable();
                $table->unsignedInteger('seller_id')->nullable();
                $table->string('status', 16)->default('pending_bind');
                $table->char('license_key', 32)->nullable();
                $table->string('delivery_channel', 24)->default('app');
                $table->unsignedInteger('activations')->default(0);
                $table->unsignedInteger('max_activations')->nullable();
                $table->timestamp('granted_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                // 幂等:一个订单条目只发一次授权
                $table->unique('order_item_id', 'uk_skill_entitlements_order_item');
                $table->index('customer_id', 'idx_skill_entitlements_customer');
                $table->index('device_id', 'idx_skill_entitlements_device');
                $table->index('product_id', 'idx_skill_entitlements_product');
            });
        }

        if (! Schema::hasColumn('products', 'seller_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unsignedInteger('seller_id')->nullable()->after('type');
                $table->index('seller_id', 'idx_products_seller');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('products', 'seller_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex('idx_products_seller');
                $table->dropColumn('seller_id');
            });
        }

        Schema::dropIfExists('skill_entitlements');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('skill_assets');
        Schema::dropIfExists('skill_versions');
        Schema::dropIfExists('sellers');
    }
};
