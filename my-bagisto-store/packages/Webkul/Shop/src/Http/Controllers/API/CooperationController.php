<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Webkul\Shop\Models\CooperationApplication;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Services\Developer\DeveloperInviteService;
use Webkul\Shop\Services\NotificationService;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * 生态合作申请 API —— 承接 C 端「我的 -> 合作申请」「合伙人推荐」「供应商邀请」：
 *   1. manufacturer     本体厂商（机器人本体厂商生态联合与渠道合作）
 *   2. raas             RaaS 合伙人（技能开发者 / 解决方案商入驻与分成合作）
 *   3. influencer       网红达人（机甲评测 / 短视频共创 / 赛事直播达人合作）
 *   4. partner_referral 合伙人推荐（推荐企业成为生态合伙人）
 *   5. supplier_invite  供应链受邀入驻（供应链伙伴凭邀请码或定向申请入驻）
 */
class CooperationController extends APIController
{
    public function __construct(
        protected OperationLogger $oplog,
        protected NotificationService $notifier,
        protected DeveloperInviteService $invites,
    ) {}

    /**
     * GET /api/cooperations
     * 查询当前登录客户（或当前游客 guest_key）提交过的合作申请记录。
     */
    public function index(Request $request): JsonResponse
    {
        $customer = auth()->guard('customer')->user();
        $guestKey = trim((string) ($request->header('X-Guest-Key') ?: $request->query('guest_key', '')));

        if (! $customer && strlen($guestKey) < 6) {
            return response()->json(['data' => []]);
        }

        $query = CooperationApplication::query()
            ->where(function ($q) use ($customer, $guestKey) {
                if ($customer) {
                    $q->where('customer_id', $customer->id);
                    if (strlen($guestKey) >= 6) {
                        $q->orWhere('guest_key', $guestKey);
                    }
                } else {
                    $q->where('guest_key', $guestKey);
                }
            })
            ->orderByDesc('id')
            ->limit(50);

        $items = $query->get()->map(fn (CooperationApplication $app) => $app->toApiArray())->values();

        return response()->json(['data' => $items]);
    }

