<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 让 `skill_entitlements.order_id` / `order_item_id` 可空 —— 为「试用」（B-3 · D4 / 计划 §4.7）。
 *
 * 为什么必须改：试用是**一条没有来源订单的授权**（`meta.trial=true` + `expires_at=+20min`），
 * 而这两列当前是 `NOT NULL`（迁移 `2026_09_30_000001` 建的）——
 * 于是"给它发一条授权"这件事在库里根本写不进去。
 *
 * 计划 §4.15 的原文就写着「买断型不填 expires_at；**试用型必填**且 `meta.trial=true`」，
 * 即试用行天然没有订单。把它硬塞成 `order_id=0` 之类的哨兵值更坏：
 *   ① 库里会出现一个"订单 0"，`order_id` 的语义（指向真实订单）被破坏；
 *   ② 所有 `whereNotNull('order_id')` ≈「这是买断」的判据会失真。
 *
 * ⚠ 只**放宽**约束（NOT NULL → NULL），对既有买断数据零影响：真实授权仍一定带订单。
 *   `order_item_id` 上的唯一键保留 —— MySQL 的唯一索引允许多个 NULL，
 *   所以多条试用行不会互相冲突（这也正是"幂等靠 order_item_id"对试用不适用的原因：
 *   试用没有订单条目可幂等，幂等改由 `TrialService` 的冷却规则保证）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('skill_entitlements')) {
            return;
        }

        Schema::table('skill_entitlements', function (Blueprint $table) {
            $table->unsignedInteger('order_id')->nullable()->change();
            $table->unsignedInteger('order_item_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('skill_entitlements')) {
            return;
        }

        // ⚠ 只改回约束，**不删数据**：若库里已存在试用行（order_id 为 NULL），
        //   这条 `down()` 会因 NOT NULL 而失败 —— 这是刻意留的硬失败，
        //   而不是"悄悄删掉用户的试用记录"。真要回滚，先自行处置这些行。
        Schema::table('skill_entitlements', function (Blueprint $table) {
            $table->unsignedInteger('order_id')->nullable(false)->change();
            $table->unsignedInteger('order_item_id')->nullable(false)->change();
        });
    }
};
