<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3.4 · 补齐计划里的数据模型（批次 1/3）：安全/审计/财务/合规。
 *
 * 之前只建了步骤 2–7 用到的表；这些是计划 §3.2/§3.3 里列过但没落的。全部新增、带守卫。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 风险告知确认（§4.14 L3）
        if (! Schema::hasTable('risk_acknowledgment')) {
            Schema::create('risk_acknowledgment', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('customer_id');
                $table->unsignedInteger('entitlement_id')->nullable();
                $table->unsignedInteger('skill_version_id')->nullable();
                $table->string('channel', 24)->nullable();
                $table->json('ack_payload')->nullable();
                $table->string('ip', 45)->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        // 操作审计（§3.2）
        if (! Schema::hasTable('operation_log')) {
            Schema::create('operation_log', function (Blueprint $table) {
                $table->increments('id');
                $table->string('actor_type', 16); // customer/admin/seller/system
                $table->unsignedInteger('actor_id')->nullable();
                $table->string('action', 64);
                $table->string('target_type', 32)->nullable();
                $table->unsignedInteger('target_id')->nullable();
                $table->json('payload')->nullable();
                $table->string('ip', 45)->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['actor_type', 'actor_id', 'created_at'], 'idx_oplog_actor');
                $table->index(['target_type', 'target_id'], 'idx_oplog_target');
            });
        }

        // 内容安全检测（§4.16）
        if (! Schema::hasTable('content_security_record')) {
            Schema::create('content_security_record', function (Blueprint $table) {
                $table->increments('id');
                $table->string('target_type', 32); // skill_meta/icon/preview/review
                $table->unsignedInteger('target_id')->nullable();
                $table->string('provider', 32)->nullable();
                $table->string('scan_type', 16)->nullable(); // text/image/video
                $table->string('result', 16)->nullable();     // pass/review/block
                $table->json('labels')->nullable();
                $table->json('raw')->nullable();
                $table->timestamp('scanned_at')->nullable();
                $table->timestamps();

                $table->index(['target_type', 'target_id'], 'idx_cs_target');
            });
        }

        // 事故上报与追溯（§4.19）
        if (! Schema::hasTable('incident')) {
            Schema::create('incident', function (Blueprint $table) {
                $table->increments('id');
                $table->string('incident_no', 40);
                $table->unsignedInteger('skill_id')->nullable();
                $table->unsignedInteger('skill_version_id')->nullable();
                $table->unsignedInteger('device_binding_id')->nullable();
                $table->unsignedInteger('customer_id')->nullable();
                $table->string('severity', 16)->nullable();
                $table->timestamp('occurred_at')->nullable();
                $table->text('description')->nullable();
                $table->json('evidence')->nullable();
                $table->string('responsibility', 16)->nullable(); // developer/platform/vendor/user/unknown
                $table->json('trace')->nullable();
                $table->string('status', 16)->default('reported');
                $table->json('insurance_claim')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->unique('incident_no', 'uk_incident_no');
                $table->index('status', 'idx_incident_status');
            });
        }

        // 备案台账（§4.17）
        if (! Schema::hasTable('compliance_filing')) {
            Schema::create('compliance_filing', function (Blueprint $table) {
                $table->increments('id');
                $table->string('filing_type', 32); // algorithm/llm/affective_interaction/icp
                $table->string('name');
                $table->string('filing_no', 64)->nullable();
                $table->string('authority')->nullable();
                $table->string('scope')->nullable();
                $table->timestamp('filed_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('annual_review_at')->nullable();
                $table->string('status', 16)->default('pending');
                $table->json('attachments')->nullable();
                $table->timestamps();
            });
        }

        // 结算 / 提现 / 对账 / 发票 / 厂商配置
        if (! Schema::hasTable('settlement')) {
            Schema::create('settlement', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('seller_id');
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->json('order_item_snapshot')->nullable();
                $table->decimal('gross', 12, 4)->default(0);
                $table->decimal('commission_rate', 5, 2)->nullable();
                $table->decimal('commission', 12, 4)->default(0);
                $table->decimal('net', 12, 4)->default(0);
                $table->string('status', 16)->default('pending');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('withdrawal')) {
            Schema::create('withdrawal', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('seller_id');
                $table->json('settlement_ids')->nullable();
                $table->decimal('amount', 12, 4)->default(0);
                $table->json('account')->nullable();
                $table->string('status', 16)->default('applied');
                $table->unsignedInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('reconciliation')) {
            Schema::create('reconciliation', function (Blueprint $table) {
                $table->increments('id');
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->string('channel', 24)->nullable();
                $table->decimal('platform_amount', 12, 4)->default(0);
                $table->decimal('wechat_amount', 12, 4)->default(0);
                $table->decimal('diff', 12, 4)->default(0);
                $table->string('status', 16)->default('matched');
                $table->json('detail')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('invoice')) {
            Schema::create('invoice', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('customer_id');
                $table->json('order_ids')->nullable();
                $table->string('type', 16)->default('personal');
                $table->string('title')->nullable();
                $table->string('tax_no', 32)->nullable();
                $table->decimal('amount', 12, 4)->default(0);
                $table->string('status', 16)->default('applied');
                $table->string('file_path')->nullable();
                $table->timestamp('issued_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('vendor_config')) {
            Schema::create('vendor_config', function (Blueprint $table) {
                $table->increments('id');
                $table->string('vendor_code', 32);
                $table->string('vendor_name')->nullable();
                $table->string('auth_type', 16)->nullable(); // oauth2/hmac
                $table->string('api_base_url')->nullable();
                $table->string('app_id')->nullable();
                $table->text('app_secret')->nullable();
                $table->string('callback_url')->nullable();
                $table->string('status', 16)->default('inactive');
                $table->timestamps();

                $table->unique('vendor_code', 'uk_vendor_code');
            });
        }
    }

    public function down(): void
    {
        foreach (['vendor_config', 'invoice', 'reconciliation', 'withdrawal', 'settlement', 'compliance_filing', 'incident', 'content_security_record', 'operation_log', 'risk_acknowledgment'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
