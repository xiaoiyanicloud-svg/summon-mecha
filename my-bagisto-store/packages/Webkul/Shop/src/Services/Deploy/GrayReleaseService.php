<?php

namespace Webkul\Shop\Services\Deploy;

use Illuminate\Support\Facades\Log;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\GrayRelease;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\RollbackRecord;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * 灰度发布 / 自动回滚 —— v3.4 步骤 8(计划 §4.10)。
 *
 * 策略:白名单 DID / 比例分批(5%→20%→50%→100%)。
 * 自动回滚阈值:安装成功率 < 90% 暂停;< 80% 回滚(向已更新设备推回滚任务);
 *              崩溃率 > 2% 回滚(计划 §4.10「崩溃率>2% 回滚」)。
 *
 * ── P10 · 灰度与回滚要留痕 ──
 *   回滚是"拿着别人的设备执行动作",发起(人工)与触发(自动)都要能追。故
 *   `rollback_record` 落 `created_by`/`ip`,并把动作写进 `operation_log`。
 *
 * ── B-11 · 崩溃率是独立的回滚维度,不能拿成功率替代 ──
 *   "装上了"与"装上之后不崩"是两件事:设备侧的安装可以 100% success,
 *   而技能在真机上一跑就崩 —— 只看 success_rate 会把它判成"健康"。
 *   故 `metrics` 增 `crashes` / `crash_rate` 两个维度,阈值 `CRASH_RATE_ROLLBACK_THRESHOLD`
 *   (分母与成功率同口径 = 本批 installs,见 `recordMetric()`)。
 */
class GrayReleaseService
{
    protected const PAUSE_THRESHOLD = 90.0;   // 成功率 < 90% 暂停

    protected const ROLLBACK_THRESHOLD = 80.0; // 成功率 < 80% 回滚

    /**
     * 崩溃率回滚阈值(百分比)。
     *
     * 与成功率阈值**正交**:成功率高的批次仍可能因崩溃率高而回滚。
     * 计划 §4.10 原文即「崩溃率 > 2% 回滚」。
     */
    protected const CRASH_RATE_ROLLBACK_THRESHOLD = 2.0;

    /** 自动触发时的操作者标识 —— 与"人按的"区分开,复盘时才看得出发起方。 */
    public const ACTOR_AUTO = 'system';

    /**
     * 灰度的终态 —— 到了这里就不该再有"回滚"这个动作(已经 rollback 过,或本来就没问题)。
     * `aborted` = 已回滚;`done` = 灰度正常走完、全量发布 —— 两者都不该被重复 abort。
     */
    protected const TERMINAL_STATUSES = ['aborted', 'done'];

    public function __construct(
        protected DeployTaskService $deploy,
        protected OperationLogger $oplog,
    ) {}

    /**
     * 开启一次灰度。
     *
     * @param  string|null  $createdBy  调用方自报的身份;null → 'internal'
     * @param  string|null  $ip         来源 IP
     */
    public function start(
        int $skillVersionId,
        array $strategy = ['percent' => 5],
        ?string $batchNo = null,
        ?string $createdBy = null,
        ?string $ip = null,
    ): GrayRelease {
        $release = GrayRelease::create([
            'skill_version_id' => $skillVersionId,
            'strategy'         => $strategy,
            'batch_no'         => $batchNo ?: 'G'.now()->format('ymdHis'),
            'status'           => 'releasing',
            'metrics'          => ['installs' => 0, 'successes' => 0, 'failures' => 0, 'crashes' => 0],
        ]);

        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            null,
            'gray_release.start',
            'skill_version',
            $skillVersionId,
            [
                'operator'   => $createdBy ?: 'internal',
                'release_id' => $release->id,
                'strategy'   => $strategy,
                'batch_no'   => $release->batch_no,
            ],
            $ip
        );

