<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S-11a · 开发者邀请入驻（数据层）—— 邀请码是**落地路由 + 归属 + 来源留痕**，
 * **不是准入闸门**（准入闸门是实名 + 协议）。
 *
 * 两张新表：
 *   - `developer_invite`       —— 邀请码本身（码 / 可用次数 / 已用次数 / 有效期 / 状态）。
 *   - `developer_invite_usage` —— 领取流水（谁、何时、从哪个 IP/UA 领的；append-only）。
 *
 * 并给 `developer_profile` 加一列 `invite_id`（nullable）：留"这位开发者是被哪个码带进来的"。
 *
 * 约定（与本仓 developer_* 家族一致）：
 *   - `->increments('id')`（= unsignedInteger 自增），跨表引用列用 `unsignedInteger`；
 *   - **不加 FK**（与 developer_profile.seller_id / 各 developer_id 列一致，避免迁移顺序耦合）；
 *   - up() 用 hasTable/hasColumn 幂等守卫，down() 对称删除。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 邀请码
        if (! Schema::hasTable('developer_invite')) {
            Schema::create('developer_invite', function (Blueprint $table) {
                $table->increments('id');
                // 去混淆字符集（无 0/O/1/I/L）、10 位；唯一索引兜底撞码
                $table->string('code', 32);
                $table->unsignedTinyInteger('max_uses')->default(1);
                $table->unsignedInteger('times_used')->default(0);
                $table->timestamp('expires_at')->nullable();
                $table->string('status', 16)->default('active'); // active/revoked
                $table->unsignedInteger('created_by')->nullable();
                $table->string('note', 191)->nullable();
                $table->timestamps();

                $table->unique('code', 'uk_dev_invite_code');
                // 门户校验"码是否可领"按 (status, expires_at) 过滤
                $table->index(['status', 'expires_at'], 'idx_dev_invite_status_expires');
            });
        }

        // 领取流水
        if (! Schema::hasTable('developer_invite_usage')) {
            Schema::create('developer_invite_usage', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('invite_id');
                $table->unsignedInteger('customer_id');
                $table->unsignedInteger('developer_id')->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('used_at');
                $table->timestamps();

                $table->index('invite_id', 'idx_dev_invite_usage_invite');
                // 同一码、同一 customer 只能领一次（多用途码 = 发给多个人）
                $table->unique(['invite_id', 'customer_id'], 'uk_dev_invite_usage_invite_customer');
            });
        }

        // developer_profile 加列：这张码把谁带进来的（可空）
        if (Schema::hasTable('developer_profile') && ! Schema::hasColumn('developer_profile', 'invite_id')) {
            Schema::table('developer_profile', function (Blueprint $table) {
                $table->unsignedInteger('invite_id')->nullable()->after('seller_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('developer_profile') && Schema::hasColumn('developer_profile', 'invite_id')) {
            Schema::table('developer_profile', function (Blueprint $table) {
                $table->dropColumn('invite_id');
            });
        }

        foreach (['developer_invite_usage', 'developer_invite'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
