<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. 统一后台注册表（支持动态覆盖 config/portal_backends.php 或扩展第 N 个后台）
        if (! Schema::hasTable('portal_backend_registry')) {
            Schema::create('portal_backend_registry', function (Blueprint $table) {
                $table->id();
                $table->string('code', 64)->unique();
                $table->string('name', 120);
                $table->string('badge', 64)->nullable();
                $table->string('description', 500)->nullable();
                $table->string('icon', 64)->default('🖥️');
                $table->string('route_url', 255);
                $table->json('permissions')->nullable();
                $table->boolean('enabled')->default(true)->index();
                $table->string('disabled_reason', 255)->nullable();
                $table->integer('sort')->default(100)->index();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        // 2. 商家主体档案（customer_id 唯一，与 developer_profile 同构且独立）
        if (! Schema::hasTable('merchant_profiles')) {
            Schema::create('merchant_profiles', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('customer_id')->unique();
                $table->unsignedBigInteger('default_seller_id')->nullable()->index();
                $table->unsignedBigInteger('coop_application_id')->nullable()->index();
                $table->string('invite_code', 64)->nullable();
                $table->string('merchant_type', 32)->default('company'); // company | personal
                $table->string('company_name', 191)->nullable();
                $table->string('credit_code', 64)->nullable();
                $table->string('contact_name', 64)->nullable();
                $table->string('contact_phone', 32)->nullable();
                $table->string('realname_status', 32)->default('pending')->index(); // pending | approved | rejected
                $table->string('status', 32)->default('active')->index(); // active | suspended
                $table->string('timezone', 64)->default('Asia/Shanghai');
                $table->timestamp('agreement_signed_at')->nullable();
                $table->json('cert_payload')->nullable();
                $table->timestamps();
            });
        }

        // 3. 商家店铺表（1 个店铺 1:1 绑定 1 个 sellers.id 作为强租户隔离锚点）
        if (! Schema::hasTable('merchant_shops')) {
            Schema::create('merchant_shops', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_id')->unique();
                $table->unsignedBigInteger('merchant_profile_id')->index();
                $table->string('code', 64)->unique();
                $table->string('name', 191);
                $table->string('description', 500)->nullable();
                $table->json('business_scopes')->nullable(); // ["sale_robot","rental_robot","accessory"]
                $table->string('timezone', 64)->default('Asia/Shanghai');
                $table->string('status', 32)->default('active')->index(); // active | suspended
                $table->timestamps();
            });
        }

        // 4. 商家店铺成员与 RBAC 角色绑定（支持同一 customer 加入多个店铺并切换）
        if (! Schema::hasTable('merchant_members')) {
            Schema::create('merchant_members', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shop_id')->index();
                $table->unsignedBigInteger('seller_id')->index();
                $table->unsignedInteger('customer_id')->index();
                $table->string('role', 32)->default('owner'); // owner | admin | catalog_ops | warehouse | finance
                $table->json('permissions')->nullable();
                $table->string('status', 32)->default('active')->index(); // active | disabled
                $table->timestamps();

                $table->unique(['shop_id', 'customer_id'], 'uk_merchant_members_shop_customer');
            });
        }

        // 5. 商家商品扩展表（1:1 绑定 products.id，承接租售类型、整数分价格/押金、双轨库存与审核上下架状态机）
        if (! Schema::hasTable('merchant_product_exts')) {
            Schema::create('merchant_product_exts', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('product_id')->unique();
                $table->unsignedBigInteger('seller_id')->index();
                $table->unsignedBigInteger('shop_id')->index();
                $table->string('product_type', 32)->default('sale_robot')->index(); // sale_robot | rental_robot | accessory
                $table->string('fulfillment_type', 32)->default('sale'); // sale | rental | both
                $table->json('compatible_models')->nullable(); // 关联 device_model.model_code
                $table->unsignedBigInteger('price_cents')->default(0); // 售卖价（整数分）
                $table->unsignedBigInteger('daily_rent_cents')->default(0); // 日租金（整数分）
                $table->unsignedBigInteger('deposit_cents')->default(0); // 租赁押金（整数分）
                $table->unsignedInteger('min_rent_days')->default(1);
                $table->unsignedInteger('sellable_stock')->default(0); // 可售标品库存
                $table->unsignedInteger('rentable_stock')->default(0); // 可租排期库存
                $table->unsignedInteger('locked_stock')->default(0); // 订单锁定/在租库存
                $table->unsignedInteger('maintenance_stock')->default(0); // 维保锁定库存
                $table->string('review_status', 32)->default('draft')->index(); // draft | in_review | approved | rejected
                $table->string('shelf_status', 32)->default('off_shelf')->index(); // off_shelf | on_shelf
                $table->string('reject_reason', 500)->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('on_shelf_at')->nullable();
                $table->unsignedInteger('version')->default(1); // 乐观锁版本号
                $table->timestamps();

                $table->index(['seller_id', 'review_status', 'shelf_status'], 'idx_mpe_seller_review_shelf');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_product_exts');
        Schema::dropIfExists('merchant_members');
        Schema::dropIfExists('merchant_shops');
        Schema::dropIfExists('merchant_profiles');
        Schema::dropIfExists('portal_backend_registry');
    }
};
