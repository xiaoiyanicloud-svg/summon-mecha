<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\EventRegistrationRequest;

/**
 * G2-03 · 幂等请求事实。
 *
 * 调用约定：`begin()` / `complete()` 必须在业务事务内调用。
 *   · 行随业务回滚一起消失，不存在“请求已记录但业务没发生”的分叉；
 *   · 并发同 key 由 `INSERT IGNORE` + 唯一索引收敛，后到者读取先到者的事实；
 *   · 同 key 不同载荷 → 409；同 actor 不同 key 互不影响。
 *
 * payload_hash 只由服务端从规范化载荷计算，不接受客户端自报。
 */
class RegistrationIdempotency
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function payloadHash(array $payload): string
    {
        return hash('sha256', $this->canonicalJson($payload));
    }

    /**
     * 读取已完成请求；未完成/不存在返回 null。
     */
    public function find(string $actorType, int $actorId, string $action, string $requestKey): ?EventRegistrationRequest
    {
        $this->assertKey($requestKey);

        return EventRegistrationRequest::query()
            ->where('actor_type', $actorType)
            ->where('actor_id', $actorId)
            ->where('action', $action)
            ->where('request_key', $requestKey)
            ->first();
    }

    /**
     * 登记（或识别重放）请求。
     *
     * @return array{request: EventRegistrationRequest, replayed: bool}
     *
     * @throws RegistrationException 同 key 不同载荷 / 请求进行中
     */
    public function begin(
        string $actorType,
        int $actorId,
        string $action,
        string $requestKey,
        string $payloadHash,
        string $targetType,
        ?int $targetId,
    ): array {
        $this->assertKey($requestKey);

        $existing = $this->find($actorType, $actorId, $action, $requestKey);

        if ($existing !== null) {
            return $this->replayOrThrow($existing, $payloadHash, $targetType, $targetId);
        }

        // INSERT IGNORE：并发首次请求只有一个能插入；唯一索引是最终裁判。
        $now = now();
        $affected = DB::table('event_registration_requests')->insertOrIgnore([
            'actor_type'    => $actorType,
            'actor_id'      => $actorId,
            'action'        => $action,
            'request_key'   => $requestKey,
            'payload_hash'  => $payloadHash,
            'target_type'   => $targetType,
            'target_id'     => $targetId,
            'result_type'   => null,
            'result_id'     => null,
            'result_public_no' => null,
            'completed_at'  => null,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        if ($affected === 0) {
            // 另一个事务先插入了同 key。唯一索引会让这里读到其提交后的事实。
            $existing = $this->find($actorType, $actorId, $action, $requestKey);

            if ($existing === null) {
                throw RegistrationException::requestConflict('请求正在处理中，请稍后按同一请求键重试。');
            }

            return $this->replayOrThrow($existing, $payloadHash, $targetType, $targetId);
        }

        $request = $this->find($actorType, $actorId, $action, $requestKey);

        if ($request === null) {
            throw new \RuntimeException('幂等请求写入后无法读取。');
        }

        return ['request' => $request, 'replayed' => false];
    }

    public function complete(
        EventRegistrationRequest $request,
        string $resultType,
        int $resultId,
        ?string $publicNo = null,
    ): EventRegistrationRequest {
        DB::table('event_registration_requests')
            ->where('id', $request->getKey())
            ->whereNull('completed_at')
            ->update([
                'result_type'      => $resultType,
                'result_id'        => $resultId,
                'result_public_no' => $publicNo,
                'completed_at'     => now('UTC')->format('Y-m-d H:i:s'),
                'updated_at'       => now(),
            ]);

        return $request->refresh();
    }

    /**
     * @return array{request: EventRegistrationRequest, replayed: bool}
     */
    private function replayOrThrow(
        EventRegistrationRequest $existing,
        string $payloadHash,
        string $targetType,
        ?int $targetId,
    ): array {
        if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
            throw RegistrationException::requestConflict();
        }

        if ((string) $existing->target_type !== $targetType
            || ($targetId !== null && (int) $existing->target_id !== $targetId)) {
            throw RegistrationException::requestConflict('同一请求键被用于不同目标。');
        }

        if ($existing->completed_at === null) {
            throw RegistrationException::requestConflict('请求正在处理中，请稍后按同一请求键重试。');
        }

        return ['request' => $existing, 'replayed' => true];
    }

    private function assertKey(string $requestKey): void
    {
        $max = (int) config('ecosystem_registration.request_key_max_length', 100);

        if ($requestKey === '' || mb_strlen($requestKey) > $max) {
            throw RegistrationException::invalid('request_key 必填且长度不能超过 '.$max.'。');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $requestKey) === 1) {
            throw RegistrationException::invalid('request_key 含非法控制字符。');
        }
    }

    /**
     * 规范化 JSON：递归按键排序，避免 PHP 数组顺序造成同载荷不同 hash。
     */
    private function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map(fn ($item): string => $this->canonicalJson($item), $value)).']';
            }

            ksort($value);

            $parts = [];

            foreach ($value as $key => $item) {
                $parts[] = json_encode((string) $key, JSON_UNESCAPED_UNICODE).':'.$this->canonicalJson($item);
            }

            return '{'.implode(',', $parts).'}';
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
