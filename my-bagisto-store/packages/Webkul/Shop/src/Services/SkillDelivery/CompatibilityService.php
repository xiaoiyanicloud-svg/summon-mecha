<?php

namespace Webkul\Shop\Services\SkillDelivery;

use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\DeviceModel;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillCompatibilityEvidence;
use Webkul\Shop\Models\SkillVersion;

/**
 * 兼容性校验(v3.4 步骤 5 · A3 证据链读侧)。
 *
 * 输入:用户设备 + 技能商品 → 输出
 *   {compatible, reason, channels[], notes[], model,
 *    adaptation_status, adaptation_label, checks[], evidence_gaps[], evidence_count}
 *
 * ── 两个结论,不许混为一谈 ──
 *   compatible        交付通道层面"装得上":技能声明通道 ∩ 机型可用通道(∩ 设备在线状态仅提示)。
 *                     它只说明**这条路能走**,不说明这份产物在该机型上被验证过。
 *   adaptation_status 适配证据层面"验证到哪一步":
 *                     verified_match        真机上报 + 安装验证 + 匹配,且证据钉住的产物/机型
 *                                           与**当前要交付的那一份**一致;
 *                     needs_confirmation    只有仿真/静态/人工类证据(或真机的非安装类验证);
 *                     mismatch              存在明确冲突(机型不在声明范围、固件低于下限、
 *                                           当前产物有"不匹配"结论);
 *                     unverified            缺声明/缺字段/缺证据/缺产物,或多产物无法唯一确定。
 *
 * ⚠「未知」降级(关键,别改回硬判):
 *   - 设备没填/填错机型 → 不判"机型不匹配",降级为"仅人工通道"+ 提示;
 *   - 固件版本未知(多数厂商不公开)→ 不判"固件过低",只提示"请确认固件版本";
 *   - 产物无法唯一确定 → 不猜、不取排序第一个,如实记 unverified。
 *
 * ⚠ 证据失效:证据钉住 `asset_id + asset_hash`。产物换了字节而证据还是旧的,
 *   旧证据**不得**构成对当前产物的验证 —— 这正是"适配证据链"的全部意义。
 */
class CompatibilityService
{
    public const STATUS_VERIFIED = 'verified_match';
    public const STATUS_NEEDS_CONFIRMATION = 'needs_confirmation';
    public const STATUS_MISMATCH = 'mismatch';
    public const STATUS_UNVERIFIED = 'unverified';

    public const STATUS_LABELS = [
        self::STATUS_VERIFIED           => '本机型已通过安装验证',
        self::STATUS_NEEDS_CONFIRMATION => '有参考资料,尚需本机确认',
        self::STATUS_MISMATCH           => '与已声明/已记录条件冲突',
        self::STATUS_UNVERIFIED         => '尚未验证',
    ];

