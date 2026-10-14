<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3.4 · 补齐数据模型（批次 3/3）：客服工单。
 * （TicketController 用到,但步骤 7 时漏建了表 —— 补上。）
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_ticket')) {
            Schema::create('support_ticket', function (Blueprint $table) {
                $table->increments('id');
                $table->string('ticket_no', 40);
                $table->unsignedInteger('customer_id')->nullable();
                $table->unsignedInteger('developer_id')->nullable();
                $table->unsignedInteger('skill_id')->nullable();
                $table->string('channel', 16)->default('app');
                $table->string('category', 64)->nullable();
                $table->string('priority', 16)->default('normal');
                $table->string('status', 16)->default('open');
                $table->unsignedInteger('assignee_id')->nullable();
                $table->unsignedInteger('related_deploy_task_id')->nullable();
                $table->timestamp('sla_due_at')->nullable();
                $table->timestamps();

                $table->unique('ticket_no', 'uk_support_ticket_no');
                $table->index(['status', 'sla_due_at'], 'idx_ticket_status_sla');
                $table->index('customer_id', 'idx_ticket_customer');
                $table->index('developer_id', 'idx_ticket_developer');
            });
        }

        if (! Schema::hasTable('ticket_message')) {
            Schema::create('ticket_message', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('ticket_id');
                $table->string('actor_type', 16); // customer/developer/admin/system
                $table->unsignedInteger('actor_id')->nullable();
                $table->text('content')->nullable();
                $table->json('attachments')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index('ticket_id', 'idx_ticket_msg_ticket');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_message');
        Schema::dropIfExists('support_ticket');
    }
};
