<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 阶段一 · 设备端 Agent v2 契约与技能执行 (OP_EXECUTE / OP_CANCEL_EXECUTION) 支持。
 * 遵循严格 additive 方案，禁止破坏性变更。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deploy_task')) {
            Schema::table('deploy_task', function (Blueprint $table) {
                if (! Schema::hasColumn('deploy_task', 'command_seq')) {
                    $table->unsignedInteger('command_seq')->default(1)->after('task_no');
                }
                if (! Schema::hasColumn('deploy_task', 'protocol_version')) {
                    $table->string('protocol_version', 32)->default('agent-task/v1')->after('channel');
                }
                if (! Schema::hasColumn('deploy_task', 'execution_id')) {
                    $table->string('execution_id', 64)->nullable()->after('entitlement_id');
                    $table->index('execution_id', 'idx_deploy_execution_id');
                }
                if (! Schema::hasColumn('deploy_task', 'task_expires_at')) {
                    $table->timestamp('task_expires_at')->nullable()->after('completed_at');
                }
                if (! Schema::hasColumn('deploy_task', 'signed_payload')) {
                    $table->json('signed_payload')->nullable()->after('signature');
                }
            });
        }

        if (! Schema::hasTable('skill_execution')) {
            Schema::create('skill_execution', function (Blueprint $table) {
                $table->increments('id');
                $table->string('execution_id', 64)->unique('uk_skill_execution_id');
                $table->unsignedInteger('deploy_task_id')->nullable()->index('idx_exec_deploy_task_id');
                $table->string('did', 64)->index('idx_exec_did');
                $table->unsignedInteger('skill_id');
                $table->unsignedInteger('skill_version_id')->nullable();
                $table->unsignedInteger('entitlement_id')->nullable();
                $table->string('status', 24)->default('ACCEPTED'); // ACCEPTED, RUNNING, CANCEL_REQUESTED, SUCCEEDED, FAILED, CANCELED, UNKNOWN
                $table->json('execution_params')->nullable();
                $table->string('plan_digest', 64)->nullable();
                $table->string('error_code', 32)->nullable();
                $table->string('error_msg', 512)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('observed_at')->nullable();
                $table->timestamps();

                $table->index(['did', 'status'], 'idx_exec_did_status');
                $table->index(['skill_id', 'status'], 'idx_exec_skill_status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('skill_execution')) {
            Schema::dropIfExists('skill_execution');
        }

        if (Schema::hasTable('deploy_task')) {
            Schema::table('deploy_task', function (Blueprint $table) {
                $columns = ['command_seq', 'protocol_version', 'execution_id', 'task_expires_at', 'signed_payload'];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('deploy_task', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