        return $release;
    }

    /**
     * 记录一次批次指标。
     *
     * @param  int  $crashes  本批中"装上了但运行崩溃"的设备数(B-11)。默认 0 —— 旧调用方(没这个维度)
     *                        行为不变;→ 影响 `crash_rate`,进而可能触发崩溃率回滚。
     *                        ⚠ 崩溃与失败正交:一次 success 之后仍可能崩,故 crashes 不要求 <= failures。
     */
    public function recordMetric(int $releaseId, int $installs, int $successes, int $failures, int $crashes = 0): ?array
    {
        $release = GrayRelease::find($releaseId);
        if (! $release) {
            return null;
        }

        $release->metrics = [
            'installs'  => $installs,
            'successes' => $successes,
            'failures'  => $failures,
            'crashes'   => $crashes,
            'success_rate' => $installs > 0 ? round($successes / $installs * 100, 2) : null,
            // 分母与成功率同口径(本批 installs):"这批下发里有多大比例最终崩了"。
            'crash_rate'   => $installs > 0 ? round($crashes / $installs * 100, 2) : null,
        ];
        $release->save();

        return $this->evaluate($release);
    }

    /**
     * 依据指标决定:继续 / 暂停 / 回滚。
     *
     * 两个回滚判据**正交**,任一命中即回滚:
     *   · 成功率 < ROLLBACK_THRESHOLD —— 没装成;
     *   · 崩溃率 > CRASH_RATE_ROLLBACK_THRESHOLD —— 装成了但一跑就崩(成功率还能是 100%)。
     */
    public function evaluate(GrayRelease $release): array
    {
        $rate = $release->metrics['success_rate'] ?? null;

        if ($rate === null) {
            return ['action' => 'continue', 'reason' => '暂无数据'];
        }

        // 崩溃率优先于成功率判据:崩溃是"装上了却不可用",比"没装上"更该立刻下闸。
        // ⚠ crash_rate 为 null 表示"这批还没有崩溃观测"(installs=0),不得当作 0 之外的东西;
        //   这里只在非 null 时才比,避免 null 被当 0 判断。
        $crashRate = $release->metrics['crash_rate'] ?? null;

        if ($crashRate !== null && $crashRate > self::CRASH_RATE_ROLLBACK_THRESHOLD) {
            $affected = $this->abort(
                $release,
                "崩溃率 {$crashRate}% > ".self::CRASH_RATE_ROLLBACK_THRESHOLD.'%,自动回滚',
                'auto',
                self::ACTOR_AUTO,
            );

            return [
                'action'           => 'rollback',
                'reason'           => "崩溃率 {$crashRate}% 高于回滚阈值",
                'affected_devices' => $affected,
            ];
        }

        if ($rate < self::ROLLBACK_THRESHOLD) {
            // affected 走 abort() 的返回值:已经是终态时它返回 0(守卫命中),
            // 此时**没有**新回滚发生 —— 若这里不带上这个数,响应会谎报"刚回滚了"。
            $affected = $this->abort(
                $release,
                "成功率 {$rate}% < ".self::ROLLBACK_THRESHOLD.'%,自动回滚',
                'auto',
                self::ACTOR_AUTO,
            );

            return [
                'action'           => 'rollback',
                'reason'           => "成功率 {$rate}% 低于回滚阈值",
                'affected_devices' => $affected,
            ];
        }

        if ($rate < self::PAUSE_THRESHOLD) {
            $release->status = 'paused';
            $release->save();

            return ['action' => 'pause', 'reason' => "成功率 {$rate}% 低于暂停阈值"];
        }

        return ['action' => 'continue', 'reason' => "成功率 {$rate}% 正常"];
    }

    /**
     * 从库中聚合指标并评估 —— 自驱路径(设备回报后由 DeployTaskService 调用)。
     *
     * 口径:该版本下所有下发任务的 总数 / success / failed+timeout。
     *
     * ⚠ 口径排除**空跑**(P11):带 `PKG_MISSING` 的 success 是"看起来成功、实际没装"
     *   —— 它既不是成功也不是失败,计进任何一边都会让灰度判定失准(成功的虚高会把
     *   该回滚的判成健康)。`DeployTask::scopeNotDryRun()` 是这段口径的唯一出处。
     *
     * B-11:崩溃数从设备回报的 `error_code = CRASH` 取 —— 崩溃是**装后**事件,
     *   与 status 正交(一条 success 的安装任务之后仍可能崩),故单列一维而非并入 failures。
     */
    public function evaluateFromRecords(GrayRelease $release): array
    {
        $tasks = DeployTask::query()
            ->where('skill_version_id', $release->skill_version_id)
            ->notDryRun();

        $installs = (clone $tasks)->count();
        $successes = (clone $tasks)->where('status', 'success')->count();
        $failures = (clone $tasks)->whereIn('status', ['failed', 'timeout'])->count();
        $crashes = (clone $tasks)->where('error_code', DeployTask::ERR_CRASH)->count();

        return $this->recordMetric($release->id, $installs, $successes, $failures, $crashes) ?? [];
    }

    /**
     * 中止并回滚:向"已更新该版本"的设备推回滚任务。
     *
     * @param  string  $trigger    auto(阈值命中)/ manual(人工)
     * @param  string|null  $createdBy  发起者:auto 路径传 ACTOR_AUTO;人工路径传调用方自报身份
     * @param  string|null  $ip         来源 IP
     */
    public function abort(
        GrayRelease $release,
        string $reason = '',
        string $trigger = 'auto',
        ?string $createdBy = null,
        ?string $ip = null,
    ): int {
        // 幂等守卫:灰度已是终态就直接返回,**不再回滚一次**。
        //
        // 重复调用是**常态**而不是异常:
        //   · 自驱路径 —— 每次设备回报都走 evaluateFromRecords → evaluate → 阈值命中 → abort。
        //     阈值是"当前成功率",一旦跌下去,后续每一条回报都会再命中一次;
        //   · 平台端 —— 运维看到告警后可能手动再点一次 abort。
        // 此前没有这道判断,第二次调用会**再建一整轮回滚任务**(每条都是真下发)+ 再写一条
        // rollback_record,affected_devices 还会把同一批设备重复计数。
        if (in_array($release->status, self::TERMINAL_STATUSES, true)) {
            return 0;
        }

        $release->status = 'aborted';
        $release->save();

        // ⚠ 原实现写死 skillId: 0 —— 会让 deploy_task.skill_id 全错,
        //   连带 KillSwitchService 按 product 匹配失效。必须从版本反查 product_id。
        $productId = (int) (SkillVersion::find($release->skill_version_id)?->product_id ?? 0);

        // 回滚靶子 = **本次灰度真正成功下发过**的设备。
        //
        // 为什么不再按 install_record.status='updated' 找(原实现):
        //   `updated` 只在"同 product 已有旧版已装记录"时才写(见 DeployTaskService::isUpgrade())。
        //   **首装**该技能的设备落的是 `installed`,永远进不了 updated 集合 —— 于是对首装设备
        //   推坏版本后 abort(),找到的靶子是**空的**:坏版本原地留着、affected=0,而灰度却
        //   报告"已回滚"。这是"回滚靶子可能是空的"这条缺口的根因。
        //
        // 改从 deploy_task 反查才对准真实靶子:谁**成功装上/升级到**这个版本,谁就得回滚。
        //   · 只认 operation=OP_INSTALL —— 装与升级同属此操作;ENABLE/DISABLE 不改安装事实;
        //   · 只认 status='success' —— failed/timeout/未终结的设备上根本没有这个版本,无需回滚;
        //   · 同一设备可能有多条成功记录(重装),按 did 去重,只回滚一次。
        $targets = DeployTask::query()
            ->where('skill_version_id', $release->skill_version_id)
            ->where('operation', DeployTask::OP_INSTALL)
            ->where('status', 'success')
            ->orderByDesc('id')
            ->get()
            ->unique('did');

        $count = 0;
        foreach ($targets as $task) {
            $this->deploy->create(
                did: $task->did,
                skillId: $productId,
                skillVersionId: $release->skill_version_id,
                entitlementId: $task->entitlement_id,
                operation: DeployTask::OP_ROLLBACK,
                channel: 'agent',
                createdBy: 'gray_rollback',
            );
            $count++;
        }

        $actor = $createdBy ?: ($trigger === 'auto' ? self::ACTOR_AUTO : 'internal');

        RollbackRecord::create([
            'gray_release_id'  => $release->id,
            'trigger'          => $trigger,
            'reason'           => $reason,
            'affected_devices' => $count,
            'created_by'       => $actor,
            'ip'               => $ip,
            'created_at'       => now(),
        ]);

        // 审计:回滚是"拿别人的设备执行动作",必须能回答"谁、从哪、什么时候、影响几台"。
        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            null,
            'gray_release.abort',
            'gray_release',
            $release->id,
            [
                'operator'         => $actor,
                'trigger'          => $trigger,
                'reason'           => $reason,
                'affected_devices' => $count,
                'skill_version_id' => $release->skill_version_id,
            ],
            $ip
        );

        Log::warning('灰度回滚已执行', [
            'release_id'       => $release->id,
            'skill_version_id' => $release->skill_version_id,
            'trigger'          => $trigger,
            'actor'            => $actor,
            'affected_devices' => $count,
        ]);

        return $count;
    }
}
