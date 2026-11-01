<?php

namespace Webkul\Shop\Services\Merchant;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\CooperationApplication;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\MerchantMember;
use Webkul\Shop\Models\MerchantProfile;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\PortalBackendRegistry;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * 统一后台选择入口注册表与权限过滤服务。
 *
 * 设计原则：
 * 1. 前端不硬编码三个后台，完全由本服务聚合 config('portal_backends.backends') 与
 *    portal_backend_registry 表（支持按 code 覆盖或新增第 N 个后台），按 sort 升序返回。
 * 2. 严格区分「未启用（enabled=false）」与「无权限（hasPermission=false）」，
 *    并统一输出 routeUrl / disabledReason（同时兼容 snake_case 字段）。
 * 3. 点击埋点统一经 OperationLogger 落入不可篡改的 operation_log 表。
 */
class PortalBackendRegistryService
{
    public const ACTION_PORTAL_CLICK = 'portal.backend.click';

    public function __construct(
        protected OperationLogger $ops,
    ) {}

    /**
     * 获取当前用户的后台列表（含启用态、权限判定与禁用原因）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForCustomer(?Customer $customer): array
    {
        $rawBackends = $this->mergedRegistry();
        $userPermissions = $this->resolveUserPermissions($customer);

        $result = [];

        foreach ($rawBackends as $item) {
            $code = (string) ($item['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $enabled = (bool) ($item['enabled'] ?? false);
            $requiredPerms = array_values(array_filter((array) ($item['permissions'] ?? []), 'is_string'));
            $routeUrl = (string) ($item['route_url'] ?? $item['routeUrl'] ?? '/');
            $sort = (int) ($item['sort'] ?? 100);

            [$hasPermission, $reason, $onboardingStatus] = $this->evaluateAccess(
                $code,
                $enabled,
                $requiredPerms,
                $userPermissions,
                $customer,
                $item['disabled_reason'] ?? null
            );

            $canAccess = $enabled && $hasPermission;
            $disabledReason = $canAccess ? null : $reason;

            $result[] = [
                'code'              => $code,
                'name'              => (string) ($item['name'] ?? $code),
                'badge'             => (string) ($item['badge'] ?? ''),
                'description'       => (string) ($item['description'] ?? ''),
                'icon'              => (string) ($item['icon'] ?? '🖥️'),
                'routeUrl'          => $routeUrl,
                'route_url'         => $routeUrl,
                'permissions'       => $requiredPerms,
                'enabled'           => $enabled,
                'hasPermission'     => $hasPermission,
                'has_permission'    => $hasPermission,
                'canEnter'          => $canAccess,
                'can_enter'         => $canAccess,
                'canAccess'         => $canAccess,
                'can_access'        => $canAccess,
                'onboardingStatus'  => $onboardingStatus,
                'onboarding_status' => $onboardingStatus,
                'disabledReason'    => $disabledReason,
                'disabled_reason'   => $disabledReason,
                'sort'              => $sort,
            ];
        }

        usort($result, static function (array $a, array $b): int {
            if ($a['sort'] !== $b['sort']) {
                return $a['sort'] <=> $b['sort'];
            }

            return strcmp($a['code'], $b['code']);
        });

        return $result;
    }

    /**
     * 判定指定用户是否具备进入商家后台的权限（供路由守卫与入口列表共用同一事实源）。
     *
     * @return array{ok: bool, has_permission: bool, code: string, message: string, disabled_reason: ?string, onboarding_required: bool}
     */
    public function checkMerchantPortalAccess(?Customer $customer): array
    {
        if (! $customer) {
            $msg = '请先登录商家账号。';

            return [
                'ok'                  => false,
                'has_permission'      => false,
                'code'                => 'UNAUTHENTICATED',
                'message'             => $msg,
                'disabled_reason'     => $msg,
                'onboarding_required' => false,
            ];
        }

        $backends = collect($this->mergedRegistry())->keyBy('code');
        $merchantMeta = $backends->get('merchant_admin');
        if ($merchantMeta && ! ($merchantMeta['enabled'] ?? true)) {
            $msg = (string) ($merchantMeta['disabled_reason'] ?? '商家后台当前维护中，暂未启用。');

            return [
                'ok'                  => false,
                'has_permission'      => false,
                'code'                => 'BACKEND_DISABLED',
                'message'             => $msg,
                'disabled_reason'     => $msg,
                'onboarding_required' => false,
            ];
        }

        $profile = MerchantProfile::query()->where('customer_id', $customer->id)->first();
        if ($profile && ! $profile->isActive()) {
            $msg = '当前商家主体已被停用，请联系平台运营处理。';

            return [
                'ok'                  => false,
                'has_permission'      => false,
                'code'                => 'MERCHANT_SUSPENDED',
                'message'             => $msg,
                'disabled_reason'     => $msg,
                'onboarding_required' => false,
            ];
        }

        $activeMemberExists = MerchantMember::query()
            ->where('customer_id', $customer->id)
            ->where('status', MerchantMember::STATUS_ACTIVE)
            ->whereIn('shop_id', function ($sub) {
                $sub->select('id')
                    ->from('merchant_shops')
                    ->where('status', 'active');
            })
            ->exists();

        if ($activeMemberExists) {
            return [
                'ok'                  => true,
                'has_permission'      => true,
                'code'                => 'OK',
                'message'             => '',
                'disabled_reason'     => null,
                'onboarding_required' => false,
            ];
        }

        if ($this->hasApprovedSupplierQualification($customer)) {
            return [
                'ok'                  => true,
                'has_permission'      => true,
                'code'                => 'ONBOARDING_ELIGIBLE',
                'message'             => '已通过供应链准入核验，请完成商家建店。',
                'disabled_reason'     => null,
                'onboarding_required' => true,
            ];
        }

        if (! config('portal_backends.strict_membership', true)) {
            return [
                'ok'                  => true,
                'has_permission'      => true,
                'code'                => 'SELF_ONBOARDING_ALLOWED',
                'message'             => '请先完成商家入驻建店。',
                'disabled_reason'     => null,
                'onboarding_required' => true,
            ];
        }

        $msg = (string) ($merchantMeta['disabled_reason'] ?? '您尚未开通商家后台权限，请先在「我的 → 供应商邀请」提交资质或使用受邀码激活。');

        return [
            'ok'                  => false,
            'has_permission'      => false,
            'code'                => 'MERCHANT_ACCESS_FORBIDDEN',
            'message'             => $msg,
            'disabled_reason'     => $msg,
            'onboarding_required' => false,
        ];
    }

