<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deploy_inbox_event')) {
            Schema::table('deploy_inbox_event', function (Blueprint $table) {
                if (! Schema::hasColumn('deploy_inbox_event', 'event_seq')) {
                    $table->unsignedBigInteger('event_seq')->nullable()->after('event_type');
                }
                if (! Schema::hasColumn('deploy_inbox_event', 'applied')) {
                    $table->boolean('applied')->default(false)->after('payload');
                }
                if (! Schema::hasColumn('deploy_inbox_event', 'result_status')) {
                    $table->string('result_status', 32)->nullable()->after('applied');
                }
                if (! Schema::hasColumn('deploy_inbox_event', 'late_report')) {
                    $table->boolean('late_report')->default(false)->after('result_status');
                }
            });
        }

        if (Schema::hasTable('skill_execution')) {
            Schema::table('skill_execution', function (Blueprint $table) {
                if (! Schema::hasColumn('skill_execution', 'actual_started')) {
                    $table->boolean('actual_started')->default(false)->after('status');
                }
                if (! Schema::hasColumn('skill_execution', 'actual_stopped')) {
                    $table->boolean('actual_stopped')->default(false)->after('actual_started');
                }
                if (! Schema::hasColumn('skill_execution', 'execution_evidence')) {
                    $table->json('execution_evidence')->nullable()->after('execution_params');
                }
                if (! Schema::hasColumn('skill_execution', 'last_event_seq')) {
                    $table->unsignedBigInteger('last_event_seq')->nullable()->after('observed_at');
                }
            });
        }

        if (! Schema::hasTable('device_reconcile_report')) {
            Schema::create('device_reconcile_report', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('did', 64);
                $table->string('model_code', 64);
                $table->unsignedBigInteger('observed_generation')->default(0);
                $table->string('report_hash', 64);
                $table->json('installed_skills');
                $table->json('active_executions');
                $table->string('outbox_cursor', 128)->nullable();
                $table->unsignedInteger('drift_count')->default(0);
                $table->timestamp('reported_at');
                $table->timestamp('received_at')->useCurrent();

                $table->unique(['did', 'report_hash'], 'uk_reconcile_did_hash');
                $table->index(['did', 'observed_generation'], 'idx_reconcile_did_generation');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_reconcile_report');

        if (Schema::hasTable('skill_execution')) {
            Schema::table('skill_execution', function (Blueprint $table) {
                foreach (['last_event_seq', 'execution_evidence', 'actual_stopped', 'actual_started'] as $column) {
                    if (Schema::hasColumn('skill_execution', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('deploy_inbox_event')) {
            Schema::table('deploy_inbox_event', function (Blueprint $table) {
                foreach (['late_report', 'result_status', 'applied', 'event_seq'] as $column) {
                    if (Schema::hasColumn('deploy_inbox_event', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};