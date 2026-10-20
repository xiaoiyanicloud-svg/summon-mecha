<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scene_projects', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('project_no')->unique();
            $t->unsignedBigInteger('request_id');
            $t->foreign('request_id')->references('id')->on('scene_requests')->restrictOnDelete();
            $t->unsignedBigInteger('quote_id')->unique();
            $t->foreign('quote_id')->references('id')->on('scene_quotes')->restrictOnDelete();
            $t->string('state', 30)->default('preparing');
            $t->string('commercial_state', 30)->default('unconfirmed');
            $t->string('payment_state', 30)->default('unpaid');
            $t->unsignedInteger('row_version')->default(1);
            $t->json('snapshot');
            $t->boolean('returned')->default(false);
            $t->boolean('open_dispute')->default(false);
            $t->timestamps();
        });
        Schema::create('scene_order_links', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('quote_id')->unique();
            $t->foreign('quote_id')->references('id')->on('scene_quotes')->restrictOnDelete();
            $t->unsignedBigInteger('project_id')->unique();
            $t->foreign('project_id')->references('id')->on('scene_projects')->restrictOnDelete();
            $t->unsignedInteger('order_id')->unique();
            $t->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
            $t->dateTime('created_at');
        });
        Schema::create('scene_project_tasks', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('project_id');
            $t->foreign('project_id')->references('id')->on('scene_projects')->restrictOnDelete();
            $t->string('task_key', 80);
            $t->string('name', 255);
            $t->json('dependencies');
            $t->json('checklist');
            $t->string('project_state_after', 30);
            $t->string('state', 20)->default('pending');
            $t->unsignedInteger('row_version')->default(1);
            $t->unsignedInteger('assignee_id')->nullable();
            $t->dateTime('due_at')->nullable();
            $t->dateTime('occurred_at')->nullable();
            $t->dateTime('recorded_at')->nullable();
            $t->json('evidence')->nullable();
            $t->timestamps();
            $t->unique(['project_id', 'task_key']);
        });
        Schema::create('scene_acceptance_records', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('project_id');
            $t->foreign('project_id')->references('id')->on('scene_projects')->restrictOnDelete();
            $t->string('item_key', 80);
            $t->unsignedInteger('attempt');
            $t->string('result', 10);
            $t->string('method', 30);
            $t->string('principal_scope', 100);
            $t->text('note')->nullable();
            $t->json('evidence');
            $t->dateTime('created_at');
            $t->unique(['project_id', 'item_key', 'attempt'], 'scene_acceptance_attempt_unique');
        });
        Schema::create('scene_project_changes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('project_id');
            $t->foreign('project_id')->references('id')->on('scene_projects')->restrictOnDelete();
            $t->unsignedInteger('base_version');
            $t->string('state', 30)->default('requested');
            $t->string('kind', 30);
            $t->text('reason');
            $t->json('requested_change');
            $t->string('principal_scope', 100);
            $t->timestamps();
        });
        Schema::create('scene_service_tickets', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('project_id');
            $t->foreign('project_id')->references('id')->on('scene_projects')->restrictOnDelete();
            $t->string('kind', 30);
            $t->text('description');
            $t->string('state', 30)->default('open');
            $t->unsignedInteger('row_version')->default(1);
            $t->string('principal_scope', 100);
            $t->unsignedInteger('assignee_id')->nullable();
            $t->text('resolution')->nullable();
            $t->timestamps();
        });
        Schema::create('scene_commercial_records', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('project_id');
            $t->foreign('project_id')->references('id')->on('scene_projects')->restrictOnDelete();
            $t->string('kind', 30);
            $t->unsignedBigInteger('amount_minor');
            $t->string('reference', 255);
            $t->json('evidence');
            $t->unsignedInteger('recorded_by');
            $t->unsignedInteger('reviewed_by')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('scene_operation_todos', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('dedupe_key', 160)->unique();
            $t->unsignedBigInteger('request_id')->nullable();
            $t->foreign('request_id')->references('id')->on('scene_requests')->restrictOnDelete();
            $t->string('kind', 50);
            $t->string('state', 20)->default('open');
            $t->unsignedInteger('assignee_id')->nullable();
            $t->dateTime('due_at')->nullable();
            $t->string('resolution_code', 80)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('交付、验收、争议、财务事实不可通过回滚迁移删除。');
    }
};