    /**
     * POST /api/cooperations/verify-invite
     * 核验供应链 / 生态伙伴邀请码有效性（只读核验，不扣减配额；实际扣减在开发者门户入驻或供应链档案绑定时发生）。
     */
    public function verifyInvite(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:40',
        ], [
            'code.required' => '请输入邀请码。',
        ]);

        if ($validator->fails()) {
            return $this->fail(
                ApiErrorCode::VALIDATION_ERROR,
                $validator->errors()->first(),
                422,
                ['errors' => $validator->errors()]
            );
        }

        $code = strtoupper(trim((string) $request->input('code')));
        $redeemable = $this->invites->findRedeemable($code);

        if ($redeemable) {
            return response()->json([
                'message' => '邀请码有效，可用于供应链入驻登记或前往开发者门户激活。',
                'data'    => [
                    'redeemable'     => true,
                    'code'           => $redeemable->code,
                    'status'         => $redeemable->status,
                    'max_uses'       => (int) $redeemable->max_uses,
                    'times_used'     => (int) $redeemable->times_used,
                    'remaining_uses' => max(0, (int) $redeemable->max_uses - (int) $redeemable->times_used),
                    'expires_at'     => $redeemable->expires_at?->toIso8601String(),
                    'note'           => $redeemable->note ?: '平台官方生态/供应链邀约',
                    'reason'         => null,
                    'dev_join_url'   => url('/dev/join?code=' . urlencode($redeemable->code)),
                ],
            ]);
        }

        $reason = $this->invites->explainUnredeemable($code);

        return response()->json([
            'message' => $reason,
            'data'    => [
                'redeemable'     => false,
                'code'           => $code,
                'status'         => 'unredeemable',
                'max_uses'       => 0,
                'times_used'     => 0,
                'remaining_uses' => 0,
                'expires_at'     => null,
                'note'           => null,
                'reason'         => $reason,
                'dev_join_url'   => null,
            ],
        ]);
    }

    /**
     * POST /api/cooperations
     * 提交合作申请（本体厂商 / RaaS合伙人 / 网红达人 / 合伙人推荐 / 供应链受邀入驻）。
     */
    public function store(Request $request): JsonResponse
    {
        $rules = [
            'coop_type'      => 'required|string|in:' . implode(',', CooperationApplication::ALLOWED_TYPES),
            'org_name'       => 'required|string|min:2|max:120',
            'contact_name'   => 'required|string|min:2|max:60',
            'contact_phone'  => ['required', 'string', 'regex:/^1\d{10}$/'],
            'contact_wechat' => 'nullable|string|max:80',
            'region'         => 'nullable|string|max:80',
            'coop_direction' => 'nullable|string|max:120',
            'summary'        => 'nullable|string|max:1000',
            'guest_key'      => 'nullable|string|max:64',
            'meta'           => 'nullable|array',
        ];

        $coopType = (string) $request->input('coop_type', '');
        $entryMode = (string) $request->input('meta.entry_mode', 'with_code');

        if ($coopType === CooperationApplication::TYPE_MANUFACTURER) {
            $rules['coop_direction']     = 'required|string|max:120';
            $rules['meta.robot_models']  = 'required|string|max:200';
        } elseif ($coopType === CooperationApplication::TYPE_RAAS) {
            $rules['coop_direction']     = 'required|string|max:120';
            $rules['meta.entity_type']   = 'required|string|in:individual,company';
            $rules['meta.robot_models']  = 'required|string|max:200';
            $rules['meta.portfolio_url'] = 'nullable|string|max:255';
            $rules['meta.invite_code']   = 'nullable|string|max:40';
        } elseif ($coopType === CooperationApplication::TYPE_INFLUENCER) {
            $rules['coop_direction']      = 'required|string|max:120';
            $rules['meta.platform']       = 'required|string|max:60';
            $rules['meta.followers_tier'] = 'required|string|max:60';
            $rules['meta.account_url']    = 'nullable|string|max:255';
        } elseif ($coopType === CooperationApplication::TYPE_SUPPLIER_INVITE) {
            $rules['coop_direction']            = 'required|string|max:120';
            $rules['meta.entry_mode']           = 'nullable|string|in:with_code,apply_code';
            $rules['meta.supply_items']         = 'required|string|max:200';
            $rules['meta.capacity_scale']       = 'required|string|max:120';
            $rules['meta.warranty_commitment']  = 'required|string|max:120';
            $rules['meta.credit_code']          = 'nullable|string|max:64';
            $rules['meta.invite_code']          = $entryMode === 'with_code'
                ? 'required|string|max:40'
                : 'nullable|string|max:40';
        }

        $messages = [
            'coop_type.required'                   => '请选择合作类型。',
            'coop_type.in'                         => '不支持的合作类型。',
            'org_name.required'                    => '请填写主体/企业/达人名称。',
            'org_name.min'                         => '主体名称至少 2 个字符。',
            'contact_name.required'                => '请填写联系人姓名。',
            'contact_phone.required'               => '请填写联系电话。',
            'contact_phone.regex'                  => '请输入正确的 11 位手机号码。',
            'coop_direction.required'              => '请选择合作方向或供应品类。',
            'meta.robot_models.required'           => '请填写主营或适配的机器人型号。',
            'meta.entity_type.required'            => '请选择主体类型（个人开发者 / 企业团队）。',
            'meta.platform.required'               => '请选择主营内容平台。',
            'meta.followers_tier.required'         => '请选择粉丝量级。',
            'meta.supply_items.required'           => '请填写主营供应产品、型号或服务范围。',
            'meta.capacity_scale.required'         => '请选择供货能力或服务覆盖量级。',
            'meta.warranty_commitment.required'    => '请选择履约与质保响应承诺。',
            'meta.invite_code.required'            => '持码入驻模式下请填写有效的供应链邀请码，或切换为「无码申请供应链邀约」。',
        ];

        $validator = Validator::make($request->all(), $rules, $messages);
        if ($validator->fails()) {
            return $this->fail(
                ApiErrorCode::VALIDATION_ERROR,
                $validator->errors()->first(),
                422,
                ['errors' => $validator->errors()]
            );
        }

        $meta = is_array($request->input('meta')) ? $request->input('meta') : [];
        // 清理空值并做长度截断保护
        $cleanMeta = [];
        foreach ($meta as $k => $v) {
            if (is_scalar($v) && trim((string) $v) !== '') {
                $cleanMeta[(string) $k] = mb_substr(trim((string) $v), 0, 300);
            }
        }

        // 若填写了邀请码（供应链受邀入驻 / RaaS 合伙人 / 合伙人推荐），执行真实邀请码核验与留痕
        $rawInviteCode = isset($cleanMeta['invite_code']) ? strtoupper(trim((string) $cleanMeta['invite_code'])) : '';
        if ($rawInviteCode !== '') {
            $cleanMeta['invite_code'] = $rawInviteCode;
            $redeemable = $this->invites->findRedeemable($rawInviteCode);

            if (! $redeemable && $coopType === CooperationApplication::TYPE_SUPPLIER_INVITE && $entryMode === 'with_code') {
                $reason = $this->invites->explainUnredeemable($rawInviteCode);
                return $this->fail(
                    ApiErrorCode::VALIDATION_ERROR,
                    $reason,
                    422,
                    ['errors' => ['meta.invite_code' => [$reason]]]
                );
            }

            $cleanMeta['invite_verified'] = (bool) $redeemable;
            if ($redeemable && $redeemable->note) {
                $cleanMeta['invite_note'] = mb_substr((string) $redeemable->note, 0, 120);
            }
        } elseif ($coopType === CooperationApplication::TYPE_SUPPLIER_INVITE) {
            $cleanMeta['entry_mode']      = 'apply_code';
            $cleanMeta['invite_verified'] = false;
        }

        $customer = auth()->guard('customer')->user();
        $guestKey = trim((string) ($request->header('X-Guest-Key') ?: $request->input('guest_key', '')));
        $orgName  = trim((string) $request->input('org_name'));
        $phone    = trim((string) $request->input('contact_phone'));

        // 防重：同一类型 + 同一手机号 + 同一主体名称，存在待审核或跟进中的申请时拒绝重复刷单
        $existing = CooperationApplication::query()
            ->where('coop_type', $coopType)
            ->where('contact_phone', $phone)
            ->where('org_name', $orgName)
            ->whereIn('status', [
                CooperationApplication::STATUS_PENDING,
                CooperationApplication::STATUS_CONTACTING,
            ])
            ->latest('id')
            ->first();

        if ($existing) {
            return $this->fail(
                ApiErrorCode::CONFLICT,
                '您已提交过该主体的同类型合作申请，商务顾问正在跟进中，请勿重复提交。',
                409,
                ['existing' => $existing->toApiArray()]
            );
        }

        $applicationNo = 'CA' . now()->format('ymdHis') . strtoupper(Str::random(4));

        $application = CooperationApplication::create([
            'application_no' => $applicationNo,
            'customer_id'    => $customer?->id,
            'guest_key'      => $guestKey !== '' ? mb_substr($guestKey, 0, 64) : null,
            'coop_type'      => $coopType,
            'org_name'       => $orgName,
            'contact_name'   => trim((string) $request->input('contact_name')),
            'contact_phone'  => $phone,
            'contact_wechat' => $request->filled('contact_wechat') ? trim((string) $request->input('contact_wechat')) : null,
            'region'         => $request->filled('region') ? trim((string) $request->input('region')) : null,
            'coop_direction' => $request->filled('coop_direction') ? trim((string) $request->input('coop_direction')) : null,
            'summary'        => $request->filled('summary') ? trim((string) $request->input('summary')) : null,
            'meta'           => $cleanMeta,
            'status'         => CooperationApplication::STATUS_PENDING,
        ]);

        try {
            $this->oplog->log(
                $customer ? OperationLog::ACTOR_CUSTOMER : 'guest',
                $customer?->id,
                'cooperation.submitted',
                'cooperation_application',
                $application->id,
                [
                    'application_no' => $application->application_no,
                    'coop_type'      => $application->coop_type,
                    'org_name'       => $application->org_name,
                ],
                $request->ip()
            );
        } catch (\Throwable) {
            // 审计日志失败不阻断主流程
        }

        if ($customer) {
            try {
                $typeLabel = CooperationApplication::typeLabel($coopType);
                $this->notifier->toCustomer(
                    (int) $customer->id,
                    'cooperation.submitted',
                    "「{$typeLabel}」合作申请已提交",
                    "申请单号 {$application->application_no}（{$application->org_name}）已进入商务审核队列，我们将在 1-3 个工作日内与您联系。",
                    [
                        'application_id' => $application->id,
                        'application_no' => $application->application_no,
                        'coop_type'      => $coopType,
                    ]
                );
            } catch (\Throwable) {
                // 通知失败不回滚主业务
            }
        }

        return response()->json([
            'message' => '合作申请已提交，专属商务顾问将在 1-3 个工作日内与您联系。',
            'data'    => $application->toApiArray(),
        ], 201);
    }
}
