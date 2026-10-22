<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Shop\Services\Ops\OperationLogger;

/** 同库事务、强审计、幂等和事件；事件只带对象引用，不复制客资。 */
class SceneWorkflow
{
    public function enabled(string $feature): void
    {
        if (! config('scenes.workflow_enabled', true)) {
            throw new SceneWorkflowException('SCENE_WRITES_DISABLED', '场景业务写入已全局停用，请稍后再试。', 503);
        }
        if (! config('scenes.'.$feature, false)) {
            throw new SceneWorkflowException('SCENE_WRITES_DISABLED', '此项服务暂未开放，请稍后重试。', 503);
        }
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR, 32);
    }

    public static function unpack(?string $value, mixed $default = []): mixed
    {
        return $value === null ? $default : json_decode($value, true, 32, JSON_THROW_ON_ERROR);
    }

    public static function utc(string $value): string
    {
        return \Illuminate\Support\Carbon::parse($value, 'UTC')->utc()->format('Y-m-d H:i:s');
    }

    public static function hash(mixed $value): string
    {
        $sort = function ($v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };

        return hash('sha256', self::json($sort($value)));
    }

    public function command(string $principal, string $operation, string $key, array $payload, callable $write, ?callable $authorizeReplay = null): array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key)) {
            throw new SceneWorkflowException('IDEMPOTENCY_KEY_REQUIRED', '请提供有效的幂等键。');
        }

        return DB::transaction(function () use ($principal, $operation, $key, $payload, $write, $authorizeReplay) {
            $identity = ['principal_scope' => $principal, 'operation' => $operation, 'key_hash' => hash('sha256', $key)];
            DB::table('business_idempotency_keys')->insertOrIgnore($identity + [
                'request_hash' => self::hash($payload), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            $record = DB::table('business_idempotency_keys')->where($identity)->lockForUpdate()->first();
            if (! $record || ! hash_equals($record->request_hash, self::hash($payload))) {
                throw new SceneWorkflowException('IDEMPOTENCY_CONFLICT', '同一提交标识的内容发生变化，请重新提交。', 409);
            }
            if ($record->result !== null) {
                $result = self::unpack($record->result);
                if ($authorizeReplay) {
                    $authorizeReplay($result);
                }

                return $result;
            }
            $result = $write();
            DB::table('business_idempotency_keys')->where('id', $record->id)->update(['result' => self::json($result), 'updated_at' => now('UTC')]);

            return $result;
        }, 3);
    }

    public function record(string $actorType, ?int $actorId, string $type, int $id, int $version, string $event, array $safe = []): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('场景变更必须在主库事务中审计并写 outbox。');
        }
        app(OperationLogger::class)->logOrFail($actorType, $actorId, $event, $type, $id, ['version' => $version] + $safe);
        DB::table('business_outbox')->insert([
            'event_id'      => (string) Str::uuid(), 'aggregate_type' => $type, 'aggregate_id' => $id,
            'aggregate_seq' => $version, 'event_type' => $event, 'schema_version' => 1,
            'payload'       => self::json(['id' => $id, 'version' => $version] + $safe),
            'available_at'  => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
    }

    public static function version(object $row, int $expected): void
    {
        if ((int) $row->row_version !== $expected) {
            throw new SceneWorkflowException('SCENE_VERSION_CONFLICT', '资料已更新，请刷新后再操作。', 409);
        }
    }

    public static function requireRow(string $table, int $id, bool $lock = true): object
    {
        $query = DB::table($table)->where('id', $id);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row) {
            throw new SceneWorkflowException('SCENE_OBJECT_NOT_FOUND', '记录不存在或无权访问。', 404);
        }

        return $row;
    }
}
