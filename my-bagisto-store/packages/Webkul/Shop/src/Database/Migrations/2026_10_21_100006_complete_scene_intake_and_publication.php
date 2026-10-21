<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scene_request_revisions', fn (Blueprint $t) => $t->json('source_snapshot')->nullable());
        Schema::table('scene_revisions', function (Blueprint $t) {
            $t->unsignedInteger('last_edited_by')->nullable();
        });
        Schema::create('scene_policy_kinds', function (Blueprint $t) {
            $t->string('kind', 40)->primary();
            $t->unsignedBigInteger('active_version_id')->nullable();
            $t->timestamps();
        });
        Schema::create('scene_policy_versions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('kind', 40);
            $t->foreign('kind')->references('kind')->on('scene_policy_kinds')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->unsignedInteger('row_version')->default(1);
            $t->string('state', 20)->default('draft');
            $t->json('content');
            $t->char('content_hash', 64);
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
            $t->unique(['kind', 'version']);
        });
        Schema::create('scene_content_evidence', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('scene_id');
            $t->foreign('scene_id')->references('id')->on('scenes')->restrictOnDelete();
            $t->string('kind', 20);
            $t->string('state', 20)->default('draft');
            $t->unsignedInteger('row_version')->default(1);
            $t->string('title', 255);
            $t->text('public_summary');
            $t->text('scope');
            $t->text('source_reference');
            $t->char('source_hash', 64);
            $t->string('authorization_reference', 255);
            $t->text('review_note')->nullable();
            $t->unsignedInteger('sample_size')->nullable();
            $t->string('metric_value', 100)->nullable();
            $t->string('metric_unit', 80)->nullable();
            $t->dateTime('observed_from');
            $t->dateTime('observed_until');
            $t->dateTime('expires_at');
            $t->dateTime('revoked_at')->nullable();
            $t->text('revocation_reason')->nullable();
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->timestamps();
            $t->index(['scene_id', 'state', 'expires_at'], 'scene_public_evidence_lookup');
        });
        Schema::create('scene_service_clocks', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('request_id')->unique();
            $t->foreign('request_id')->references('id')->on('scene_requests')->restrictOnDelete();
            $t->string('state', 30)->default('awaiting_contact');
            $t->json('calendar_snapshot');
            $t->dateTime('due_at');
            $t->dateTime('escalates_at');
            $t->dateTime('contacted_at')->nullable();
            $t->dateTime('escalated_at')->nullable();
            $t->unsignedInteger('assignee_id');
            $t->unsignedInteger('escalation_assignee_id');
            $t->timestamps();
            $t->index(['state', 'escalates_at']);
        });
        Schema::create('scene_attachment_holds', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('attachment_id');
            $t->foreign('attachment_id')->references('id')->on('scene_attachments')->restrictOnDelete();
            $t->string('reference', 255);
            $t->text('reason');
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('released_by')->nullable();
            $t->dateTime('released_at')->nullable();
            $t->timestamps();
            $t->index(['attachment_id', 'released_at']);
        });
        Schema::table('scene_attachments', function (Blueprint $t) {
            $t->string('retention_version', 80)->nullable();
            $t->dateTime('deleted_at')->nullable();
            $t->dateTime('revoked_at')->nullable();
            $t->text('revocation_reason')->nullable();
        });
        Schema::table('scene_operation_todos', function (Blueprint $t) {
            $t->unsignedInteger('row_version')->default(1);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('发布依据、联系时钟与资料保全事实不可删除；停写回退保留增量表列。');
    }
};
