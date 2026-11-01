<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 0. 补齐 merchant_product_exts 的媒体、技术参数与安全库存字段
        if (Schema::hasTable('merchant_product_exts')) {
            Schema::table('merchant_product_exts', function (Blueprint $table) {
                if (! Schema::hasColumn('merchant_product_exts', 'safety_stock')) {
                    $table->unsignedInteger('safety_stock')->default(2)->after('maintenance_stock');
                }
                if (! Schema::hasColumn('merchant_product_exts', 'param_specs')) {
                    $table->json('param_specs')->nullable()->after('compatible_models');
                }
                if (! Schema::hasColumn('merchant_product_exts', 'media_items')) {
                    $table->json('media_items')->nullable()->after('param_specs');
                }
                if (! Schema::hasColumn('merchant_product_exts', 'rental_plans_snapshot')) {
                    $table->json('rental_plans_snapshot')->nullable()->after('media_items');
                }
                if (! Schema::hasColumn('merchant_product_exts', 'deposit_rule_snapshot')) {
                    $table->json('deposit_rule_snapshot')->nullable()->after('rental_plans_snapshot');
                }
            });
        }

        // 1. 多规格 SKU 表（SPU -> 多 SKU 价格/双轨库存/规格矩阵）
        if (! Schema::hasTable('merchant_product_skus')) {
            Schema::create('merchant_product_skus', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('product_id')->index();
                $table->unsignedBigInteger('seller_id')->index();
                $table->unsignedBigInteger('shop_id')->index();
                $table->string('sku_code', 64);
                $table->string('name', 160);
                $table->json('spec_json')->nullable(); // 例如 {"电池":"长续航版","保修":"2年驻场"}
                $table->unsignedBigInteger('price_cents')->default(0);
                $table->unsignedBigInteger('daily_rent_cents')->default(0);
                $table->unsignedBigInteger('deposit_cents')->default(0);
                $table->unsignedInteger('sellable_stock')->default(0);
                $table->unsignedInteger('rentable_stock')->default(0);
                $table->unsignedInteger('locked_stock')->default(0);
                $table->unsignedInteger('maintenance_stock')->default(0);
                $table->unsignedInteger('safety_stock')->default(1);
                $table->string('status', 32)->default('active')->index(); // active | disabled
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();

                $table->unique(['seller_id', 'sku_code'], 'uk_merchant_sku_seller_code');
            });
        }

        // 2. 租赁计划与阶梯价、押金规则表
        if (! Schema::hasTable('merchant_rental_plans')) {
            Schema::create('merchant_rental_plans', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('product_id')->index();
                $table->unsignedBigInteger('seller_id')->index();
                $table->unsignedBigInteger('shop_id')->index();
                $table->string('plan_code', 64);
                $table->string('name', 120); // 如：商业巡展周租计划 / 高校科研月租计划
                $table->string('period_unit', 32)->default('day'); // day | week | month | quarter
                $table->unsignedInteger('min_days')->default(1);
                $table->unsignedInteger('max_days')->default(365);
                $table->unsignedBigInteger('base_price_cents')->default(0); // 单周期基准租金（分）
                $table->json('price_tiers')->nullable(); // [{"min_days":7,"daily_cents":85000},{"min_days":30,"daily_cents":68000}]
                $table->json('deposit_rule')->nullable(); // {"mode":"full|ratio|credit_waiver","deposit_cents":500000,"overdue_daily_cents":30000,"buyout_price_cents":8900000}
                $table->string('contract_template_code', 64)->default('TPL-ROBOT-RENTAL-STD');
                $table->boolean('enabled')->default(true)->index();
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
            });
        }

        // 3. 商家多仓库表
        if (! Schema::hasTable('merchant_warehouses')) {
            Schema::create('merchant_warehouses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_id')->index();
                $table->unsignedBigInteger('shop_id')->index();
                $table->string('code', 64);
                $table->string('name', 120);
                $table->string('region', 120)->nullable();
                $table->string('address', 255)->nullable();
                $table->string('contact_name', 64)->nullable();
                $table->string('contact_phone', 32)->nullable();
                $table->boolean('is_default')->default(false);
                $table->string('status', 32)->default('active')->index();
                $table->timestamps();

                $table->unique(['seller_id', 'code'], 'uk_merchant_warehouse_seller_code');
            });
        }

        // 4. 库存锁定、盘点与维保占用流水台账表
        if (! Schema::hasTable('merchant_inventory_locks')) {
            Schema::create('merchant_inventory_locks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_id')->index();
                $table->unsignedBigInteger('shop_id')->index();
                $table->unsignedInteger('product_id')->index();
                $table->unsignedBigInteger('sku_id')->nullable()->index();
                $table->unsignedBigInteger('warehouse_id')->nullable()->index();
                $table->string('action_type', 40)->index(); // restock | stocktake | sale_lock | rental_lock | return_release | maintenance_lock | maintenance_release
                $table->integer('qty_change')->default(0);
                $table->unsignedInteger('sellable_after')->default(0);
                $table->unsignedInteger('rentable_after')->default(0);
                $table->unsignedInteger('locked_after')->default(0);
                $table->unsignedInteger('maintenance_after')->default(0);
                $table->string('reference_no', 64)->nullable()->index();
                $table->string('reason', 255)->nullable();
                $table->unsignedInteger('actor_id')->nullable();
                $table->timestamps();
            });
        }

        // 5. 商家履约订单与租赁全状态机表（售卖 + 租赁 + 合同 + 发货 + 归还 + 验机定损 + 押金结算）
        if (! Schema::hasTable('merchant_orders')) {
            Schema::create('merchant_orders', function (Blueprint $table) {
                $table->id();
                $table->string('order_no', 64)->unique();
                $table->unsignedInteger('bagisto_order_id')->nullable()->index();
                $table->unsignedBigInteger('seller_id')->index();
                $table->unsignedBigInteger('shop_id')->index();
                $table->unsignedInteger('product_id')->index();
                $table->unsignedBigInteger('sku_id')->nullable()->index();
                $table->unsignedBigInteger('rental_plan_id')->nullable()->index();
                $table->string('fulfillment_type', 32)->default('sale')->index(); // sale | rental
                $table->string('product_name', 191);
                $table->string('sku_code', 64);
                $table->string('buyer_name', 64);
                $table->string('buyer_phone', 32)->nullable();
                $table->string('shipping_address', 255)->nullable();
                $table->unsignedInteger('qty')->default(1);
                $table->unsignedInteger('rental_days')->default(1);
                $table->timestamp('rental_start_at')->nullable();
                $table->timestamp('rental_end_at')->nullable();
                $table->unsignedBigInteger('unit_price_cents')->default(0);
                $table->unsignedBigInteger('subtotal_cents')->default(0); // 货款或租金小计（分）
                $table->unsignedBigInteger('deposit_cents')->default(0); // 实收押金（分）
                $table->unsignedBigInteger('damage_deduct_cents')->default(0); // 验机定损扣款（分）
                $table->unsignedBigInteger('overdue_fee_cents')->default(0); // 逾期违约金（分）
                $table->unsignedBigInteger('refund_deposit_cents')->default(0); // 应退押金（分）
                $table->string('status', 40)->default('pending_shipment')->index();
                // sale: pending_shipment -> shipped -> completed / cancelled
                // rental: pending_shipment -> shipped -> in_use -> returned_inspecting -> completed
                $table->string('contract_no', 64)->nullable();
                $table->string('contract_status', 32)->default('signed'); // not_required | pending_sign | signed
                $table->timestamp('contract_signed_at')->nullable();
                $table->string('device_sn', 64)->nullable()->index(); // 出库绑定真机序列号 SN
                $table->string('carrier', 64)->nullable();
                $table->string('tracking_no', 64)->nullable();
                $table->timestamp('shipped_at')->nullable();
                $table->string('return_carrier', 64)->nullable();
                $table->string('return_tracking_no', 64)->nullable();
                $table->timestamp('returned_at')->nullable();
                $table->string('inspection_result', 32)->nullable(); // passed | minor_damage | major_damage
                $table->string('inspection_notes', 500)->nullable();
                $table->timestamp('inspected_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->boolean('settled')->default(false)->index();
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
            });
        }

        // 6. 售后退换修与设备维保工单表
        if (! Schema::hasTable('merchant_after_sales_tickets')) {
            Schema::create('merchant_after_sales_tickets', function (Blueprint $table) {
                $table->id();
                $table->string('ticket_no', 64)->unique();
                $table->unsignedBigInteger('seller_id')->index();
                $table->unsignedBigInteger('shop_id')->index();
                $table->unsignedBigInteger('merchant_order_id')->nullable()->index();
                $table->unsignedInteger('product_id')->index();
                $table->string('ticket_type', 32)->default('repair')->index(); // return_refund | exchange | repair | maintenance
                $table->string('device_sn', 64)->nullable();
                $table->string('requester_name', 64)->nullable();
                $table->string('reason', 500);
                $table->string('status', 32)->default('open')->index(); // open | processing | resolved | rejected
                $table->string('resolution_notes', 500)->nullable();
                $table->unsignedBigInteger('refund_cents')->default(0); // 售后退款（分）
                $table->unsignedBigInteger('compensation_cents')->default(0); // 客户赔偿（分）
                $table->boolean('locks_maintenance_stock')->default(false);
                $table->boolean('maintenance_released')->default(false);
                $table->unsignedInteger('handled_by')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
            });
        }

        // 7. 结算单与结算明细、发票台账表
        if (! Schema::hasTable('merchant_settlements')) {
            Schema::create('merchant_settlements', function (Blueprint $table) {
                $table->id();
                $table->string('settlement_no', 64)->unique();
                $table->unsignedBigInteger('seller_id')->index();
                $table->unsignedBigInteger('shop_id')->index();
                $table->string('period_label', 64); // 例如 2026-11 结算周期
                $table->unsignedInteger('orders_count')->default(0);
                $table->unsignedBigInteger('gross_amount_cents')->default(0); // 订单货款/租金总额（分）
                $table->unsignedBigInteger('damage_income_cents')->default(0); // 定损赔偿收入（分）
                $table->unsignedBigInteger('platform_fee_cents')->default(0); // 平台服务费（分，默认 5%）
                $table->unsignedBigInteger('refund_deduct_cents')->default(0); // 售后退款扣减（分）
                $table->bigInteger('net_amount_cents')->default(0); // 应结净额（分）
                $table->string('status', 32)->default('pending_confirm')->index(); // pending_confirm | confirmed | paid
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('merchant_settlement_items')) {
            Schema::create('merchant_settlement_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('settlement_id')->index();
                $table->unsignedBigInteger('seller_id')->index();
                $table->unsignedBigInteger('merchant_order_id')->unique();
                $table->string('order_no', 64);
                $table->string('fulfillment_type', 32);
                $table->unsignedBigInteger('gross_cents')->default(0);
                $table->unsignedBigInteger('damage_income_cents')->default(0);
                $table->unsignedBigInteger('platform_fee_cents')->default(0);
                $table->bigInteger('net_cents')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('merchant_invoices')) {
            Schema::create('merchant_invoices', function (Blueprint $table) {
                $table->id();
                $table->string('invoice_no', 64)->unique();
                $table->unsignedBigInteger('seller_id')->index();
                $table->unsignedBigInteger('shop_id')->index();
                $table->unsignedBigInteger('settlement_id')->nullable()->index();
                $table->unsignedBigInteger('merchant_order_id')->nullable()->index();
                $table->string('direction', 32)->default('outbound'); // outbound(向买家开票) | platform_fee(平台服务费发票)
                $table->string('invoice_type', 32)->default('vat_special'); // vat_normal | vat_special
                $table->string('buyer_title', 191);
                $table->string('buyer_tax_no', 64)->nullable();
                $table->unsignedBigInteger('amount_cents')->default(0);
                $table->string('tax_rate', 16)->default('6%');
                $table->string('invoice_code_number', 64)->nullable(); // 税控发票代码+号码
                $table->string('status', 32)->default('pending')->index(); // pending | issued | voided
                $table->timestamp('issued_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_invoices');
        Schema::dropIfExists('merchant_settlement_items');
        Schema::dropIfExists('merchant_settlements');
        Schema::dropIfExists('merchant_after_sales_tickets');
        Schema::dropIfExists('merchant_orders');
        Schema::dropIfExists('merchant_inventory_locks');
        Schema::dropIfExists('merchant_warehouses');
        Schema::dropIfExists('merchant_rental_plans');
        Schema::dropIfExists('merchant_product_skus');
    }
};
