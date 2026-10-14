<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v3.4 步骤 3 · 下发与审核四表(计划 §3.2)。
 *
 *  deploy_task    —— 下发任务状态机(Go 服务驱动)
 *  install_record —— 安装事实(每设备每技能一条)
 *  audit_record   —— 三层审核记录(L0机审/L1仿真/L2人审)
 *  security_scan  —— 安全扫描结果
 *
 * ⚠ v1 惯例:全部用普通索引不用外键(订单会被反复重建,FK 在删单路径上会炸)。
 * ⚠ deploy_task 的幂等由应用层保证(回收任务前先原子翻转 entitlement 状态);
 *   MySQL 5.7 不支持"部分唯一索引",故此处只建索引不建唯一键。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deploy_task')) {
            Schema::create('deploy_task', function (Blueprint $table) {
                $table->increments('id');
                $table->string('task_no', 40);
                $table->string('did', 64);
                $table->string('channel', 24);            // agent / vendor:{code} / manual
                $table->unsignedInteger('skill_id');
                $table->unsignedInteger('skill_version_id')->nullable();
                $table->unsignedInteger('entitlement_id')->nullable();
                $table->tinyInteger('operation');          // 1安装 2卸载 3回滚 4启用 5停用 6回收
                $table->string('status', 20)->default('pending');
                $table->unsignedInteger('retry_count')->default(0);
                $table->timestamp('next_retry_at')->nullable();
                $table->string('package_url')->nullable();
                $table->string('package_hash', 64)->nullable();
                $table->text('signature')->nullable();
                $table->string('error_code', 32)->nullable();
                $table->string('error_msg', 512)->nullable();
                $table->string('created_by', 32)->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->unique('task_no', 'uk_deploy_task_no');
                $table->index(['did', 'status'], 'idx_deploy_did_status');
                $table->index(['entitlement_id', 'operation', 'status'], 'idx_deploy_ent_op_status');
                $table->index(['status', 'next_retry_at'], 'idx_deploy_status_retry');
            });
        }

        if (! Schema::hasTable('install_record')) {
            Schema::create('install_record', function (Blueprint $table) {
                $table->increments('id');
                $table->string('did', 64);
                $table->unsignedInteger('skill_version_id');
                $table->unsignedInteger('entitlement_id')->nullable();
                $table->unsignedInteger('deploy_task_id')->nullable();
                $table->string('status', 16)->default('installed'); // installed/updated/rolled_back/uninstalled/expired
                $table->string('current_version', 32)->nullable();
                $table->timestamp('installed_at')->nullable();
                $table->timestamp('removed_at')->nullable();
                $table->timestamps();

                $table->unique(['did', 'skill_version_id'], 'uk_install_did_version');
                $table->index('entitlement_id', 'idx_install_entitlement');
            });
        }

        if (! Schema::hasTable('audit_record')) {
            Schema::create('audit_record', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('skill_id');
                $table->unsignedInteger('version')->nullable();     // 基础信息审核为空
                $table->tinyInteger('audit_type')->default(1);      // 1基础 2版本
                $table->tinyInteger('layer')->default(1);           // 1=L0机审 2=L1仿真 3=L2人审
                $table->unsignedInteger('auditor_id')->nullable();
                $table->tinyInteger('result')->default(0);          // 0待审 1通过 2驳回 3待补充
                $table->string('reason_category', 64)->nullable();
                $table->text('reason_detail')->nullable();
                $table->text('suggestion')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('audited_at')->nullable();
                $table->timestamps();

                $table->index(['skill_id', 'audit_type'], 'idx_audit_skill_type');
                $table->index('result', 'idx_audit_result');
            });
        }

        if (! Schema::hasTable('security_scan')) {
            Schema::create('security_scan', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('skill_version_id');
                $table->tinyInteger('scan_type')->default(1);   // 1静态 2注入检测 3沙盒 4仿真
                $table->string('result', 16);                   // SAFE/SUSPICIOUS/DANGEROUS/PASS/WARN/BLOCKED
                $table->json('report')->nullable();
                $table->timestamp('scanned_at')->nullable();
                $table->timestamps();

                $table->index(['skill_version_id', 'scan_type'], 'idx_secscan_version_type');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('security_scan');
        Schema::dropIfExists('audit_record');
        Schema::dropIfExists('install_record');
        Schema::dropIfExists('deploy_task');
    }
};
