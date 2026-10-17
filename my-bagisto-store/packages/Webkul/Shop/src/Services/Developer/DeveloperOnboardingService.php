<?php

namespace Webkul\Shop\Services\Developer;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeveloperAgreement;
use Webkul\Shop\Models\DeveloperInvite;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\DeveloperTeamMember;
use Webkul\Shop\Models\Seller;

/**
 * 开发者入驻 —— v3.4 步骤 9。
 *
 * 一次入驻要落三样东西，且必须**同事务**（任一失败不留半截数据）：
 *   1. sellers 行      —— 归属主体（products/skill_versions 的 seller_id 指向它）
 *   2. developer_profile —— 开发者主体（customer_id 唯一）
 *   3. developer_team_member(role=owner) —— 谁是主账号（完整 RBAC 留位）
 *
 * S-11d：邀请码衔接。邀请码**不是准入闸门**，只做「归属 + 来源留痕」。
 *   领取与建 profile **同一个事务** —— 码没抢到就降级为自助入驻（invite_id=null），
 *   **绝不因为码失效而阻断入驻**。
 *
 * ⚠ sellers.code 由平台生成，**绝不接受前端传入** —— 那会变成"抢注他人商户码"。
 */
class DeveloperOnboardingService
{
    public function __construct(protected DeveloperInviteService $invites) {}

    /**
     * 建开发者主体（幂等：重复提交返回既有 profile，不重复建 seller）。
     *
     * 保留原签名供既有调用点（API `POST /api/dev/profile`）使用 ——
     * 等价于 `registerWithInvite(..., null)`，**不接受邀请码**。
     */
    public function register(Customer $customer, array $data): DeveloperProfile
    {
        return $this->registerWithInvite($customer, $data, null)['profile'];
    }

    /**
     * 建开发者主体 + 尝试领取邀请码（S-11d）。
     *
     * 顺序（同一事务内）：findRedeemable → claim → 建 seller/profile（带 invite_id）→ team member。
     * claim() 自己负责写 `developer_invite_usage` 流水；失败时**不抛异常**，照常入驻：
     *   - 码不存在/作废/过期/用完 → 对应 state + 中性 message，profile.invite_id = null；
     *   - 同一 customer 已领过 → state = already_claimed，profile.invite_id = null。
     *
     * @return array{profile: DeveloperProfile, invite: array{state: string, message: string}}
     */
    public function registerWithInvite(Customer $customer, array $data, ?string $inviteCode): array
    {
        $inviteCode = $inviteCode !== null ? trim($inviteCode) : null;

        if ($inviteCode === '') {
            $inviteCode = null;
        }

        $existing = DeveloperProfile::query()->where('customer_id', $customer->id)->first();

        if ($existing) {
            return [
                'profile' => $existing,
                'invite'  => $this->stateForExisting($customer->id, $inviteCode),
            ];
        }

        return DB::transaction(function () use ($customer, $data, $inviteCode) {
            $invite = null;
            $inviteState = ['state' => 'not_provided', 'message' => ''];

            if ($inviteCode !== null) {
                $invite = $this->invites->findRedeemable($inviteCode);

                if ($invite) {
                    $claimed = $this->invites->claim(
                        $invite,
                        $customer->id,
                        request()->ip(),
                        request()->userAgent()
                    );

                    if ($claimed) {
                        $inviteState = ['state' => 'claimed', 'message' => '已记录邀请来源。'];
                    } elseif ($this->invites->alreadyClaimedBy($invite, $customer->id)) {
                        // 同一人多用途码重复领 —— 不重复记来源、不报错，照常入驻
                        $inviteState = ['state' => 'already_claimed', 'message' => '你已领取过该邀请码，本次未重复记录。'];
                        $invite = null;
                    } else {
                        // findRedeemable 命中但 claim 没抢到（并发 / 竞态）—— 降级
                        $inviteState = $this->explainCode($inviteCode);
                        $invite = null;
                    }
                } else {
                    $inviteState = $this->explainCode($inviteCode);
                }
            }

            $seller = Seller::create([
                'code'             => $this->generateSellerCode(),
                'name'             => $data['seller_name'] ?? $customer->name ?? ('开发者 '.$customer->id),
                'status'           => 'active',
                'owner_customer_id' => $customer->id,
            ]);

            $profile = DeveloperProfile::create([
                'customer_id'     => $customer->id,
                'seller_id'       => $seller->id,
                'invite_id'       => $invite?->id,
                'dev_type'        => $data['dev_type'],
                // ⚠ 入驻时一律停在 pending —— 实名审核是**平台端的人工判定**，
                //   由运营在 Admin「开发者入驻审核台」做（已上线）。
                //   自助入驻流程里**不伪造 approved**：能自己把自己标成已核验的核验不算核验。
                'realname_status' => DeveloperProfile::REALNAME_PENDING,
                'cert_payload'    => $data['cert_payload'] ?? null,
                'status'          => DeveloperProfile::STATUS_ACTIVE,
            ]);

            DeveloperTeamMember::create([
                'developer_id' => $profile->id,
                'customer_id'  => $customer->id,
                'role'         => DeveloperTeamMember::ROLE_OWNER,
                'status'       => 'active',
            ]);

            return ['profile' => $profile, 'invite' => $inviteState];
        });
    }

