<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S-12a · 实名拆两层（2026-10-16 第三轮拍板）。
 *
 * 开发者必须**两层实名**（`company` 公司认证 + `operator` 操作人认证）通过才能建技能
 * （`DeveloperSkillService::canCreateSkill()`）。本迁移只**新增**两层的状态列 + 提交元数据，
 * **完全不碰**总状态 `realname_status` —— 那是上架闸门 `SkillPublisher::ownerQualification`
 * 读的列，语义与取值一字不改（拍板第 1 条）。
 *
 * ⚠ 存量不追溯：新列**可 NULL**。历史主体只写了 `realname_status=approved`、两层列为 NULL，
 *   照旧能建技能（见 `DeveloperProfile::isRealnameApproved()`）——
 *   把它们算回 pending 会造成大面积误拦，拍板明确不许（第 2 条）。
 *
 * ⚠ 一期只加列、**不实现上传**：实名提交页与营业执照附件上传是 S-12b；
 *   `company_cert_path` / `company_cert_name` 先占位（操作人一期只收文本、不收证件影像）。
 *
 * hasColumn 幂等守卫 + 对称 down()；沿用本仓"加列"迁移的既有写法。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('developer_profile')) {
            return;
        }

        Schema::table('developer_profile', function (Blueprint $table) {
            if (! Schema::hasColumn('developer_profile', 'company_realname_status')) {
                // company / operator 两层状态，取值 pending|approved|rejected（沿用 REALNAME_*）
                $table->string('company_realname_status', 16)->nullable()->after('realname_status');
            }

            if (! Schema::hasColumn('developer_profile', 'operator_realname_status')) {
                $table->string('operator_realname_status', 16)->nullable()->after('company_realname_status');
            }

            if (! Schema::hasColumn('developer_profile', 'realname_submitted_at')) {
                $table->timestamp('realname_submitted_at')->nullable()->after('operator_realname_status');
            }

            if (! Schema::hasColumn('developer_profile', 'company_cert_path')) {
                // 营业执照附件在存储上的相对路径（S-12b 上传时写；本卡只占位）
                $table->string('company_cert_path')->nullable()->after('realname_submitted_at');
            }

            if (! Schema::hasColumn('developer_profile', 'company_cert_name')) {
                // 附件原始文件名（展示用；191 与 Bagisto 其它 name 列同宽）
                $table->string('company_cert_name', 191)->nullable()->after('company_cert_path');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('developer_profile')) {
            return;
        }

        Schema::table('developer_profile', function (Blueprint $table) {
            foreach ([
                'company_cert_name',
                'company_cert_path',
                'realname_submitted_at',
                'operator_realname_status',
                'company_realname_status',
            ] as $column) {
                if (Schema::hasColumn('developer_profile', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
