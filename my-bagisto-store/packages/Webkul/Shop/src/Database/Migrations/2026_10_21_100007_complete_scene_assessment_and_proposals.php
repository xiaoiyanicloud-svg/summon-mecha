<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scene_site_surveys', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('request_id');
            $t->foreign('request_id')->references('id')->on('scene_requests')->restrictOnDelete();
            $t->unsignedInteger('request_revision_no');
            $t->json('facts');
            $t->json('provenance');
            $t->json('evidence');
            $t->text('note');
            $t->char('input_hash', 64);
            $t->dateTime('occurred_at');
            $t->unsignedInteger('created_by');
            $t->dateTime('created_at');
            $t->index(['request_id', 'request_revision_no', 'id'], 'scene_survey_revision_lookup');
        });
        Schema::table('scene_evidence', function (Blueprint $t) {
            $t->json('binding_snapshot')->nullable();
            $t->json('constraint_snapshot')->nullable();
            $t->json('skill_snapshot')->nullable();
            $t->unsignedInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->text('review_note')->nullable();
            $t->text('revocation_reason')->nullable();
        });
        Schema::table('scene_price_items', fn (Blueprint $t) => $t->text('revocation_reason')->nullable());
        Schema::table('scene_assessments', function (Blueprint $t) {
            $t->unsignedInteger('channel_id')->nullable();
            $t->foreign('channel_id')->references('id')->on('channels')->restrictOnDelete();
            $t->unsignedBigInteger('survey_id')->nullable();
            $t->foreign('survey_id')->references('id')->on('scene_site_surveys')->restrictOnDelete();
            $t->string('rule_version', 80)->nullable();
            $t->char('survey_hash', 64)->nullable();
        });
        Schema::create('scene_proposals', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('request_id');
            $t->foreign('request_id')->references('id')->on('scene_requests')->restrictOnDelete();
            $t->unsignedInteger('request_revision_no');
            $t->unsignedBigInteger('assessment_id');
            $t->foreign('assessment_id')->references('id')->on('scene_assessments')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->unsignedInteger('row_version')->default(1);
            $t->string('state', 30)->default('draft');
            $t->string('title', 255);
            $t->text('review_note')->nullable();
            $t->text('scope');
            $t->text('exclusions');
            $t->json('components');
            $t->json('acceptance');
            $t->char('content_hash', 64);
            $t->unsignedInteger('created_by');
            $t->unsignedInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['request_id', 'version']);
        });
        Schema::table('scene_quotes', function (Blueprint $t) {
            $t->unsignedBigInteger('proposal_id')->nullable();
            $t->foreign('proposal_id')->references('id')->on('scene_proposals')->restrictOnDelete();
            $t->json('proposal_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('勘察、能力依据及方案版本事实不可通过回滚删除。');
    }
};