    public function __construct(
        protected SkillDeliveryAssetResolver $assets,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function check(int $customerId, int $productId, int $deviceId): array
    {
        $device = Device::query()
            ->where('customer_id', $customerId)
            ->where('id', $deviceId)
            ->first();

        if (! $device) {
            return $this->unverified('设备不存在或不属于你。');
        }

        // v3.4 步骤 2：设备归属以 binding 为准（**只读** —— GET 不该写库，故不 heal）
        $resolved = app(DeviceBindingService::class)->resolveForCustomer($device, $customerId);

        if (! $resolved['ok']) {
            return $this->unverified($resolved['reason'] ?: '设备不存在或不属于你。');
        }

        $binding = $resolved['binding'];

        $version = SkillVersion::query()
            ->where('product_id', $productId)
            ->where('status', SkillVersion::STATUS_PUBLISHED)
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->first();

        if (! $version) {
            return $this->unverified('该技能暂未发布可交付版本。');
        }

        $snapshot = is_array($binding?->meta) && is_array($binding->meta['capability_snapshot'] ?? null)
            ? $binding->meta['capability_snapshot']
            : [];

        // 机型 code 的唯一解析顺序:binding.model_id 指向的机型库 → 设备登记字段 → Agent 上报快照。
        //   三者都没有 = 未知机型(降级为仅人工),不是"机型不匹配"。
        $modelCode = $binding?->model?->model_code;
        if (! is_string($modelCode) || $modelCode === '') {
            $modelCode = is_string($device->model_code) && $device->model_code !== ''
                ? $device->model_code
                : (is_string($snapshot['model_code'] ?? null) && $snapshot['model_code'] !== ''
                    ? $snapshot['model_code']
                    : null);
        }

        $firmware = is_string($device->firmware_version) && $device->firmware_version !== ''
            ? $device->firmware_version
            : (is_string($snapshot['firmware_version'] ?? null) && $snapshot['firmware_version'] !== ''
                ? $snapshot['firmware_version']
                : null);

        $notes = [];

        // 未确权（只有自报登记）→ 如实提示：自动下发这一步走不通，别让用户以为能一键装
        if (! $binding) {
            $notes[] = '该设备尚未确权（仅自报登记），自动下发通道不可用，请先完成设备确权。';
        }

        // 技能声明的交付通道(v3.4 新列);缺省视为仅人工
        $declaredChannels = is_array($version->delivery_channels) && $version->delivery_channels
            ? $version->delivery_channels
            : [SkillAsset::DELIVERY_MANUAL];

        // 机型可用通道
        $model = null;
        if ($modelCode !== null) {
            $model = DeviceModel::query()->where('model_code', $modelCode)->first();
        }

        if (! $model) {
            // 「未知机型」降级:不判不兼容,只允许人工通道
            $notes[] = '未识别机型(设备未关联机型库),仅提供人工安装通道。';
            $deviceChannels = [SkillAsset::DELIVERY_MANUAL];
        } else {
            $deviceChannels = is_array($model->comm_channels) ? $model->comm_channels : [SkillAsset::DELIVERY_MANUAL];

            if ($model->status === 'display_only') {
                $notes[] = '该机型暂为仅展示(尚无自动下发通道),仅提供人工安装。';
            }

            // 固件"未知"降级:不判固件过低
            if (! $model->firmware_versions) {
                $notes[] = '该机型固件版本信息未知,请在安装前自行确认固件版本。';
            }
        }

        // 交集
        $channels = array_values(array_intersect($declaredChannels, $deviceChannels));

        // 设备离线仅提示(不影响 manual;影响 agent)
        //
        // ⚠ 这里此前调的是 `$this->isOnline($device)`，而那个方法**硬编码 `return false`**
        //   （注释给的理由是"binding 未接在线心跳"）。心跳早就接上了 —— 于是这条提示
        //   变成一句**恒定输出**的假话：设备明明在线，界面也告诉用户"设备当前离线"。
        //   现在与其余几处读点用同一个判据（`DeviceBinding::isOnlineNow()`）。
        //
        //   ⚠ 离线仍然只是"提示"，不是"拒绝"：该做的是挂起下发（`pending_online`），
        //     而不是让用户装不了 —— 所以这里不改成 fail。
        if (in_array(SkillAsset::DELIVERY_AGENT, $channels, true) && ! $binding?->isOnlineNow()) {
            $notes[] = '设备当前离线,自动下发将在设备上线后进行。';
        }

        $adaptation = $this->evaluateAdaptation($version, $modelCode, $firmware, $binding, $snapshot);

        $notes = array_values(array_unique(array_merge($notes, $adaptation['notes'])));

        $compatible = $channels !== [];

        return [
            // ── 交付通道:装不装得上 ──
            'compatible' => $compatible,
            'reason'     => $compatible
                ? '兼容。'
                : '无可用通道(该技能声明的交付方式与你的机型不匹配)。',
            'channels'   => $channels,
            'notes'      => $notes,
            'model'      => $modelCode,
            // ── 适配证据:验证到哪一步(与上面那个结论正交) ──
            'adaptation_status' => $adaptation['status'],
            'adaptation_label'  => self::STATUS_LABELS[$adaptation['status']],
            'checks'            => $adaptation['checks'],
            'evidence_gaps'     => $adaptation['gaps'],
            'evidence_count'    => $adaptation['evidence_count'],
        ];
    }

    /**
     * 适配结论的唯一计算点。
     *
     * @param  array<string, mixed>  $snapshot
     * @return array{status: string, checks: array, gaps: array, notes: array, evidence_count: int}
     */
    protected function evaluateAdaptation(
        SkillVersion $version,
        ?string $modelCode,
        ?string $firmware,
        ?DeviceBinding $binding,
        array $snapshot,
    ): array {
        $checks = [];
        $gaps = [];
        $notes = [];

        $status = self::STATUS_UNVERIFIED;

        // ── 1) 清单声明 ──
        $declaredModels = $this->declaredModels($version->compatibility);

        if ($declaredModels === []) {
            $checks[] = $this->entry('declaration', '适配声明', 'unknown', '清单未声明适用机型。');
            $gaps[] = '清单未声明适用机型,只能按交付通道判断"装得上",不能判断"适配"。';
        } else {
            $checks[] = $this->entry(
                'declaration',
                '适配声明',
                'pass',
                '清单已声明 '.count($declaredModels).' 个机型(声明 ≠ 验证)。',
            );
        }

        // ── 2) 当前机型的声明条目 ──
        $declaredEntry = null;

        if ($modelCode === null || $modelCode === '') {
            $checks[] = $this->entry('model', '机型识别', 'unknown', '设备未识别机型。');
            $gaps[] = '设备机型未知,无法与任何机型证据比对。';
        } else {
            foreach ($declaredModels as $entry) {
                if (is_array($entry) && ($entry['model'] ?? null) === $modelCode) {
                    $declaredEntry = $entry;

                    break;
                }
            }

            if ($declaredModels === []) {
                $checks[] = $this->entry('model', '机型识别', 'unknown', "机型 {$modelCode}(清单未声明范围,不做匹配判定)。");
            } elseif ($declaredEntry === null) {
                $checks[] = $this->entry('model', '机型识别', 'fail', "清单声明的机型不含 {$modelCode}。");
                $gaps[] = "清单声明的机型不含 {$modelCode}:这是明确冲突,不是\"尚未验证\"。";
                $status = self::STATUS_MISMATCH;
            } else {
                $checks[] = $this->entry('model', '机型识别', 'pass', "机型 {$modelCode} 在清单声明范围内。");
            }
        }

        // ── 3) 固件下限(未知只提示,不判过低) ──
        $minFirmware = is_array($declaredEntry) ? ($declaredEntry['min_firmware'] ?? null) : null;

        if (is_string($minFirmware) && $minFirmware !== '') {
            if ($firmware === null) {
                $checks[] = $this->entry('firmware', '固件版本', 'unknown', "清单要求 ≥ {$minFirmware},本机未上报固件版本。");
                $gaps[] = "清单声明最低固件 {$minFirmware},但本机固件版本未知。";
                $notes[] = "请在安装前自行确认固件版本(需 ≥ {$minFirmware})。";
            } elseif (version_compare($firmware, $minFirmware, '<')) {
                $checks[] = $this->entry('firmware', '固件版本', 'fail', "本机 {$firmware} 低于清单要求 {$minFirmware}。");
                $gaps[] = "固件 {$firmware} 低于声明下限 {$minFirmware}。";
                $status = self::STATUS_MISMATCH;
            } else {
                $checks[] = $this->entry('firmware', '固件版本', 'pass', "本机 {$firmware} ≥ 清单要求 {$minFirmware}。");
            }
        } else {
            $checks[] = $this->entry('firmware', '固件版本', 'unknown', '清单未声明固件下限。');
        }

        // 清单还允许声明 min_app（平台/开发版 App 版本下限）。设备侧目前没有上报
        // 可与之比较的 App 版本字段 —— 这里**如实列为未判定缺口**，不能悄悄忽略，
        // 也不能拿 agent_version / sdk_version 冒充。
        $minApp = is_array($declaredEntry) ? ($declaredEntry['min_app'] ?? null) : null;

        if (is_string($minApp) && $minApp !== '') {
            $checks[] = $this->entry(
                'app',
                '最低 App 版本',
                'unknown',
                "清单声明 ≥ {$minApp}，但设备未上报可比的 App 版本，本次不判定。",
            );
            $gaps[] = "清单声明最低 App 版本 {$minApp}：平台未收到可比的 App 版本，需人工确认。";
        }

        // ── 4) 当前要交付的那一份产物 ──
        $asset = null;
        $ambiguous = false;

        try {
            $asset = $this->assets->resolveForVersion((int) $version->id, $modelCode);
        } catch (SkillAssetAmbiguousException $e) {
            $ambiguous = true;

            $checks[] = $this->entry(
                'asset',
                '交付产物',
                'unknown',
                '该版本有多个文件类交付物,无法唯一确定要验证的是哪一份。',
            );
            $gaps[] = '产物不唯一:平台不替你猜发哪一份,适配结论只能是"尚未验证"。';
        }

        if ($asset) {
            $checks[] = $this->entry(
                'asset',
                '交付产物',
                'pass',
                "当前交付资产 #{$asset->id}".(is_string($asset->file_hash) && $asset->file_hash !== ''
                    ? ' (sha256 '.substr($asset->file_hash, 0, 12).'…)'
                    : ' (哈希缺失)'),
            );
        } elseif (! $ambiguous) {
            $checks[] = $this->entry('asset', '交付产物', 'unknown', '该版本没有可交付的文件类产物。');
            $gaps[] = '该版本没有可交付的文件类产物,无法给出适配结论。';
        }

        // ── 5) 证据 ──
        $query = SkillCompatibilityEvidence::query()
            ->where('skill_version_id', $version->id)
            ->orderByDesc('observed_at')
            ->orderByDesc('id');

        $modelKnown = is_string($modelCode) && $modelCode !== '';

        if ($modelKnown) {
            $query->where('model_code', $modelCode);
        } else {
            // 机型未知时**不读任何机型的证据**：
            //   · 别的机型"真机安装通过"不能升级成这台设备已验证；
            //   · 别的机型"不匹配"也不能判这台设备不匹配。
            //   证据行的 model_code 必填具体机型，不存在"通用证据"。
            $query->whereRaw('1 = 0');
        }

        $all = $query->get();
        $evidenceCount = $all->count();

        if ($evidenceCount === 0) {
            if (! $modelKnown) {
                $checks[] = $this->entry('evidence', '适配证据', 'unknown', '设备机型未知，不借用其它机型的证据。');
                $gaps[] = '设备机型未知：既不采用其它机型"已验证"的结论，也不采用其它机型"不匹配"的结论。';
            } else {
                $checks[] = $this->entry('evidence', '适配证据', 'unknown', '该机型没有任何适配证据。');
                $gaps[] = '该机型尚无任何适配证据(真机/仿真/静态/人工)。';
            }
        } else {
            // 证据只有"钉住当前这份字节"才算数。产物换哈希后,旧证据自动失效。
            $pinned = $all->filter(function (SkillCompatibilityEvidence $e) use ($asset) {
                if (! $asset) {
                    return false;
                }

                $hasId   = $e->asset_id !== null;
                $hasHash = is_string($e->asset_hash) && $e->asset_hash !== '';

                $idMatches = $hasId && (int) $e->asset_id === (int) $asset->id;
                $hashMatches = $hasHash
                    && is_string($asset->file_hash) && $asset->file_hash !== ''
                    && hash_equals($e->asset_hash, $asset->file_hash);

                // 同时钉住 id 与 hash:两者都必须与当前产物一致。
                // 只钉一个:按所钉的那一项判定。都没钉:不构成对某一份字节的验证。
                if ($hasId && $hasHash) {
                    return $idMatches && $hashMatches;
                }

                if ($hasId) {
                    return $idMatches;
                }

                if ($hasHash) {
                    return $hashMatches;
                }

                return false;
            })->values();

            $stale = $all->count() - $pinned->count();

            if ($pinned->isEmpty()) {
                $checks[] = $this->entry(
                    'evidence',
                    '适配证据',
                    'unknown',
                    $stale > 0
                        ? "有 {$stale} 条证据,但钉住的产物与当前交付不是同一份。"
                        : '没有可用于当前交付产物的证据。',
                );

                if ($stale > 0) {
                    $gaps[] = '产物已变更:旧证据钉住的是另一份字节,不构成对当前产物的验证。';
                } else {
                    $gaps[] = '没有钉住当前交付产物的适配证据。';
                }
            } else {
                $conflict = $pinned->first(fn (SkillCompatibilityEvidence $e) => $e->result === SkillCompatibilityEvidence::RESULT_MISMATCH);
                $verified = $pinned->first(fn (SkillCompatibilityEvidence $e) => $e->source === SkillCompatibilityEvidence::SOURCE_DEVICE
                    && $e->evidence_type === SkillCompatibilityEvidence::EVIDENCE_INSTALL
                    && $e->result === SkillCompatibilityEvidence::RESULT_MATCH);
                $matched = $pinned->first(fn (SkillCompatibilityEvidence $e) => $e->result === SkillCompatibilityEvidence::RESULT_MATCH);

                if ($conflict) {
                    $checks[] = $this->entry(
                        'evidence',
                        '适配证据',
                        'fail',
                        "当前产物在该机型有\"不匹配\"记录({$conflict->sourceLabel()}·{$conflict->evidenceTypeLabel()})。",
                    );
                    $gaps[] = '当前产物存在明确的不匹配证据。';
                    $status = self::STATUS_MISMATCH;
                } elseif ($verified) {
                    $checks[] = $this->entry(
                        'evidence',
                        '适配证据',
                        'pass',
                        '真机安装验证通过(上报来源不可被平台独立核验)。',
                    );

                    // 设备未确权时,不能把"这个机型组合验证过"说成"你这台已验证"。
                    if (! $binding) {
                        $checks[] = $this->entry('device', '设备确权', 'warn', '设备尚未确权,只能作为机型级参考。');
                        $gaps[] = '设备尚未确权:即使机型组合有真机安装证据,也不能表述为"你这台已验证"。';

                        if ($status !== self::STATUS_MISMATCH) {
                            $status = self::STATUS_NEEDS_CONFIRMATION;
                        }
                    } else {
                        $checks[] = $this->entry('device', '设备确权', 'pass', '设备已确权。');

                        if ($status !== self::STATUS_MISMATCH) {
                            $status = self::STATUS_VERIFIED;
                        }
                    }
                } elseif ($matched) {
                    $checks[] = $this->entry(
                        'evidence',
                        '适配证据',
                        'warn',
                        "仅有{$matched->sourceLabel()}·{$matched->evidenceTypeLabel()}结论,不足以认定真机安装验证。",
                    );
                    $gaps[] = '缺少"真机上报 + 安装验证 + 匹配"这一级证据。';

                    if ($status !== self::STATUS_MISMATCH) {
                        $status = self::STATUS_NEEDS_CONFIRMATION;
                    }
                } else {
                    $checks[] = $this->entry('evidence', '适配证据', 'unknown', '当前产物只有"无法判定"类记录。');
                    $gaps[] = '当前产物的证据结论为"无法判定"。';
                }

                if ($stale > 0) {
                    $checks[] = $this->entry(
                        'evidence_stale',
                        '历史证据',
                        'warn',
                        "另有 {$stale} 条证据钉住旧产物,已不适用于当前交付。",
                    );
                }
            }
        }

        // ── 6) 能力快照(如实展示已知项,不据此推断适配) ──
        $snapshotKeys = ['development_version', 'sdk_version', 'agent_version'];
        $known = [];

        foreach ($snapshotKeys as $key) {
            if (is_string($snapshot[$key] ?? null) && $snapshot[$key] !== '') {
                $known[] = $key.'='.$snapshot[$key];
            }
        }

        $checks[] = $this->entry(
            'capability_snapshot',
            '能力快照',
            $known === [] ? 'unknown' : 'pass',
            $known === [] ? '设备未上报开发版本/SDK/Agent 版本。' : implode(' · ', $known),
        );

        return [
            'status'         => $status,
            'checks'         => $checks,
            'gaps'           => array_values(array_unique($gaps)),
            'notes'          => array_values(array_unique($notes)),
            'evidence_count' => $evidenceCount,
        ];
    }

    /**
     * 清单 `compatibility.models` 的规整读取 —— 只接受数组项,畸形值一律当"没声明"。
     *
     * @param  mixed  $compatibility
     * @return array<int, array<string, mixed>>
     */
    protected function declaredModels($compatibility): array
    {
        if (! is_array($compatibility)) {
            return [];
        }

        $models = $compatibility['models'] ?? [];

        if (! is_array($models)) {
            return [];
        }

        return array_values(array_filter($models, fn ($entry) => is_array($entry)));
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    protected function entry(string $key, string $label, string $status, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }

    /**
     * 无法进入适配评估时的统一返回(设备不存在/无版本等)。
     *
     * ⚠ 仍保留完整五键形状 —— 调用方按固定结构解析,不能因为一条早退路径就少字段。
     *
     * @return array<string, mixed>
     */
    protected function unverified(string $reason): array
    {
        return [
            'compatible'        => false,
            'reason'            => $reason,
            'channels'          => [],
            'notes'             => [],
            'model'             => null,
            'adaptation_status' => self::STATUS_UNVERIFIED,
            'adaptation_label'  => self::STATUS_LABELS[self::STATUS_UNVERIFIED],
            'checks'            => [],
            'evidence_gaps'     => [$reason],
            'evidence_count'    => 0,
        ];
    }
}

