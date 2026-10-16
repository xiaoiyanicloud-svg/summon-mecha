<?php

namespace Webkul\Shop\Services\SkillPackage;

/**
 * 中立技能清单(manifest.yaml)的值对象 —— v3.4 步骤 6。
 *
 * 这是"开发者传一份、平台转各机型"的**唯一契约**(计划 §4.20)。
 * 它只是数据的**形状**,不做校验(校验在 ManifestValidator),不做转换(在转换层)。
 *
 * ⚠ 口径更正(2026-10-16 · D-9):"平台转各机型"是**目标**,当前并未成立 ——
 *   转换层只做**编排与登记**(`SkillBuildService` → `skill_build` 表),真正的转换产物
 *   需**外部重定向工具产出或人工回填**,平台侧**当前没有生产者**。这份契约锁的是
 *   清单的**形状**,**不承诺**平台会自动把它编译成任何机型的可下发产物。
 *
 * schema 固定为 zhaohuan.skill/v2。语言码用 `en` 对齐 Bagisto(不是 en_US)。
 */
class SkillManifest
{
    public const SCHEMA = 'zhaohuan.skill/v2';

    /**
     * schema 族名（命名空间部分）—— 与具体版本解耦。
     *
     * 用途：分辨"是不是本平台的清单"。族内换版本（/v2 → /v3）仍是我们的；
     * 族外（别的命名空间）直接判为"不认识"，不当作版本问题。
     */
    public const SCHEMA_FAMILY = 'zhaohuan.skill';

    /**
     * 平台**当前接受**的具体版本清单。
     *
     * 这里现在只有一个 v2；将来新增版本 = 往数组里加一项，判定逻辑自动覆盖
     * （`SCHEMA` 仍是"当前推荐版本"，用于给开发者的提示文案）。
     * ⚠ 本卡只做"分清来的是哪个版本"，**不做自动升级/降级**——那是转换层的职责。
     */
    public const SCHEMA_SUPPORTED = [self::SCHEMA];

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

    /**
     * 权限码 → 人话标签（D-8 改判版 · 可见化的**唯一**中文来源）。
     *
     * 用途：技能详情接口与风险告知都要把 `permissions` 渲染成用户看得懂的话
     * （"会控制机器人运动"而不是 `motion_control`）。前端与告知**都从这里取语义**，
     * ⚠ 别在别处再抄一份中文映射 —— 抄一份就会有两份各自漂移。
     *
     * 未收录的码（理论上过不了白名单校验）由调用方回落成原始码，不在此处兜底。
     */
    public const PERMISSION_LABELS = [
        'motion_control' => '运动控制',
        'audio_play'     => '播放语音',
        'camera'         => '摄像头',
        'expression'     => '表情/灯光',
        'network'        => '联网',
        'storage'        => '本地存储',
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

    /**
     * 从 schema 串里解析出版本号：`zhaohuan.skill/v2` → 2。
     *
     * 判据：必须以本族命名空间 `zhaohuan.skill/` 开头，且其后是 `v<正整数>`。
     * 非本族（命名空间不同）或格式不对 → null —— 调用方据此把"版本问题"
     * 与"根本不认识"分开（两者对开发者意味着完全不同的改法）。
     *
     * ⚠ 只识别、不转换：拿到 null 不代表要升级，只表示"这个串我没法归到某个版本"。
     */
    public static function parseSchemaVersion(string $schema): ?int
    {
        $prefix = self::SCHEMA_FAMILY.'/';

        if (! str_starts_with($schema, $prefix)) {
            return null;
        }

        $tail = substr($schema, strlen($prefix));

        // 只接受 `v<digits>`（如 v2 / v10）；`zhaohuan.skill`（无斜杠）走不到这，
        // `zhaohuan.skill/`、`zhaohuan.skill/v`、`.../v2.1` 等一律判为不合法 → null。
        if (! preg_match('/^v(\d+)$/', $tail, $m)) {
            return null;
        }

        return (int) $m[1];
    }
}