    /**
     * 已入驻的重复提交：**不再领取**（幂等），只如实说明来源是否已记录。
     *
     * @return array{state: string, message: string}
     */
    protected function stateForExisting(int $customerId, ?string $inviteCode): array
    {
        if ($inviteCode === null) {
            return ['state' => 'not_provided', 'message' => ''];
        }

        $invite = DeveloperInvite::query()->where('code', $inviteCode)->first();

        if ($invite && $this->invites->alreadyClaimedBy($invite, $customerId)) {
            return ['state' => 'already_claimed', 'message' => '你已领取过该邀请码，本次未重复记录。'];
        }

        return ['state' => 'already_registered', 'message' => '该账号已完成入驻，邀请码未重复记录。'];
    }

    /**
     * 把"领不到"翻译成机器可判的 state + 中性人话（文案唯一来源是 explainUnredeemable）。
     *
     * @return array{state: string, message: string}
     */
    protected function explainCode(string $code): array
    {
        $invite = DeveloperInvite::query()->where('code', $code)->first();
        $message = $this->invites->explainUnredeemable($code);

        if (! $invite) {
            return ['state' => 'not_found', 'message' => $message];
        }

        if ($invite->status !== DeveloperInvite::STATUS_ACTIVE) {
            return ['state' => 'revoked', 'message' => $message];
        }

        if ($invite->isExpired()) {
            return ['state' => 'expired', 'message' => $message];
        }

        if ($invite->isExhausted()) {
            return ['state' => 'exhausted', 'message' => $message];
        }

        // 命中过可领、但 claim 没抢到（并发窗口）—— 归入"已被领用"，不阻断入驻
        return ['state' => 'exhausted', 'message' => '该邀请码已被其他开发者领用，已按自助通道完成入驻。'];
    }

    /**
     * 签署协议（幂等：同一 doc_type 重复签则覆盖并记新版本）。
     *
     * @param  array<string,bool>  $accepted  doc_type => 是否勾选
     * @return array{ok: bool, message: string, signed?: array}
     */
    public function signAgreements(DeveloperProfile $profile, array $accepted, Request $request): array
    {
        $docs = (array) config('developer_portal.agreements', []);

        if (empty($docs)) {
            return ['ok' => true, 'message' => '无需签署。', 'signed' => []];
        }

        // 必须全部勾选 —— 少一个都不能算签完
        $missing = [];

        foreach ($docs as $docType => $doc) {
            if (empty($accepted[$docType])) {
                $missing[] = $doc['title'] ?? $docType;
            }
        }

        if ($missing) {
            return ['ok' => false, 'message' => '请先勾选并同意：'.implode('、', $missing).'。'];
        }

        $signed = [];

        DB::transaction(function () use ($profile, $docs, $request, &$signed) {
            foreach ($docs as $docType => $doc) {
                $now = now();

                $agreement = DeveloperAgreement::updateOrCreate(
                    ['developer_id' => $profile->id, 'doc_type' => $docType],
                    [
                        'doc_version'  => $doc['version'] ?? '1.0',
                        'signed_at'    => $now,
                        'sign_payload' => [
                            'checked'             => true,
                            'doc_type'            => $docType,
                            'doc_version'         => $doc['version'] ?? '1.0',
                            // 关键：留的是"当时那一版正文"的指纹，将来改协议可证签的是哪版
                            'agreement_text_hash' => hash('sha256', (string) ($doc['text'] ?? '')),
                            'ip'                  => $request->ip(),
                            'user_agent'          => (string) $request->userAgent(),
                            'signed_at'           => $now->toDateTimeString(),
                        ],
                    ]
                );

                $signed[] = $agreement->doc_type;
            }

            $profile->agreement_signed_at = now();
            $profile->save();
        });

        return ['ok' => true, 'message' => '签署完成。', 'signed' => $signed];
    }

    /**
     * 商户码：平台生成，全局唯一。
     */
    protected function generateSellerCode(): string
    {
        do {
            $code = 'DEV-'.strtoupper(Str::random(8));
        } while (Seller::query()->where('code', $code)->exists());

        return $code;
    }
}
