<?php

namespace Webkul\Shop\Services\SkillPackage;

/**
 * 中立技能清单(manifest.yaml)的值对象 —— v3.4 步骤 6。
 *
 * 这是"开发者传一份、平台转各机型"的**唯一契约**(计划 §4.20)。
 * 它只是数据的**形状**,不做校验(校验在 ManifestValidator),不做转换(在转换层)。
 *
 * schema 固定为 zhaohuan.skill/v2。语言码用 `en` 对齐 Bagisto(不是 en_US)。
 */
class SkillManifest
{
    public const SCHEMA = 'zhaohuan.skill/v2';

    /** 技能形态。 */
    public const KIND_MOTION = 'motion';       // 纯动作(可跨本体重定向)

    public const KIND_APP = 'app';             // 容器应用(绑定特定本体)

    public const KIND_COMPOSITE = 'composite'; // 编排

    /** 资源类型(对齐智元 AIMDK 六分类的子集)。 */
    public const RESOURCE_TYPES = ['skill', 'motion', 'emoticon', 'audio'];

    /** 权限白名单(对齐宇树 metadata.yaml 的声明式 permissions)。 */
    public const PERMISSION_WHITELIST = [
        'motion_control',
        'audio_play',
        'camera',
        'expression',
        'network',
        'storage',
    ];

    public function __construct(
        public string $schema,
        public string $kind,
        public array $name,                 // {zh_CN, en}
        public string $version,
        public string $description = '',
        public string $pricingMode = 'buyout',
        public array $deliveryChannels = [],
        public array $permissions = [],
        public string $resourceType = 'skill',
        public array $compatibility = [],   // {models:[{vendor,model,min_firmware,...}]}
        public array $safety = [],          // {risk_level, requires_open_area, estop_required, max_duration_s}
        public array $runtime = [],         // kind=app 时
        public array $motion = [],          // kind=motion 时
        public array $raw = [],
    ) {}

    /**
     * 从解析后的数组构造。
     */
    public static function fromArray(array $a): self
    {
        return new self(
            schema: (string) ($a['schema'] ?? ''),
            kind: (string) ($a['kind'] ?? ''),
            name: is_array($a['name'] ?? null) ? $a['name'] : [],
            version: (string) ($a['version'] ?? ''),
            description: (string) ($a['description'] ?? ''),
            pricingMode: (string) ($a['pricing_mode'] ?? 'buyout'),
            deliveryChannels: is_array($a['delivery_channels'] ?? null) ? $a['delivery_channels'] : [],
            permissions: is_array($a['permissions'] ?? null) ? $a['permissions'] : [],
            resourceType: (string) ($a['resource_type'] ?? 'skill'),
            compatibility: is_array($a['compatibility'] ?? null) ? $a['compatibility'] : [],
            safety: is_array($a['safety'] ?? null) ? $a['safety'] : [],
            runtime: is_array($a['runtime'] ?? null) ? $a['runtime'] : [],
            motion: is_array($a['motion'] ?? null) ? $a['motion'] : [],
            raw: $a,
        );
    }

    public function toArray(): array
    {
        return $this->raw;
    }

    public function nameIn(string $locale = 'zh_CN'): string
    {
        return $this->name[$locale] ?? $this->name['en'] ?? reset($this->name) ?: '';
    }

    public function riskLevel(): string
    {
        return (string) ($this->safety['risk_level'] ?? '');
    }
}
