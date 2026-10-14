<?php

namespace Webkul\Shop\Services\SkillAudit;

/**
 * 审核拒绝清单 —— v3.4 步骤 6(计划 §4.21)。
 *
 * 原则:好/一般技能并存是正常市场;下面这些是"不合格",必须拒。
 * 与"质量差"(降级)是两回事 —— 拒绝是"不能用/不安全/违规/侵权/不完整"。
 */
class RejectionReason
{
    // 技术不可用(可全自动判)
    public const TECH_FORMAT = 'TECH_FORMAT';              // 包格式/manifest 不合格
    public const TECH_UNRUNNABLE = 'TECH_UNRUNNABLE';      // 仿真跑不起来/摔倒/超时
    public const TECH_PERMISSION = 'TECH_PERMISSION';      // 权限越界(声明与实际不符)

    // 内容与合规
    public const CONTENT_VIOLATION = 'CONTENT_VIOLATION';  // 涉政涉黄/违规
    public const CONTENT_UNSCREENED = 'CONTENT_UNSCREENED'; // 内容扫描没跑起来（没查 ≠ 合规）
    public const MALICIOUS_CODE = 'MALICIOUS_CODE';        // 恶意代码
    public const INFRINGEMENT = 'INFRINGEMENT';            // 侵权(无授权)
    public const MISREPRESENTATION = 'MISREPRESENTATION';  // 描述与内容不符

    // 功能不完整
    public const INCOMPLETE = 'INCOMPLETE';                // 半成品/缺关键文件
    public const NO_INSTALL_GUIDE = 'NO_INSTALL_GUIDE';    // 人工通道无安装说明

    // 物理安全
    public const SAFETY_MISSING = 'SAFETY_MISSING';        // 高危动作无 safety 声明

    /**
     * 全部拒绝码 → 人话说明。
     */
    public const LABELS = [
        self::TECH_FORMAT         => '包格式或清单不合格',
        self::TECH_UNRUNNABLE     => '仿真预跑失败(动作跑不起来/姿态失稳/超时)',
        self::TECH_PERMISSION     => '权限声明与实际调用不符(越权)',
        self::CONTENT_VIOLATION   => '内容违规(涉政/涉黄/违规词)',
        self::CONTENT_UNSCREENED  => '内容安全扫描未运行(词表缺失或驱动不可用)——无法确认合规',
        self::MALICIOUS_CODE      => '含恶意代码',
        self::INFRINGEMENT        => '侵权(动作/素材无授权)',
        self::MISREPRESENTATION   => '描述与内容不符(虚假宣传)',
        self::INCOMPLETE          => '功能不完整(半成品/缺关键文件)',
        self::NO_INSTALL_GUIDE    => '人工安装通道缺少安装说明',
        self::SAFETY_MISSING      => '高危动作缺少安全声明',
    ];

    public static function label(string $code): string
    {
        return self::LABELS[$code] ?? $code;
    }
}
