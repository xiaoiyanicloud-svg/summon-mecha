<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 给 `deploy_task` 加**投递标记**（P4 · 幂等缺失第三条）。
 *
 * 问题：设备端 Agent 每轮 `GET /api/agent/commands` 都拿到该 did 下**全部**
 * `dispatching / pending_online` 的任务，服务端没有任何"这条已经给过它了"的记录。
 * 于是设备在"取到指令"与"回报 downloading/done"之间重启一次，重启后的第一次轮询
 * 会把同一条指令**再取走一遍** —— 同一个版本装两次。
 *
 * 为什么单独开一列而不是复用现成字段：
 *   - `retry_count` / `next_retry_at` 是**重试**语义（失败后再来一次），
 *     "投递给设备一次"不是失败，混用会让重试统计凭空+1；
 *   - `signature` / `error_*` 各自有明确含义，且都会被覆写；
 *   - 最贴近的 `DeviceBinding.last_online_at`（markSeen）记的是"**这台设备**来过"，
 *     回答不了"**这一条任务**给过它没有"—— 一台设备上有 N 条任务时粒度不够。
 *
 * 可空：只有真的被 `commands` 取走过才写。首次下发即离线挂起的任务
 * （`pending_online`）保持 NULL —— 它**从未投递过**，设备上线后当然要能取到。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deploy_task') || Schema::hasColumn('deploy_task', 'delivered_at')) {
            return;
        }

        Schema::table('deploy_task', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('next_retry_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('deploy_task') || ! Schema::hasColumn('deploy_task', 'delivered_at')) {
            return;
        }

        Schema::table('deploy_task', function (Blueprint $table) {
            $table->dropColumn('delivered_at');
        });
    }
};