    /**
     * 记录后台选择入口点击埋点。
     *
     * @return array{recorded: bool, user_id: ?int, backend_code: string, timestamp: string, source: string}
     */
    public function trackClick(
        ?Customer $customer,
        string $backendCode,
        string $source = 'mine_portal_selector',
        ?string $clientTimestamp = null,
        mixed $ipOrRequest = null,
    ): array {
        $known = collect($this->mergedRegistry())->keyBy('code');
        if (! $known->has($backendCode)) {
            return [
                'recorded'     => false,
                'user_id'      => $customer?->id,
                'backend_code' => $backendCode,
                'timestamp'    => now()->toIso8601String(),
                'source'       => $source,
            ];
        }

        $ts = $clientTimestamp && trim($clientTimestamp) !== ''
            ? trim($clientTimestamp)
            : now()->toIso8601String();

        $ip = null;
        if ($ipOrRequest instanceof Request) {
            $ip = $ipOrRequest->ip();
        } elseif (is_string($ipOrRequest)) {
            $ip = $ipOrRequest;
        }

        $payload = [
            'recorded'     => true,
            'user_id'      => $customer?->id,
            'backend_code' => $backendCode,
            'timestamp'    => $ts,
            'source'       => $source,
        ];

        $this->ops->log(
            $customer ? OperationLog::ACTOR_CUSTOMER : OperationLog::ACTOR_SYSTEM,
            $customer?->id,
            self::ACTION_PORTAL_CLICK,
            'portal_backend',
            null,
            $payload,
            $ip
        );

        return $payload;
    }

