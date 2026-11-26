<?php

namespace Webkul\Shop\Services\Deploy;

use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Services\Deploy\Contracts\AgentFactEnvelopeV3;

/**
 * 事件正文 v3 验签（WBS A1）。
 *
 * 顺序：形状 → 外层/内层一致性 → key_id 绑定 → 签名 → 时间窗。
 * 任何一步失败都必须在 Inbox / 任务状态 / SkillExecution 修改**之前**返回。
 */
class AgentFactSignatureVerifier
{
    public const LEVEL_V3 = 'v3';

    public const LEVEL_V2_REQUEST_ONLY = 'v2_request_only';

    public const CODE_REQUIRED = 'AGENT_FACT_SIGNATURE_REQUIRED';

    public const CODE_INVALID = 'AGENT_FACT_SIGNATURE_INVALID';

    public const CODE_KEY_UNKNOWN = 'AGENT_FACT_KEY_UNKNOWN';

    /** A7：密钥已被吊销（含"唯一活动密钥被吊销后的离线状态"）。 */
    public const CODE_KEY_REVOKED = 'AGENT_FACT_KEY_REVOKED';

    /** A7：密钥已退休且超出轮换窗口，或代次落后于允许的 grace 代。 */
    public const CODE_KEY_STALE = 'AGENT_FACT_KEY_STALE';

    /** A7：密钥尚未生效（not_before 在未来）。 */
    public const CODE_KEY_INACTIVE = 'AGENT_FACT_KEY_INACTIVE';

    public const CODE_BODY_MISMATCH = 'AGENT_FACT_BODY_MISMATCH';

    public function __construct(
        protected DeviceCredentialService $credentials,
        protected DeviceKeyProvider $keys,
    ) {}

    /**
     * @return array{ok:bool,code:?string,message:?string,level:string,key_id:?string,envelope:?AgentFactEnvelopeV3}
     */
    public function verify(DeviceBinding $binding, array $payload): array
    {
        $envelopeData = $payload['fact_envelope'] ?? null;

        if (! is_array($envelopeData)) {
            if ($this->requiresV3($binding)) {
                return $this->result(false, self::CODE_REQUIRED, '该设备必须为事件正文提供 agent-fact/v3 签名。');
            }

            if (! (bool) config('agent_fact_signature.v2_window_enabled', true)) {
                return $this->result(false, self::CODE_REQUIRED, 'v2 事件正文兼容窗口已关闭，必须提供 agent-fact/v3 签名。');
            }

            return [
                'ok'       => true,
                'code'     => null,
                'message'  => null,
                'level'    => self::LEVEL_V2_REQUEST_ONLY,
                'key_id'   => null,
                'envelope' => null,
            ];
        }

        try {
            $envelope = AgentFactEnvelopeV3::fromArray($envelopeData);
        } catch (\InvalidArgumentException $exception) {
            return $this->result(false, self::CODE_INVALID, $exception->getMessage());
        }

        $mismatch = $this->mismatchedFields($envelope, $payload);
        if ($mismatch !== []) {
            return $this->result(
                false,
                self::CODE_BODY_MISMATCH,
                '签名内层与外层字段不一致：'.implode(', ', $mismatch),
            );
        }

        $material = $this->keys->resolve($binding->did, $envelope->keyId);

        if ($material === null) {
            // 完全没有密钥材料的历史设备：仍按 v2 窗口处理（只读兼容）；
            // 有材料但 key_id 未知，则是明确的伪造/配错 —— 一律拒绝。
            if (! $this->credentials->hasAnyKeyMaterial($binding)) {
                if (! (bool) config('agent_fact_signature.v2_window_enabled', true)) {
                    return $this->result(false, self::CODE_KEY_UNKNOWN, '该设备没有可用于验签的证书。');
                }

                return [
                    'ok'       => true,
                    'code'     => null,
                    'message'  => null,
                    'level'    => self::LEVEL_V2_REQUEST_ONLY,
                    'key_id'   => null,
                    'envelope' => null,
                ];
            }

            return $this->result(false, self::CODE_KEY_UNKNOWN, 'key_id 未登记在该设备的密钥环中。');
        }

        // A7.2：吊销 / 未生效 / 退休超窗 / 代次落后 —— 每种都要有独立错误码，
        //   否则运维只能看到"验签失败"，分不清是密钥被吊销还是时钟不对。
        if ($material->isRevoked()) {
            return $this->result(false, self::CODE_KEY_REVOKED, '该密钥已被吊销。');
        }

        $now = now()->timestamp;

        if ($material->notBefore !== null && $now < $material->notBefore) {
            return $this->result(false, self::CODE_KEY_INACTIVE, '该密钥尚未进入生效窗口。');
        }

        $grace = (int) config('deploy_gateways.device_credential.epoch_grace', 1);
        $minEpoch = max(1, $this->keys->currentEpoch($binding->did) - max(0, $grace));

        if (! $material->isUsableAt($now, $minEpoch)) {
            return $this->result(false, self::CODE_KEY_STALE, '该密钥已超出轮换窗口或代次过旧。');
        }

        if (! $this->credentials->verifyDetachedWithCert($material->certPem, $envelope->canonical(), $envelope->signature)) {
            return $this->result(false, self::CODE_INVALID, 'agent-fact/v3 签名校验失败。');
        }

        $maxAge = (int) config('agent_fact_signature.max_age_seconds', 604800);
        $maxSkew = (int) config('agent_fact_signature.max_clock_skew_seconds', 300);

        if ($envelope->reportedAt > $now + $maxSkew || $now - $envelope->reportedAt > $maxAge) {
            return $this->result(false, self::CODE_INVALID, '事件 reported_at 超出允许时钟窗口。');
        }

        return [
            'ok'       => true,
            'code'     => null,
            'message'  => null,
            'level'    => self::LEVEL_V3,
            'key_id'   => $envelope->keyId,
            'envelope' => $envelope,
        ];
    }

