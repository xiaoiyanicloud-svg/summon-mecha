<?php

namespace Webkul\Shop\Services\SkillCompile;

use Illuminate\Support\Facades\DB;
use Throwable;
use Webkul\Shop\Models\SkillBuildCommand;
use Webkul\Shop\Services\Deploy\SigningService;

/**
 * 构建命令应用服务（WBS B1）。
 *
 * 事务边界（B1.6）：
 *   1. 事务内只做 idempotency 查重 + 落命令（outbox），绝不调用 builder / HTTP / SDK；
 *   2. 事务提交后再 claim（queued → running），然后执行 builder；
 *   3. 迟到 worker 不能覆盖 canceled/已完成终态（终态更新带 status=running 条件）。
 *
 * 幂等（B1.5）：
 *   同 `(seller_id, idempotency_key)` 同载荷 → 返回原记录（duplicate=true）；
 *   同键异载荷 → BuildCommandConflictException（控制器映射 409）。
 */
class BuildCommandService
{
    /** internal.key 通道没有具体 seller 身份，统一记平台作用域。 */
    public const PLATFORM_SELLER_ID = 1;

    public function __construct(protected SkillBuildService $builder) {}

    /**
     * @return array{command_id:int,status:string,duplicate:bool,result:?array,error_code:?string,error_message:?string,notice:?string}
     */
    public function submit(BuildCommand $command, array $manifest, array $options = []): array
    {
        $requestHash = $this->requestHash($command, $manifest, $options);

        [$record, $created] = DB::transaction(function () use ($command, $manifest, $options, $requestHash) {
            $existing = SkillBuildCommand::query()
                ->where('seller_id', $command->sellerId)
                ->where('idempotency_key', $command->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                    throw new BuildCommandConflictException(
                        '同一 idempotency_key 在 seller 作用域内对应不同构建载荷。'
                    );
                }

                return [$existing, false];
            }

            $record = SkillBuildCommand::create([
                'seller_id'             => $command->sellerId,
                'idempotency_key'       => $command->idempotencyKey,
                'request_hash'          => $requestHash,
                'status'                => SkillBuildCommand::STATUS_QUEUED,
                'skill_version_id'      => $command->skillVersionId,
                'source_asset_id'       => $command->sourceAssetId,
                'source_digest'         => $command->sourceDigest,
                'target_model_code'     => $command->targetModelCode,
                'target_profile_digest' => $command->targetProfileDigest,
                'compiler_version'      => $command->compilerVersion,
                'requested_by'          => $command->requestedBy,
                'payload_json'          => ['manifest' => $manifest, 'options' => $options],
            ]);

            return [$record, true];
        });

        if (! $created) {
            // 同键同载荷：原样返回既有记录，不重复执行 builder。
            return $this->toResponse($record->fresh(), true);
        }

        if ((bool) config('skill_build.async_enabled', false)) {
            // 异步模式：命令已落库（outbox），由 skill:build-worker 领取。
            return $this->toResponse($record, false);
        }

        // 同步模式：事务已提交，再 claim + 执行（不在事务内调 builder）。
        if (! $this->claim((int) $record->id, $this->workerId())) {
            return $this->toResponse($record->fresh(), false);
        }

