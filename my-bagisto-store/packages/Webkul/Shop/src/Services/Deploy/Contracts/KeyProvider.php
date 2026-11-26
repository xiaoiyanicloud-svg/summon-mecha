<?php

namespace Webkul\Shop\Services\Deploy\Contracts;

/**
 * 密钥生命周期接口（WBS A7.1）。
 *
 * 为什么需要一个接口，而不是直接在验签处读 `binding.meta`：
 *   轮换、吊销、代次这些规则**必须只有一份**。验签点一旦自己解释 key 状态，
 *   就会长出"这个入口认旧 key、那个入口不认"的分叉，而这种分叉只在轮换当天暴露。
 *
 * 作用域 `$scope` 当前是设备的 `did`。接口刻意不绑定 `DeviceBinding` 模型，
 * 这样将来换成 KMS/HSM 后端时，调用方（验签器）不需要改。
 *
 * ⚠ A7.3：**不实现假 KMS/HSM**。当前唯一实现是本地 CA + binding.meta 的 key ring；
 *   `capabilities()` 必须如实声明 `kms=false / hsm=false`，否则上层会把"接口存在"
 *   误读成"密钥托管已经到位"。
 */
interface KeyProvider
{
    /** 按 key_id 解析一把密钥；未知 → null。 */
    public function resolve(string $scope, string $keyId): ?KeyMaterial;

    /** 当前应使用的活动 key_id；没有可用密钥 → null（fail-closed）。 */
    public function activeKeyId(string $scope): ?string;

    /** 当前代次（每次轮换 +1）。 */
    public function currentEpoch(string $scope): int;

    /** 轮换：签发新一代密钥，旧密钥进入 retirement 窗口。 */
    public function rotate(string $scope, string $reason = ''): ?KeyMaterial;

    /** 吊销一把密钥（立即失效，不等窗口）。 */
    public function revoke(string $scope, string $keyId, string $reason = ''): bool;

    /**
     * 后端能力声明。KMS/HSM 未接入时必须为 false。
     *
     * @return array{kms:bool,hsm:bool,rotation:bool,revocation:bool,epochs:bool,offline_recovery:bool}
     */
    public function capabilities(): array;
}
