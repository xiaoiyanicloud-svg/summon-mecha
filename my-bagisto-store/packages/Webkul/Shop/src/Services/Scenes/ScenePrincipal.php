<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

final class ScenePrincipal
{
    public function __construct(public readonly ?int $customerId, public readonly ?string $guestHash)
    {
        if (($customerId !== null && $customerId < 1) || ($customerId === null && (! $guestHash || ! preg_match('/^[a-f0-9]{64}$/D', $guestHash)))) {
            throw new SceneWorkflowException('SCENE_ACCESS_REQUIRED', '缺少有效需求主体。', 401);
        }
    }

    public static function fromRequest(Request $request): self
    {
        $customer = $request->user('customer');
        $token = (string) $request->header('X-Scene-Access', '');
        $hash = preg_match('/^[a-f0-9]{64}$/D', $token) ? hash('sha256', $token) : null;
        if (! $customer && ! $hash) {
            throw new SceneWorkflowException('SCENE_ACCESS_REQUIRED', '请登录或使用本机保存的需求访问凭证。', 401);
        }

        return new self($customer ? (int) $customer->id : null, $hash);
    }

    public function scope(): string
    {
        return $this->customerId ? 'customer:'.$this->customerId : 'guest:'.$this->guestHash;
    }

    public function guestCapability(): object
    {
        $row = $this->guestHash ? \Illuminate\Support\Facades\DB::table('scene_guest_capabilities')
            ->where('token_hash', $this->guestHash)->whereNull('revoked_at')->where('expires_at', '>', now('UTC'))->first() : null;
        if (! $row) {
            throw new SceneWorkflowException('SCENE_ACCESS_INVALID', '游客访问凭证无效或已过期，请使用原登录账号或重新发起需求。', $this->customerId ? 403 : 401);
        }

        return $row;
    }

    public function constrain(Builder $query): Builder
    {
        return $this->customerId
            ? $query->where('customer_id', $this->customerId)
            : $query->whereNull('customer_id')->where('guest_access_hash', $this->guestHash)->where('guest_expires_at', '>', now('UTC'));
    }

    public function request(int $id, bool $lock = false): object
    {
        if (! $this->customerId) {
            $this->guestCapability();
        }
        $query = $this->constrain(\Illuminate\Support\Facades\DB::table('scene_requests')->where('id', $id));
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row) {
            throw new SceneWorkflowException('SCENE_OBJECT_NOT_FOUND', '记录不存在或无权访问。', 404);
        }

        return $row;
    }
}
