<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S-12b · 实名「结构化文本」落库列（2026-10-16）。
 *
 * 拍板（`docs/skill-dev-step9-closure-plan.md` §11.8 决定 #1 + E2）：
 *   企业层收 **公司主体名 + 统一社会信用代码 + 营业执照附件**；
 *   操作人层收 **姓名 + 证件号文本**（**不收证件影像**）。
 *   E2 明确：**「developer_profile」加列**、**不复用 `cert_payload`**（避免其语义变宽）、
 *   **不建独立表**（一期无"多次重提留痕"需求）。
 *
 * ⚠ 为什么本卡还要加列：S-12a（`2026_10_16_000005`）只加了**两层状态 + submitted_at +
 *   营业执照路径/文件名**，**没有**放"公司主体名 / 信用代码 / 操作人姓名 / 证件号"这四个
 *   **文本字段**的位置。E2 又**禁止**复用 `cert_payload`。所以这四个字段只能在
 *   `developer_profile` 上**加列**（E2 的口径是"加列、不复用 cert_payload、不建表"）。
 *   这是本卡**唯一**的结构变更，且只新增列，**不碰**任何既有列。
 *
 * ⚠ 存量不追溯：四列**可 NULL**。历史主体没提交过实名 → 列为 NULL，照旧放行
 *   （`realname_status` 的取值与写入逻辑一字不动，见 S-12a）。
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
            // 企业层文本
            if (! Schema::hasColumn('developer_profile', 'company_name')) {
                $table->string('company_name', 191)->nullable()->after('company_cert_name');
            }

            if (! Schema::hasColumn('developer_profile', 'company_credit_code')) {
                // 统一社会信用代码：18 位（GB 32100-2015 字符集），64 留足余量
                $table->string('company_credit_code', 64)->nullable()->after('company_name');
            }

            // 操作人层文本（一期只收文本，不收证件影像）
            if (! Schema::hasColumn('developer_profile', 'operator_name')) {
                $table->string('operator_name', 191)->nullable()->after('company_credit_code');
            }

            if (! Schema::hasColumn('developer_profile', 'operator_cert_no')) {
                // 证件号文本；宽 64 留余量（身份证 18 位，其它证件可能更长）
                $table->string('operator_cert_no', 64)->nullable()->after('operator_name');
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
                'operator_cert_no',
                'operator_name',
                'company_credit_code',
                'company_name',
            ] as $column) {
                if (Schema::hasColumn('developer_profile', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
