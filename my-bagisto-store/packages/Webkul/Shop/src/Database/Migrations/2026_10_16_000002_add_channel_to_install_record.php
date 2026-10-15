<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 任务卡 S-5 · `install_record` 增 `channel` —— 让"人工通道安装"可被定位。
 *
 * 为什么必须有这一列：
 *   熔断（D9）对已装设备的"强制卸载/回滚"走的是 `channel='agent'` 的下发任务。
 *   此前 `install_record` 没有任何字段记"这个技能当初是怎么装上去的"，于是熔断只能
 *   对**每一条**安装记录都建一条 agent 任务 —— 连那些经**人工通道**（app/manual）
 *   装上的设备也照建。而那些设备没有 agent 回程，那条卸载任务永远等不到人来拉，
 *   熔断却对外报成"已建卸载任务"：拿一个拉不动的任务冒充"覆盖到了"。
 *
 * 取值口径（写入点见 `DeployTaskService::recordInstall` 与 `SkillController::confirmInstall`）：
 *   · `agent`         —— 平台经 agent 通道推下去（也能经 agent 撤回来 → 可自动卸载）；
 *   · `app`/`manual`  —— 应用内/人工通道交付（当前唯一真能跑的通道，但**无回程**，无法自动卸载）；
 *   · `vendor:{code}` —— 厂商云通道（计划中，尚未接入）。
 *   ⚠ 这一列是"**交付通道**"口径，与 `skill_versions.channel`（发布通道 stable/beta）无关。
 *
 * 可空：存量历史行没有这个信息，**不回填**。把"不知道"写成 `manual` 是伪造事实 ——
 *       读取方必须把 NULL 当"通道未知"，而不是当"人工"。熔断据此把 NULL 计入
 *       "无法自动卸载"（不假装能卸），而不是想当然地建一条 agent 任务。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('install_record') && ! Schema::hasColumn('install_record', 'channel')) {
            Schema::table('install_record', function (Blueprint $table) {
                // 与 `skill_entitlements.delivery_channel` 同为 string(24)：同一套交付通道 key。
                $table->string('channel', 24)->nullable()->after('deploy_task_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('install_record') && Schema::hasColumn('install_record', 'channel')) {
            Schema::table('install_record', fn (Blueprint $table) => $table->dropColumn('channel'));
        }
    }
};