    /**
     * 合并配置文件与数据库表中的后台注册项（数据库同 code 覆盖配置，新 code 直接追加）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function mergedRegistry(): array
    {
        $configured = (array) config('portal_backends.backends', []);
        $byCode = [];

        foreach ($configured as $row) {
            if (! is_array($row) || empty($row['code'])) {
                continue;
            }
            $byCode[(string) $row['code']] = $row;
        }

        if (isset($byCode['developer_skill'])) {
            $devPrefix = trim((string) config('developer_portal.prefix', 'dev'), '/') ?: 'dev';
            $byCode['developer_skill']['route_url'] = '/'.$devPrefix;
        }

        if (isset($byCode['merchant_admin'])) {
            $merchantPrefix = trim((string) config('portal_backends.merchant_prefix', 'merchant'), '/') ?: 'merchant';
            $byCode['merchant_admin']['route_url'] = '/'.$merchantPrefix;
        }

        if (Schema::hasTable('portal_backend_registry')) {
            $dbRows = PortalBackendRegistry::query()->orderBy('sort')->orderBy('id')->get();
            foreach ($dbRows as $dbRow) {
                $existing = $byCode[$dbRow->code] ?? [];
                $byCode[$dbRow->code] = array_merge($existing, [
                    'code'            => $dbRow->code,
                    'name'            => $dbRow->name,
                    'badge'           => $dbRow->badge ?? ($existing['badge'] ?? ''),
                    'description'     => $dbRow->description ?? ($existing['description'] ?? ''),
                    'icon'            => $dbRow->icon ?: ($existing['icon'] ?? '🖥️'),
                    'route_url'       => $dbRow->route_url,
                    'permissions'     => is_array($dbRow->permissions) ? $dbRow->permissions : ($existing['permissions'] ?? []),
                    'enabled'         => (bool) $dbRow->enabled,
                    'disabled_reason' => $dbRow->disabled_reason ?? ($existing['disabled_reason'] ?? null),
                    'sort'            => (int) $dbRow->sort,
                ]);
            }
        }

        return array_values($byCode);
    }

    /**
     * 解析当前客户拥有的门户级与店铺级权限码集合。
     *
     * @return array<int, string>
     */
    public function resolveUserPermissions(?Customer $customer): array
    {
        if (! $customer) {
            return [];
        }

        $perms = [];

        $devProfile = DeveloperProfile::query()->where('customer_id', $customer->id)->first();
        if (! $devProfile || $devProfile->isActive()) {
            $perms[] = 'portal.developer.access';
        }

        $merchantCheck = $this->checkMerchantPortalAccess($customer);
        if ($merchantCheck['ok']) {
            $perms[] = 'portal.merchant.access';
        }

        if (Schema::hasTable('merchant_members')) {
            $members = MerchantMember::query()
                ->where('customer_id', $customer->id)
                ->where('status', MerchantMember::STATUS_ACTIVE)
                ->get();

            foreach ($members as $member) {
                $perms = array_merge($perms, $member->resolvedPermissions());
            }
        }

        return array_values(array_unique($perms));
    }

    /**
     * @param  array<int, string>  $requiredPerms
     * @param  array<int, string>  $userPerms
     * @return array{0: bool, 1: ?string, 2: string}
     */
    protected function evaluateAccess(
        string $code,
        bool $enabled,
        array $requiredPerms,
        array $userPerms,
        ?Customer $customer,
        ?string $configuredDisabledReason,
    ): array {
        if (! $enabled) {
            return [
                false,
                $configuredDisabledReason ?: '该后台暂未开放，敬请期待',
                'disabled',
            ];
        }

        if (! $customer) {
            return [
                false,
                '请先登录账号后再访问后台',
                'unauthenticated',
            ];
        }

        if ($code === 'merchant_admin') {
            $check = $this->checkMerchantPortalAccess($customer);
            if (! $check['ok']) {
                return [
                    false,
                    $check['message'],
                    $check['code'] === 'MERCHANT_SUSPENDED' ? 'suspended' : 'no_permission',
                ];
            }

            return [
                true,
                null,
                $check['onboarding_required'] ? 'onboarding_pending' : 'active',
            ];
        }

        if ($code === 'developer_skill') {
            $devProfile = DeveloperProfile::query()->where('customer_id', $customer->id)->first();
            if ($devProfile && ! $devProfile->isActive()) {
                return [
                    false,
                    '您的开发者账号已被停用，请联系平台运营',
                    'suspended',
                ];
            }
        }

        if ($requiredPerms !== []) {
            $hasWildcard = in_array('*', $userPerms, true);
            $missing = array_diff($requiredPerms, $userPerms);

            if (! $hasWildcard && $missing !== []) {
                return [
                    false,
                    $configuredDisabledReason ?: '当前账号暂无该后台访问权限',
                    'no_permission',
                ];
            }
        }

        if ($code === 'developer_skill') {
            $hasDevProfile = DeveloperProfile::query()->where('customer_id', $customer->id)->exists();

            return [true, null, $hasDevProfile ? 'active' : 'onboarding_available'];
        }

        return [true, null, 'active'];
    }

    protected function hasApprovedSupplierQualification(Customer $customer): bool
    {
        if (! Schema::hasTable('cooperation_applications')) {
            return false;
        }

        $hasInviteVerified = Schema::hasColumn('cooperation_applications', 'invite_verified');
        $hasIssuedCode = Schema::hasColumn('cooperation_applications', 'issued_invite_code');

        return CooperationApplication::query()
            ->where('customer_id', $customer->id)
            ->whereIn('coop_type', [
                CooperationApplication::TYPE_SUPPLIER_INVITE,
                CooperationApplication::TYPE_MANUFACTURER,
            ])
            ->where(function ($q) use ($hasInviteVerified, $hasIssuedCode) {
                $q->where('status', CooperationApplication::STATUS_APPROVED);
                if ($hasInviteVerified) {
                    $q->orWhere('invite_verified', true);
                }
                if ($hasIssuedCode) {
                    $q->orWhereNotNull('issued_invite_code');
                }
            })
            ->exists();
    }
}
