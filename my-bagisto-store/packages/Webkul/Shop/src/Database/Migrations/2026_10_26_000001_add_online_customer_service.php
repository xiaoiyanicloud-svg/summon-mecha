<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 在线客服 MVP：复用客服工单，只补充在线模式、队列、接入、已读和客服在线状态。
     *
     * 这是 additive migration：不修改既有状态值，也不把现有异步工单强行转成在线单。
     */
    public function up(): void
    {
        if (! Schema::hasColumn('support_ticket', 'service_mode')) {
            Schema::table('support_ticket', function (Blueprint $table) {
                $table->string('service_mode', 16)->default('async')->after('channel');
            });
        }

        if (! Schema::hasColumn('support_ticket', 'queued_at')) {
            Schema::table('support_ticket', function (Blueprint $table) {
                $table->timestamp('queued_at')->nullable()->after('sla_due_at');
            });
        }

        if (! Schema::hasColumn('support_ticket', 'accepted_at')) {
            Schema::table('support_ticket', function (Blueprint $table) {
                $table->timestamp('accepted_at')->nullable()->after('queued_at');
            });
        }

        if (! Schema::hasColumn('support_ticket', 'last_customer_message_at')) {
            Schema::table('support_ticket', function (Blueprint $table) {
                $table->timestamp('last_customer_message_at')->nullable()->after('accepted_at');
            });
        }

        if (! Schema::hasColumn('support_ticket', 'last_staff_message_at')) {
            Schema::table('support_ticket', function (Blueprint $table) {
                $table->timestamp('last_staff_message_at')->nullable()->after('last_customer_message_at');
            });
        }

        if (! Schema::hasColumn('support_ticket', 'ended_at')) {
            Schema::table('support_ticket', function (Blueprint $table) {
                $table->timestamp('ended_at')->nullable()->after('last_staff_message_at');
            });
        }

        if (! Schema::hasIndex('support_ticket', 'idx_support_online_queue')) {
            Schema::table('support_ticket', function (Blueprint $table) {
                $table->index(['service_mode', 'status', 'queued_at'], 'idx_support_online_queue');
            });
        }

        if (! Schema::hasColumn('ticket_message', 'read_at')) {
            Schema::table('ticket_message', function (Blueprint $table) {
                $table->timestamp('read_at')->nullable()->after('attachments');
            });
        }

        if (! Schema::hasIndex('ticket_message', 'idx_ticket_msg_unread')) {
            Schema::table('ticket_message', function (Blueprint $table) {
                $table->index(['ticket_id', 'read_at'], 'idx_ticket_msg_unread');
            });
        }

        if (! Schema::hasTable('support_agent_state')) {
            Schema::create('support_agent_state', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('admin_id');
                $table->boolean('is_online')->default(false);
                $table->unsignedTinyInteger('max_concurrent')->default(5);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();

                $table->unique('admin_id', 'uk_support_agent_admin');
                $table->index('last_seen_at', 'idx_support_agent_seen');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_agent_state');

        if (Schema::hasIndex('ticket_message', 'idx_ticket_msg_unread')) {
            Schema::table('ticket_message', function (Blueprint $table) {
                $table->dropIndex('idx_ticket_msg_unread');
            });
        }

        if (Schema::hasColumn('ticket_message', 'read_at')) {
            Schema::table('ticket_message', function (Blueprint $table) {
                $table->dropColumn('read_at');
            });
        }

        if (Schema::hasIndex('support_ticket', 'idx_support_online_queue')) {
            Schema::table('support_ticket', function (Blueprint $table) {
                $table->dropIndex('idx_support_online_queue');
            });
        }

        foreach (['ended_at', 'last_staff_message_at', 'last_customer_message_at', 'accepted_at', 'queued_at', 'service_mode'] as $column) {
            if (Schema::hasColumn('support_ticket', $column)) {
                Schema::table('support_ticket', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};