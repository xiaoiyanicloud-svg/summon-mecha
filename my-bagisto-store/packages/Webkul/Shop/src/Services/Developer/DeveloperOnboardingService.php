<?php

namespace Webkul\Shop\Services\Developer;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeveloperAgreement;
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
 * ⚠ sellers.code 由平台生成，**绝不接受前端传入** —— 那会变成"抢注他人商户码"。
 */
class DeveloperOnboardingService
{
    /**
     * 建开发者主体（幂等：重复提交返回既有 profile，不重复建 seller）。
     */
    public function register(Customer $customer, array $data): DeveloperProfile
    {
        $existing = DeveloperProfile::query()->where('customer_id', $customer->id)->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($customer, $data) {
            $seller = Seller::create([
                'code'             => $this->generateSellerCode(),
                'name'             => $data['seller_name'] ?? $customer->name ?? ('开发者 '.$customer->id),
                'status'           => 'active',
                'owner_customer_id' => $customer->id,
            ]);

            $profile = DeveloperProfile::create([
                'customer_id'     => $customer->id,
                'seller_id'       => $seller->id,
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

            return $profile;
        });
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
