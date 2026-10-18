<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 技能适配证据 —— 「某版本在某机型/固件/SDK/Agent 组合上被验证过」的一条事实。
 *
 * ── 三条轴，互不替代 ──
 *   source        : 这条事实从哪来（device / simulation / static / manual）
 *   evidence_type : 验证到了哪一步（install / runtime / lab_test / manual_review）
 *   result        : 验证结论（match / mismatch / inconclusive）
 *
 * ⚠ `source=device` **只表示上报来源**，平台无法核验上报者真的是那台机器 ——
 *   与 DeviceBinding 自报同级。`simulation/static/manual` 不得对外表述为"真机已验证"。
 *
 * ⚠ 证据钉住当时的产物（asset_id + asset_hash）。产物换了，旧证据仍在，
 *   但判定时必须比对"证据那条钉住的产物"与"现在要发的那份"是否一致；
 *   不一致就不构成对当前产物的验证。
 */
class SkillCompatibilityEvidence extends Model
{
    public const SOURCE_DEVICE = 'device';
    public const SOURCE_SIMULATION = 'simulation';
    public const SOURCE_STATIC = 'static';
    public const SOURCE_MANUAL = 'manual';

    public const SOURCES = [
        self::SOURCE_DEVICE,
        self::SOURCE_SIMULATION,
        self::SOURCE_STATIC,
        self::SOURCE_MANUAL,
    ];

    public const SOURCE_LABELS = [
        self::SOURCE_DEVICE     => '真机上报',
        self::SOURCE_SIMULATION => '仿真环境',
        self::SOURCE_STATIC     => '静态分析',
        self::SOURCE_MANUAL     => '人工核验',
    ];

    public const EVIDENCE_INSTALL = 'install';
    public const EVIDENCE_RUNTIME = 'runtime';
    public const EVIDENCE_LAB_TEST = 'lab_test';
    public const EVIDENCE_MANUAL_REVIEW = 'manual_review';

    public const EVIDENCE_TYPES = [
        self::EVIDENCE_INSTALL,
        self::EVIDENCE_RUNTIME,
        self::EVIDENCE_LAB_TEST,
        self::EVIDENCE_MANUAL_REVIEW,
    ];

    public const EVIDENCE_TYPE_LABELS = [
        self::EVIDENCE_INSTALL       => '安装验证',
        self::EVIDENCE_RUNTIME       => '运行验证',
        self::EVIDENCE_LAB_TEST      => '实验室测试',
        self::EVIDENCE_MANUAL_REVIEW => '人工复核',
    ];

    public const RESULT_MATCH = 'match';
    public const RESULT_MISMATCH = 'mismatch';
    public const RESULT_INCONCLUSIVE = 'inconclusive';

    public const RESULTS = [
        self::RESULT_MATCH,
        self::RESULT_MISMATCH,
        self::RESULT_INCONCLUSIVE,
    ];

    public const RESULT_LABELS = [
        self::RESULT_MATCH        => '匹配',
        self::RESULT_MISMATCH     => '不匹配',
        self::RESULT_INCONCLUSIVE => '无法判定',
    ];

    protected $table = 'skill_compatibility_evidence';

    protected $fillable = [
        'skill_version_id',
        'product_id',
        'device_did',
        'model_code',
        'development_version',
        'firmware_version',
        'sdk_version',
        'agent_version',
        'carrier_type',
        'asset_id',
        'asset_hash',
        'source',
        'evidence_type',
        'result',
        'summary',
        'verified_by',
        'observed_at',
    ];

    protected $casts = [
        'skill_version_id' => 'integer',
        'product_id'       => 'integer',
        'asset_id'         => 'integer',
        'observed_at'      => 'datetime',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(SkillVersion::class, 'skill_version_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(SkillAsset::class, 'asset_id');
    }

    public function sourceLabel(): string
    {
        return self::SOURCE_LABELS[$this->source] ?? (string) $this->source;
    }

    public function evidenceTypeLabel(): string
    {
        return self::EVIDENCE_TYPE_LABELS[$this->evidence_type] ?? (string) $this->evidence_type;
    }

    public function resultLabel(): string
    {
        return self::RESULT_LABELS[$this->result] ?? (string) $this->result;
    }
}
