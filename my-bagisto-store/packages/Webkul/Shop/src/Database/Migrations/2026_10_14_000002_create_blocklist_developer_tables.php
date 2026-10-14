<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3.4 · 补齐数据模型（批次 2/3）：熔断/灰度/开发者/评价/举报。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 熔断 / 紧急下架（§4.19）
        if (! Schema::hasTable('skill_blocklist')) {
            Schema::create('skill_blocklist', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('skill_id');
                $table->unsignedInteger('skill_version_id')->nullable();
                $table->string('reason', 500)->nullable();
                $table->string('severity', 16)->default('high');
                $table->string('action', 24)->default('block_all');
                $table->string('status', 16)->default('active');
                $table->unsignedInteger('affected_devices')->default(0);
                $table->string('created_by', 32)->nullable();
                $table->timestamp('lifted_at')->nullable();
                $table->timestamps();

                $table->index(['skill_id', 'status'], 'idx_blocklist_skill_status');
            });
        }

        // 灰度发布 / 回滚记录（§4.10）
        if (! Schema::hasTable('gray_release')) {
            Schema::create('gray_release', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('skill_version_id');
                $table->json('strategy')->nullable(); // whitelist/percent/model
                $table->string('batch_no', 40)->nullable();
                $table->string('status', 16)->default('paused'); // paused/releasing/done/aborted
                $table->json('metrics')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('rollback_record')) {
            Schema::create('rollback_record', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('gray_release_id')->nullable();
                $table->unsignedInteger('deploy_task_id')->nullable();
                $table->string('trigger', 16)->default('manual'); // auto/manual
                $table->string('reason', 500)->nullable();
                $table->unsignedInteger('affected_devices')->default(0);
                $table->timestamp('created_at')->nullable();
            });
        }

        // 开发者主体 / 团队 / 协议 / 应用 / 密钥（§4.15）
        if (! Schema::hasTable('developer_profile')) {
            Schema::create('developer_profile', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('customer_id');
                $table->unsignedInteger('seller_id')->nullable();
                $table->string('dev_type', 16)->default('personal'); // personal/company
                $table->string('realname_status', 16)->default('pending');
                $table->json('cert_payload')->nullable();
                $table->timestamp('agreement_signed_at')->nullable();
                $table->json('api_quota')->nullable();
                $table->string('status', 16)->default('active');
                $table->timestamps();

                $table->unique('customer_id', 'uk_dev_profile_customer');
            });
        }

        if (! Schema::hasTable('developer_team_member')) {
            Schema::create('developer_team_member', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('developer_id');
                $table->unsignedInteger('customer_id');
                $table->string('role', 16)->default('dev'); // owner/admin/dev/finance/support
                $table->json('app_scope')->nullable();
                $table->string('status', 16)->default('active');
                $table->timestamps();

                $table->index('developer_id', 'idx_dev_team_developer');
            });
        }

        if (! Schema::hasTable('developer_agreement')) {
            Schema::create('developer_agreement', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('developer_id');
                $table->string('doc_type', 32); // dev_agreement/safety_commitment
                $table->string('doc_version', 16)->nullable();
                $table->timestamp('signed_at')->nullable();
                $table->json('sign_payload')->nullable();
                $table->timestamps();

                $table->index(['developer_id', 'doc_type'], 'idx_dev_agreement');
            });
        }

        if (! Schema::hasTable('developer_app')) {
            Schema::create('developer_app', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('developer_id');
                $table->string('name');
                $table->string('platform', 32)->nullable();
                $table->string('status', 16)->default('active');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('api_credential')) {
            Schema::create('api_credential', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('developer_app_id');
                $table->string('app_id', 64);
                $table->string('secret_hash')->nullable();
                $table->json('scopes')->nullable();
                $table->string('status', 16)->default('active');
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('rotated_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->unique('app_id', 'uk_api_credential_app_id');
            });
        }

        if (! Schema::hasTable('api_call_log')) {
            Schema::create('api_call_log', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('credential_id')->nullable();
                $table->string('endpoint', 191)->nullable();
                $table->unsignedSmallInteger('status')->nullable();
                $table->unsignedInteger('latency_ms')->nullable();
                $table->string('ip', 45)->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['credential_id', 'created_at'], 'idx_api_call_cred');
            });
        }

        // 技能评价 / 举报（§4.18）
        if (! Schema::hasTable('skill_review')) {
            Schema::create('skill_review', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('skill_id');
                $table->unsignedInteger('skill_version_id')->nullable();
                $table->unsignedInteger('customer_id');
                $table->unsignedInteger('order_id')->nullable();
                $table->tinyInteger('rating')->nullable();
                $table->text('content')->nullable();
                $table->json('media')->nullable();
                $table->boolean('is_anonymous')->default(false);
                $table->string('status', 16)->default('visible');
                $table->json('reply')->nullable();
                $table->timestamp('replied_at')->nullable();
                $table->timestamps();

                $table->index(['skill_id', 'status'], 'idx_skill_review_skill');
            });
        }

        if (! Schema::hasTable('skill_report')) {
            Schema::create('skill_report', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('reporter_customer_id')->nullable();
                $table->string('target_type', 16); // skill/review/developer
                $table->unsignedInteger('target_id');
                $table->string('reason_category', 64)->nullable();
                $table->text('detail')->nullable();
                $table->json('evidence')->nullable();
                $table->string('status', 16)->default('pending');
                $table->unsignedInteger('handler_id')->nullable();
                $table->timestamp('handled_at')->nullable();
                $table->timestamps();

                $table->index(['target_type', 'target_id'], 'idx_skill_report_target');
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'skill_report', 'skill_review', 'api_call_log', 'api_credential', 'developer_app',
            'developer_agreement', 'developer_team_member', 'developer_profile',
            'rollback_record', 'gray_release', 'skill_blocklist',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