        return $this->run(SkillBuildCommand::query()->whereKey($record->id)->firstOrFail());
    }

    /**
     * 原子领取：只有 queued → running 成功的那一个 worker 得到一条记录。
     * MySQL 5.7 无 SKIP LOCKED，用条件 UPDATE 的 affected rows 裁决。
     */
    public function claim(int $commandId, string $workerId): bool
    {
        $affected = DB::table('skill_build_commands')
            ->where('id', $commandId)
            ->where('status', SkillBuildCommand::STATUS_QUEUED)
            ->update([
                'status'     => SkillBuildCommand::STATUS_RUNNING,
                'worker_id'  => $workerId,
                'started_at' => now(),
                'updated_at' => now(),
            ]);

        return $affected === 1;
    }

    /**
     * 执行一条已 claim（status=running）的命令。
     */
    public function run(SkillBuildCommand $record): array
    {
        // 已被取消 / 已终态的命令不得再产生物理构建副作用。
        if ((string) $record->status !== SkillBuildCommand::STATUS_RUNNING) {
            return $this->toResponse($record, false, 'COMMAND_NOT_RUNNING');
        }

        $payload = is_array($record->payload_json) ? $record->payload_json : [];
        $manifest = is_array($payload['manifest'] ?? null) ? $payload['manifest'] : [];
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];

        $command = new BuildCommand(
            skillVersionId: (int) $record->skill_version_id,
            sourceAssetId: (int) $record->source_asset_id,
            sourceDigest: (string) $record->source_digest,
            targetModelCode: (string) $record->target_model_code,
            targetProfileDigest: (string) $record->target_profile_digest,
            compilerVersion: (string) $record->compiler_version,
            idempotencyKey: (string) $record->idempotency_key,
            requestedBy: (string) $record->requested_by,
            sellerId: (int) $record->seller_id,
        );

        try {
            $result = $this->builder->compileWithCommand($command, $manifest, $options);
        } catch (Throwable $exception) {
            $this->finalize($record->id, [
                'status'        => SkillBuildCommand::STATUS_FAILED,
                'error_code'    => 'BUILD_EXCEPTION',
                'error_message' => $exception::class.': '.$exception->getMessage(),
            ]);

            return $this->toResponse($record->fresh(), false);
        }

        $status = $result->status === 'failed'
            ? SkillBuildCommand::STATUS_FAILED
            : SkillBuildCommand::STATUS_COMPLETED;

        $runId = null;
        if ($result->buildKey && $result->runNumber) {
            $runId = DB::table('skill_build_runs')
                ->where('build_key', $result->buildKey)
                ->where('run_number', $result->runNumber)
                ->value('id');
        }

        $updated = $this->finalize($record->id, [
            'status'        => $status,
            'build_key'     => $result->buildKey,
            'build_run_id'  => $runId,
            'result_json'   => $result->toArray(),
            'error_code'    => $result->errorCode,
            'error_message' => $result->error,
        ]);

        if (! $updated) {
            // 已被取消或别的 worker 先写终态：不覆盖，如实返回当前状态。
            return $this->toResponse($record->fresh(), false, 'COMMAND_STATE_CHANGED');
        }

        return $this->toResponse($record->fresh(), false);
    }

    /**
     * 取消仅允许 queued/running；已完成/失败/已取消不可再改。
     */
    public function cancel(int $commandId): bool
    {
        $affected = DB::table('skill_build_commands')
            ->where('id', $commandId)
            ->whereIn('status', [SkillBuildCommand::STATUS_QUEUED, SkillBuildCommand::STATUS_RUNNING])
            ->update([
                'status'      => SkillBuildCommand::STATUS_CANCELED,
                'finished_at' => now(),
                'updated_at'  => now(),
            ]);

        return $affected === 1;
    }

    /**
     * 有界批次领取并执行（供 skill:build-worker 手工/定时调用）。
     *
     * @return array{claimed:int,completed:int,failed:int,skipped:int,errors:int}
     */
    public function runQueued(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));

        $ids = SkillBuildCommand::query()
            ->where('status', SkillBuildCommand::STATUS_QUEUED)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $summary = ['claimed' => 0, 'completed' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($ids as $id) {
            $workerId = $this->workerId();

            if (! $this->claim((int) $id, $workerId)) {
                $summary['skipped']++;
                continue;
            }

            $summary['claimed']++;

            try {
                $record = SkillBuildCommand::query()->whereKey($id)->firstOrFail();
                $response = $this->run($record);

                if (($response['status'] ?? '') === SkillBuildCommand::STATUS_FAILED) {
                    $summary['failed']++;
                } else {
                    $summary['completed']++;
                }
            } catch (Throwable $exception) {
                $summary['errors']++;
                $this->finalize((int) $id, [
                    'status'        => SkillBuildCommand::STATUS_FAILED,
                    'error_code'    => 'BUILD_WORKER_EXCEPTION',
                    'error_message' => $exception::class.': '.$exception->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * 只允许 running → 终态；返回是否真正写入（false = 状态已变，迟到结果作废）。
     */
    protected function finalize(int $commandId, array $values): bool
    {
        $affected = DB::table('skill_build_commands')
            ->where('id', $commandId)
            ->where('status', SkillBuildCommand::STATUS_RUNNING)
            ->update(array_merge($values, [
                'finished_at' => now(),
                'updated_at'  => now(),
            ]));

        return $affected === 1;
    }

    public function requestHash(BuildCommand $command, array $manifest, array $options): string
    {
        return hash('sha256', SigningService::jcsCanonicalize([
            'command'  => $command->toArray(),
            'manifest' => $manifest,
            'options'  => $options,
        ]));
    }

    protected function workerId(): string
    {
        $host = gethostname() ?: 'host';

        return 'worker:'.$host.':'.getmypid();
    }

    protected function toResponse(SkillBuildCommand $record, bool $duplicate, ?string $notice = null): array
    {
        return [
            'command_id'    => (int) $record->id,
            'status'        => (string) $record->status,
            'duplicate'     => $duplicate,
            'result'        => is_array($record->result_json) ? $record->result_json : null,
            'error_code'    => $record->error_code,
            'error_message' => $record->error_message,
            'notice'        => $notice,
        ];
    }
}
