<?php

namespace Webkul\Shop\Services\Deploy;

use Throwable;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;

/**
 * 声明式目标状态（WBS A4）。
 *
 * 目标：设备不再只依赖"平台主动推一条命令"，而是能问"这台机器**应该**装什么"，
 * 平台按「期望 ∩ 授权 ∩ 已发布版本」算出 desired，再和 `install_record` 的实际
 * 状态做 diff。
 *
 * ── 边界（刻意克制）──
 *   · **只报告 drift，不默认执行**。自动收敛由
 *     `deploy_gateways.desired_state.auto_converge` 控制，默认关闭；
 *     即使打开，也只对"声明了 agent 通道 + 有可下载文件"的技能建安装任务
 *     （否则会建出没有包的 dry install，把"没装"伪装成"装好了"）。
 *   · 不回收 extra：卸载是不可逆动作，必须走显式操作/人工确认。
 *   · 版本跟随最新只在 entitlement.skill_version_id 为 NULL 时发生；
 *     用户锁定了版本就尊重锁定版本。
 */
class DesiredStateService
{
    public function __construct(protected DeployTaskService $deploy) {}

    /**
     * @return array{
     *     generation: string,
     *     desired: array<int, array<string, mixed>>,
     *     actual: array<int, array<string, mixed>>,
     *     drift: array{missing: array, extra: array, version_mismatch: array},
     *     converge: array{enabled: bool, enqueued: array, errors: array}
     * }
     */
    public function snapshot(DeviceBinding $binding): array
    {
        $desired = $this->desired($binding);
        $actual = $this->actual($binding);
        $drift = $this->diff($desired, $actual);

        return [
            'generation' => $this->generation($desired),
            'desired'    => array_values($desired),
            'actual'     => array_values($actual),
            'drift'      => $drift,
            'converge'   => $this->converge($binding, $desired, $drift),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>  keyed by product_id
     */
    public function desired(DeviceBinding $binding): array
    {
        if (empty($binding->devices_id)) {
            return [];
        }

        $entitlements = SkillEntitlement::query()
            ->where('device_id', (int) $binding->devices_id)
            ->whereIn('status', [SkillEntitlement::STATUS_GRANTED, SkillEntitlement::STATUS_ACTIVE])
            ->whereNull('revoked_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('id')
            ->get();

        $desired = [];

        foreach ($entitlements as $entitlement) {
            if (! $entitlement->isOrderPaid()) {
                continue;
            }

            $productId = (int) $entitlement->product_id;

            // 同一技能多条授权时，保留 id 最大的那条（版本锁定更具体）
            if (isset($desired[$productId])) {
                continue;
            }

            $version = $this->resolveVersion($entitlement);

            if ($version === null) {
                continue;
            }

            $desired[$productId] = [
                'skill_id'         => $productId,
                'skill_version_id' => (int) $version->id,
                'version'          => (string) $version->version,
                'license_mode'     => $version->license_mode,
                'delivery_channels' => (array) ($version->delivery_channels ?? []),
                'package_sha256'   => $this->packageHash((int) $version->id),
                'entitlement_id'   => (int) $entitlement->id,
                'expires_at'       => $entitlement->expires_at?->timestamp,
            ];
        }

        ksort($desired);

        return $desired;
    }

    protected function resolveVersion(SkillEntitlement $entitlement): ?SkillVersion
    {
        if ($entitlement->skill_version_id) {
            $version = SkillVersion::query()->find((int) $entitlement->skill_version_id);

            return $version && $version->status === SkillVersion::STATUS_PUBLISHED ? $version : null;
        }

        return SkillVersion::query()
            ->where('product_id', (int) $entitlement->product_id)
            ->where('status', SkillVersion::STATUS_PUBLISHED)
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->first();
    }

    protected function packageHash(int $versionId): ?string
    {
        $hash = SkillAsset::query()
            ->where('skill_version_id', $versionId)
            ->whereNotNull('file_hash')
            ->orderBy('sort_order')
            ->value('file_hash');

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * @return array<int, array<string, mixed>>  keyed by product_id
     */
    public function actual(DeviceBinding $binding): array
    {
        $records = InstallRecord::query()
            ->where('did', $binding->did)
            ->whereIn('status', ['installed', 'updated'])
            ->orderByDesc('id')
            ->get();

        $actual = [];

        foreach ($records as $record) {
            $version = SkillVersion::query()->find((int) $record->skill_version_id);

            if (! $version) {
                continue;
            }

            $productId = (int) $version->product_id;

            if (isset($actual[$productId])) {
                continue;
            }

            $actual[$productId] = [
                'skill_id'         => $productId,
                'skill_version_id' => (int) $version->id,
                'version'          => (string) $record->current_version,
                'install_record_id' => (int) $record->id,
                'status'           => $record->status,
            ];
        }

        ksort($actual);

        return $actual;
    }

    /**
     * @param  array<int, array<string, mixed>>  $desired
     * @param  array<int, array<string, mixed>>  $actual
     * @return array{missing: array, extra: array, version_mismatch: array}
     */
    public function diff(array $desired, array $actual): array
    {
        $missing = [];
        $mismatch = [];

        foreach ($desired as $productId => $item) {
            if (! isset($actual[$productId])) {
                $missing[] = $item;
                continue;
            }

            if ((int) $actual[$productId]['skill_version_id'] !== (int) $item['skill_version_id']) {
                $mismatch[] = [
                    'skill_id'         => (int) $productId,
                    'desired_version_id' => (int) $item['skill_version_id'],
                    'actual_version_id'  => (int) $actual[$productId]['skill_version_id'],
                ];
            }
        }

        $extra = array_values(array_filter(
            $actual,
            fn (array $item) => ! isset($desired[(int) $item['skill_id']])
        ));

        return ['missing' => $missing, 'extra' => $extra, 'version_mismatch' => $mismatch];
    }

    /**
     * @param  array<int, array<string, mixed>>  $desired
     */
    public function generation(array $desired): string
    {
        $canonical = [];

        foreach ($desired as $item) {
            $canonical[] = [(int) $item['skill_id'], (int) $item['skill_version_id']];
        }

        sort($canonical);

        return substr(hash('sha256', json_encode($canonical)), 0, 32);
    }

    /**
     * 自动收敛（默认关闭）。只对"有 agent 通道 + 有包哈希"的缺失项建安装任务。
     *
     * @param  array<int, array<string, mixed>>  $desired
     * @param  array{missing: array, extra: array, version_mismatch: array}  $drift
     * @return array{enabled: bool, enqueued: array, errors: array}
     */
    protected function converge(DeviceBinding $binding, array $desired, array $drift): array
    {
        $enabled = (bool) config('deploy_gateways.desired_state.auto_converge', false);

        if (! $enabled || $drift['missing'] === []) {
            return ['enabled' => $enabled, 'enqueued' => [], 'errors' => []];
        }

        $enqueued = [];
        $errors = [];

        foreach ($drift['missing'] as $item) {
            $channels = (array) ($item['delivery_channels'] ?? []);

            if (! in_array('agent', $channels, true) || empty($item['package_sha256'])) {
                $errors[] = [
                    'skill_id' => (int) $item['skill_id'],
                    'reason'   => 'NO_AGENT_DELIVERABLE',
                ];
                continue;
            }

            try {
                $task = $this->deploy->create(
                    did: (string) $binding->did,
                    skillId: (int) $item['skill_id'],
                    skillVersionId: (int) $item['skill_version_id'],
                    entitlementId: (int) $item['entitlement_id'],
                    operation: DeployTask::OP_INSTALL,
                    channel: 'agent',
                    createdBy: 'desired_state',
                );
                $enqueued[] = $task->task_no;
            } catch (Throwable $exception) {
                $errors[] = [
                    'skill_id' => (int) $item['skill_id'],
                    'reason'   => 'ENQUEUE_FAILED',
                    'message'  => $exception->getMessage(),
                ];
            }
        }

        return ['enabled' => $enabled, 'enqueued' => $enqueued, 'errors' => $errors];
    }
}
