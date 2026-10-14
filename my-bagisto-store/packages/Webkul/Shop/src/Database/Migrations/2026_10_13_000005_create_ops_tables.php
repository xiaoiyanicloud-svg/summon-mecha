<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3.4 步骤 3 · 运营与售后表(计划 §3.3) + 索引补丁。
 *
 *  notification / notify_template —— 通知中心(站内信 + 微信订阅消息)
 *  after_sale / refund_request    —— 售后与退款(虚拟商品政策)
 *
 *  索引补丁:skill_entitlements 补 (status, expires_at)
 *    —— 试用回收每分钟扫描若靠 meta.trial(JSON)会全表扫,必须走物理列/索引。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notify_template')) {
            Schema::create('notify_template', function (Blueprint $table) {
                $table->increments('id');
                $table->string('code', 64);
                $table->string('scene', 64)->nullable();
                $table->string('title_tpl')->nullable();
                $table->text('content_tpl')->nullable();
                $table->string('channel', 24)->default('inapp'); // inapp / subscribe_msg / sms
                $table->string('status', 16)->default('active');
                $table->timestamps();

                $table->unique('code', 'uk_notify_template_code');
            });
        }

        if (! Schema::hasTable('notification')) {
            Schema::create('notification', function (Blueprint $table) {
                $table->increments('id');
                $table->string('recipient_type', 16);   // customer / developer / admin
                $table->unsignedInteger('recipient_id');
                $table->string('type', 64)->nullable();
                $table->string('title')->nullable();
                $table->text('content')->nullable();
                $table->json('payload')->nullable();
                $table->string('channel', 24)->default('inapp');
                $table->timestamp('read_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->index(['recipient_type', 'recipient_id', 'read_at'], 'idx_notify_recipient_read');
            });
        }

        if (! Schema::hasTable('after_sale')) {
            Schema::create('after_sale', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('order_id')->nullable();
                $table->unsignedInteger('order_item_id')->nullable();
                $table->unsignedInteger('entitlement_id')->nullable();
                $table->unsignedInteger('customer_id')->nullable();
                $table->string('type', 16)->default('refund');  // refund / complaint
                $table->string('reason_category', 64)->nullable();
                $table->text('detail')->nullable();
                $table->string('policy_basis', 64)->nullable(); // 退款政策依据
                $table->string('status', 16)->default('applied'); // applied/reviewing/approved/rejected/refunded
                $table->unsignedInteger('reviewer_id')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->index('customer_id', 'idx_aftersale_customer');
                $table->index('status', 'idx_aftersale_status');
                $table->index('entitlement_id', 'idx_aftersale_entitlement');
            });
        }

        if (! Schema::hasTable('refund_request')) {
            Schema::create('refund_request', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('after_sale_id')->nullable();
                $table->string('wechat_refund_no', 64)->nullable();
                $table->decimal('amount', 12, 4)->nullable();
                $table->string('status', 16)->default('pending');
                $table->timestamp('callback_at')->nullable();
                $table->timestamps();

                $table->index('after_sale_id', 'idx_refund_aftersale');
            });
        }

        // 索引补丁:试用回收扫描走 (status, expires_at),不要靠 JSON 过滤
        if (Schema::hasTable('skill_entitlements')) {
            Schema::table('skill_entitlements', function (Blueprint $table) {
                $table->index(['status', 'expires_at'], 'idx_entitlements_status_expires');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('skill_entitlements')) {
            Schema::table('skill_entitlements', function (Blueprint $table) {
                $table->dropIndex('idx_entitlements_status_expires');
            });
        }

        Schema::dropIfExists('refund_request');
        Schema::dropIfExists('after_sale');
        Schema::dropIfExists('notification');
        Schema::dropIfExists('notify_template');
    }
};
