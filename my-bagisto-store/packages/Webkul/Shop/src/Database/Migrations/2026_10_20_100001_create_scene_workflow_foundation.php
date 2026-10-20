<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scene_guest_capabilities', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->char('token_hash', 64)->unique();
            $t->dateTime('expires_at');
            $t->dateTime('revoked_at')->nullable();
            $t->dateTime('created_at');
        });
        Schema::create('business_idempotency_keys', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('principal_scope', 100);
            $t->string('operation', 80);
            $t->char('key_hash', 64);
            $t->char('request_hash', 64);
            $t->json('result')->nullable();
            $t->timestamps();
            $t->unique(['principal_scope', 'operation', 'key_hash'], 'business_idempotency_scope_unique');
        });
        Schema::create('business_outbox', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('event_id')->unique();
            $t->string('aggregate_type', 40);
            $t->unsignedBigInteger('aggregate_id');
            $t->unsignedInteger('aggregate_seq');
            $t->string('event_type', 80);
            $t->unsignedInteger('schema_version')->default(1);
            $t->json('payload');
            $t->unsignedInteger('attempts')->default(0);
            $t->dateTime('available_at');
            $t->dateTime('lease_until')->nullable();
            $t->uuid('lease_token')->nullable();
            $t->dateTime('processed_at')->nullable();
            $t->string('last_error_code', 80)->nullable();
            $t->timestamps();
            $t->index(['processed_at', 'available_at', 'lease_until'], 'business_outbox_due');
        });
        Schema::create('business_event_receipts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('consumer', 60);
            $t->uuid('event_id');
            $t->dateTime('created_at');
            $t->unique(['consumer', 'event_id']);
        });
        Schema::create('scene_revisions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('scene_id');
            $t->foreign('scene_id')->references('id')->on('scenes')->restrictOnDelete();
            $t->unsignedInteger('revision_no');
            $t->unsignedInteger('row_version')->default(1);
            $t->string('state', 20)->default('draft');
            $t->unsignedInteger('schema_version')->default(1);
            $t->json('content');
            $t->json('requirement_schema');
            $t->json('acceptance_template');
            $t->json('workflow');
            $t->char('content_hash', 64);
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->dateTime('published_at')->nullable();
            $t->timestamps();
            $t->unique(['scene_id', 'revision_no']);
        });
        Schema::table('scenes', function (Blueprint $t) {
            $t->unsignedBigInteger('active_revision_id')->nullable();
            $t->foreign('active_revision_id')->references('id')->on('scene_revisions')->restrictOnDelete();
            $t->unsignedInteger('public_seq')->default(0);
        });
        Schema::create('scene_requests', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('request_no', 40)->unique();
            $t->unsignedInteger('customer_id')->nullable();
            $t->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
            $t->char('guest_access_hash', 64)->nullable()->index();
            $t->dateTime('guest_expires_at')->nullable();
            $t->unsignedInteger('scene_id')->nullable();
            $t->foreign('scene_id')->references('id')->on('scenes')->restrictOnDelete();
            $t->unsignedBigInteger('scene_revision_id')->nullable();
            $t->foreign('scene_revision_id')->references('id')->on('scene_revisions')->restrictOnDelete();
            $t->unsignedInteger('revision_no')->default(1);
            $t->unsignedInteger('row_version')->default(1);
            $t->string('state', 30)->default('submitted');
            $t->unsignedInteger('assignee_id')->nullable();
            $t->string('source', 40);
            $t->string('consent_version', 80);
            $t->boolean('is_test')->default(false);
            $t->timestamps();
            $t->index(['customer_id', 'id']);
            $t->index(['assignee_id', 'state', 'created_at'], 'scene_request_work_queue');
        });
        Schema::create('scene_request_revisions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('request_id');
            $t->foreign('request_id')->references('id')->on('scene_requests')->restrictOnDelete();
            $t->unsignedInteger('revision_no');
            $t->mediumText('original_text');
            $t->json('facts');
            $t->json('schema_snapshot');
            $t->char('input_hash', 64);
            $t->dateTime('created_at');
            $t->unique(['request_id', 'revision_no']);
        });
        Schema::create('scene_request_contacts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('request_id')->unique();
            $t->foreign('request_id')->references('id')->on('scene_requests')->restrictOnDelete();
            // 加密字段不参与电话匹配和认领。
            $t->text('encrypted_contact');
            $t->timestamps();
        });
        Schema::create('scene_activities', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('request_id');
            $t->foreign('request_id')->references('id')->on('scene_requests')->restrictOnDelete();
            $t->string('kind', 50);
            $t->string('visibility', 20)->default('internal');
            $t->string('actor_type', 20);
            $t->unsignedInteger('actor_id')->nullable();
            $t->text('note')->nullable();
            $t->dateTime('occurred_at');
            $t->dateTime('created_at');
            $t->index(['request_id', 'visibility', 'id']);
        });
        Schema::create('scene_attachments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('request_id');
            $t->foreign('request_id')->references('id')->on('scene_requests')->restrictOnDelete();
            $t->uuid('storage_key')->unique();
            $t->char('sha256', 64);
            $t->string('mime', 80);
            $t->unsignedInteger('size');
            $t->string('state', 30)->default('quarantined');
            $t->boolean('legal_hold')->default(false);
            $t->dateTime('expires_at');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('场景流程含客户原始资料；停写回退，不允许通过 down 删除业务记录。');
    }
};
