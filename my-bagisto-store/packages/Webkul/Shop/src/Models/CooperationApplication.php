<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 生态合作与供应链邀请入驻申请（本体厂商 / RaaS合伙人 / 网红达人 / 合伙人推荐 / 供应链受邀入驻）。
 */
class CooperationApplication extends Model
{
    protected $table = 'cooperation_applications';

    public const TYPE_MANUFACTURER = 'manufacturer';

    public const TYPE_RAAS = 'raas';

    public const TYPE_INFLUENCER = 'influencer';

    public const TYPE_PARTNER_REFERRAL = 'partner_referral';

    public const TYPE_SUPPLIER_INVITE = 'supplier_invite';

    public const TYPES = [
        self::TYPE_MANUFACTURER,
        self::TYPE_RAAS,
        self::TYPE_INFLUENCER,
        self::TYPE_PARTNER_REFERRAL,
        self::TYPE_SUPPLIER_INVITE,
    ];

    public const ALLOWED_TYPES = self::TYPES;

    public const TYPE_LABELS = [
        self::TYPE_MANUFACTURER     => '本体厂商',
        self::TYPE_RAAS             => 'RaaS合伙人',
        self::TYPE_INFLUENCER       => '网红达人',
        self::TYPE_PARTNER_REFERRAL => '合伙人推荐',
        self::TYPE_SUPPLIER_INVITE  => '供应链受邀入驻',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONTACTING = 'contacting';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONTACTING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    public const ALLOWED_STATUSES = self::STATUSES;

    public const STATUS_LABELS = [
        self::STATUS_PENDING    => '待审核',
        self::STATUS_CONTACTING => '商务跟进中',
        self::STATUS_APPROVED   => '已通过',
        self::STATUS_REJECTED   => '未通过',
    ];

    public const META_LABELS = [
        'robot_models'             => '主营 / 适配机器人型号',
        'entity_type'              => '主体类型',
        'portfolio_url'            => '代表作 / 演示链接',
        'invite_code'              => '提交邀请码',
        'invite_verified'          => '邀请码核验状态',
        'invite_note'              => '邀请码来源备注',
        'platform'                 => '主营内容平台',
        'followers_tier'           => '粉丝量级',
        'account_url'              => '主页链接 / 账号ID',
        'level'                    => '意向签约级别',
        'recommender_type'         => '推荐人身份',
        'license_file_name'        => '营业执照附件',
        'entry_mode'               => '入驻通道模式',
        'supply_category'          => '供应链类别',
        'supply_items'             => '核心供货清单 / 服务内容',
        'capacity_scale'           => '供货产能 / 响应规模',
        'warranty_commitment'      => '质保与售后承诺',
        'credit_code'              => '统一社会信用代码',
        'follower_name'            => '在跟进人员',
        'follower_contact'         => '跟进人联系方式',
        'issued_invite_code'       => '平台签发邀请码',
        'issued_invite_expires_at' => '签发邀请码有效期',
    ];

    protected $fillable = [
        'application_no',
        'customer_id',
        'guest_key',
        'coop_type',
        'org_name',
        'contact_name',
        'contact_phone',
        'contact_wechat',
        'region',
        'coop_direction',
        'summary',
        'meta',
        'status',
        'followup_person',
        'admin_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'meta'        => 'array',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public static function typeLabel(?string $type): string
    {
        if ($type === null || $type === '') {
            return '未知类型';
        }

        return self::TYPE_LABELS[$type] ?? $type;
    }

    public static function statusLabel(?string $status): string
    {
        if ($status === null || $status === '') {
            return '未知状态';
        }

        return self::STATUS_LABELS[$status] ?? $status;
    }

    /**
     * 将 meta 字典格式化为适合后台展示的中文键值列表。
     *
     * @return array<int, array{key: string, label: string, value: string}>
     */
    public static function formatMetaForDisplay(?array $meta): array
    {
        if (empty($meta)) {
            return [];
        }

        $rows = [];
        foreach ($meta as $k => $v) {
            if ($v === null || $v === '' || $k === 'issued_invite_id') {
                continue;
            }

            $key = (string) $k;
            $label = self::META_LABELS[$key] ?? $key;
            $displayValue = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);

            if ($key === 'entity_type') {
                $displayValue = $v === 'individual' ? '个人开发者' : '企业 / 工作室团队';
            } elseif ($key === 'recommender_type') {
                $displayValue = $v === 'fulfill' ? '履约合伙人' : '销售合伙人';
            } elseif ($key === 'entry_mode') {
                $displayValue = $v === 'apply_code' ? '无码申请供应链邀约' : '持邀请码极速入驻';
            } elseif ($key === 'invite_verified') {
                $displayValue = filter_var($v, FILTER_VALIDATE_BOOLEAN) ? '已核验有效' : '未核验 / 待人工确认';
            }

            $rows[] = [
                'key'   => $key,
                'label' => $label,
                'value' => (string) $displayValue,
            ];
        }

        return $rows;
    }

    public function toApiArray(): array
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $followerName = $this->followup_person ?: ($meta['follower_name'] ?? null);

        return [
            'id'                       => $this->id,
            'application_no'           => $this->application_no,
            'customer_id'              => $this->customer_id,
            'coop_type'                => $this->coop_type,
            'type_label'               => self::typeLabel($this->coop_type),
            'org_name'                 => $this->org_name,
            'contact_name'             => $this->contact_name,
            'contact_phone'            => $this->contact_phone,
            'contact_wechat'           => $this->contact_wechat,
            'region'                   => $this->region,
            'coop_direction'           => $this->coop_direction,
            'summary'                  => $this->summary,
            'meta'                     => $meta,
            'invite_code'              => $meta['invite_code'] ?? null,
            'invite_verified'          => (bool) ($meta['invite_verified'] ?? false),
            'invite_note'              => $meta['invite_note'] ?? null,
            'follower_name'            => $followerName,
            'followup_person'          => $followerName,
            'follower_contact'         => $meta['follower_contact'] ?? null,
            'issued_invite_code'       => $meta['issued_invite_code'] ?? null,
            'issued_invite_expires_at' => $meta['issued_invite_expires_at'] ?? null,
            'status'                   => $this->status,
            'status_label'             => self::statusLabel($this->status),
            'followup_person'          => $this->followup_person,
            'admin_note'               => $this->admin_note,
            'reviewed_at'              => $this->reviewed_at?->format('Y-m-d H:i'),
            'created_at'               => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}
