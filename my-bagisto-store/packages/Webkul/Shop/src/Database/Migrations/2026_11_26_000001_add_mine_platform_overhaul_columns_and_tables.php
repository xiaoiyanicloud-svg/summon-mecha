<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * “我的”中心平台化升级（全栈加性迁移 · 幂等安全）：
 *
 * 1. customers 补充企业客户档案与注销申请留痕列（company_name / job_title / deletion_requested_at / deletion_reason）
 * 2. invoice 补充 ToB 增值税普票/专票四要素与收票渠道列
 * 3. customer_invoice_headers 新增常用发票抬头簿表
 * 4. after_sale 补充全品类域标识（skill / order）、审核反馈、撤回与 D10 申诉列
 * 5. portal_handoff_tickets 新增跨端单次短效免登交换票据表（App/H5 Token -> /merchant & /dev Web Session）
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                if (! Schema::hasColumn('customers', 'company_name')) {
                    $table->string('company_name', 191)->nullable();
                }
                if (! Schema::hasColumn('customers', 'job_title')) {
                    $table->string('job_title', 96)->nullable();
                }
                if (! Schema::hasColumn('customers', 'deletion_requested_at')) {
                    $table->timestamp('deletion_requested_at')->nullable();
                }
                if (! Schema::hasColumn('customers', 'deletion_reason')) {
                    $table->string('deletion_reason', 500)->nullable();
                }
            });
        }

        if (Schema::hasTable('invoice')) {
            Schema::table('invoice', function (Blueprint $table) {
                if (! Schema::hasColumn('invoice', 'invoice_kind')) {
                    $table->string('invoice_kind', 16)->default('ordinary'); // ordinary | special
                }
                if (! Schema::hasColumn('invoice', 'receive_email')) {
                    $table->string('receive_email', 191)->nullable();
                }
                if (! Schema::hasColumn('invoice', 'receive_phone')) {
                    $table->string('receive_phone', 32)->nullable();
                }
                if (! Schema::hasColumn('invoice', 'bank_name')) {
                    $table->string('bank_name', 128)->nullable();
                }
                if (! Schema::hasColumn('invoice', 'bank_account')) {
                    $table->string('bank_account', 64)->nullable();
                }
                if (! Schema::hasColumn('invoice', 'company_address')) {
                    $table->string('company_address', 255)->nullable();
                }
                if (! Schema::hasColumn('invoice', 'company_phone')) {
                    $table->string('company_phone', 64)->nullable();
                }
            });
        }

        if (! Schema::hasTable('customer_invoice_headers')) {
            Schema::create('customer_invoice_headers', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('customer_id');
                $table->string('type', 16)->default('company'); // personal | company
                $table->string('invoice_kind', 16)->default('ordinary'); // ordinary | special
                $table->string('title', 128);
                $table->string('tax_no', 32)->nullable();
                $table->string('receive_email', 191)->nullable();
                $table->string('receive_phone', 32)->nullable();
                $table->string('bank_name', 128)->nullable();
                $table->string('bank_account', 64)->nullable();
                $table->string('company_address', 255)->nullable();
                $table->string('company_phone', 64)->nullable();
                $table->boolean('is_default')->default(false);
                $table->timestamps();

                $table->index(['customer_id', 'is_default'], 'idx_inv_header_customer_def');
            });
        }

        if (Schema::hasTable('after_sale')) {
            Schema::table('after_sale', function (Blueprint $table) {
                if (! Schema::hasColumn('after_sale', 'target_domain')) {
                    $table->string('target_domain', 24)->default('skill'); // skill | order
                }
                if (! Schema::hasColumn('after_sale', 'review_note')) {
                    $table->text('review_note')->nullable();
                }
                if (! Schema::hasColumn('after_sale', 'appeal_detail')) {
                    $table->text('appeal_detail')->nullable();
                }
                if (! Schema::hasColumn('after_sale', 'appealed_at')) {
                    $table->timestamp('appealed_at')->nullable();
                }
                if (! Schema::hasColumn('after_sale', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable();
                }
            });
        }

        if (! Schema::hasTable('portal_handoff_tickets')) {
            Schema::create('portal_handoff_tickets', function (Blueprint $table) {
                $table->increments('id');
                $table->string('ticket_hash', 64)->unique('uk_portal_handoff_hash');
                $table->unsignedInteger('customer_id');
                $table->string('backend_code', 64);
                $table->string('redirect_path', 255)->nullable();
                $table->string('issued_ip', 45)->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('consumed_at')->nullable();
                $table->string('consumed_ip', 45)->nullable();
                $table->timestamps();

                $table->index(['customer_id', 'expires_at'], 'idx_portal_handoff_cust_exp');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_handoff_tickets');
        Schema::dropIfExists('customer_invoice_headers');

        if (Schema::hasTable('after_sale')) {
            Schema::table('after_sale', function (Blueprint $table) {
                foreach (['target_domain', 'review_note', 'appeal_detail', 'appealed_at', 'cancelled_at'] as $col) {
                    if (Schema::hasColumn('after_sale', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('invoice')) {
            Schema::table('invoice', function (Blueprint $table) {
                foreach (['invoice_kind', 'receive_email', 'receive_phone', 'bank_name', 'bank_account', 'company_address', 'company_phone'] as $col) {
                    if (Schema::hasColumn('invoice', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                foreach (['company_name', 'job_title', 'deletion_requested_at', 'deletion_reason'] as $col) {
                    if (Schema::hasColumn('customers', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