    /** 只要持有任何密钥材料（含已吊销）就必须提供 v3 签名，绝不允许退回无签名路径。 */
    protected function requiresV3(DeviceBinding $binding): bool
    {
        if (! (bool) config('agent_fact_signature.require_v3', true)) {
            return false;
        }

        return $this->credentials->hasAnyKeyMaterial($binding);
    }

    /**
     * 外层 payload 与签名内层的字段必须描述同一件事，防止“签名 A、提交 B”。
     *
     * @return array<int, string>
     */
    protected function mismatchedFields(AgentFactEnvelopeV3 $envelope, array $payload): array
    {
        $mismatch = [];

        if ($envelope->eventId !== (string) ($payload['event_id'] ?? '')) {
            $mismatch[] = 'event_id';
        }

        if ($envelope->did !== (string) ($payload['did'] ?? '')) {
            $mismatch[] = 'did';
        }

        if ($envelope->taskNo !== (string) ($payload['task_no'] ?? '')) {
            $mismatch[] = 'task_no';
        }

        if ($envelope->phase !== (string) ($payload['phase'] ?? '')) {
            $mismatch[] = 'phase';
        }

        if ($envelope->reportedAt !== (int) ($payload['reported_at'] ?? 0)) {
            $mismatch[] = 'reported_at';
        }

        if (array_key_exists('event_seq', $payload)
            && $payload['event_seq'] !== null
            && $envelope->eventSeq !== (int) $payload['event_seq']) {
            $mismatch[] = 'event_seq';
        }

        $outerEvidence = is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [];
        if ($envelope->evidence !== $outerEvidence) {
            $mismatch[] = 'evidence';
        }

        if (! $this->executionFactMatches($envelope->executionFact, $payload)) {
            $mismatch[] = 'execution_fact';
        }

        return $mismatch;
    }

    /**
     * 签名内的执行事实必须是外层 payload 对应字段的**同一子集**。
     */
    protected function executionFactMatches(?array $signedFact, array $payload): bool
    {
        if ($signedFact === null) {
            return true;
        }

        foreach ($signedFact as $key => $value) {
            if (! array_key_exists($key, $payload)) {
                return false;
            }

            if ($payload[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    protected function result(bool $ok, ?string $code, ?string $message): array
    {
        return [
            'ok'       => $ok,
            'code'     => $code,
            'message'  => $message,
            'level'    => self::LEVEL_V3,
            'key_id'   => null,
            'envelope' => null,
        ];
    }
}
