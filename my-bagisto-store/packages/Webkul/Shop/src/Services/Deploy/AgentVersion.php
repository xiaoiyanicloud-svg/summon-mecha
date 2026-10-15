<?php

namespace Webkul\Shop\Services\Deploy;

/**
 * 版本单调判定 —— §4.22 F1「拒绝低于已装版本的包」/ F3 防降级 的服务端口径。
 *
 * ── 为什么需要 ──
 *   全仓此前**没有**任何版本比较(`version_compare` 零命中):下发只管"装什么",
 *   不管"是不是往回装"。于是攻击者/误操作可以拿一个**签名合法但更旧**的包,
 *   让设备退回有已知漏洞的版本(Uptane 里最经典的 rollback 攻击)。
 *
 *   本类把"版本高低"收敛成**唯一一处**判定:版本单调 → 拒绝降级。
 *   服务端在**建安装任务时**即拦(见 `DeployTaskService::assertNotDowngrade`);
 *   Agent 自更新也只提供**更高**版本(见 `AgentSelfUpdateService`)。
 *
 * ⚠ Uptane/TUF 的完整形态是"端侧凭**签名元数据**独立拒绝降级" —— 那需要设备端实现
 *   (待真机)。本类是**服务端能兑现的那一半**:平台自己不制造、不下发降级指令。
 */
class AgentVersion
{
    /**
     * 语义化比较:$a < $b 返回 -1,= 返回 0,> 返回 1。
     */
    public static function compare(string $a, string $b): int
    {
        return version_compare(self::normalize($a), self::normalize($b));
    }

    /**
     * $target 是否**低于**已装 $installed(即一次降级)。
     *
     * 已装版本未知(installed 为空)时不算降级 —— 平台无从判断,不误拦首次安装。
     */
    public static function isDowngrade(?string $installed, string $target): bool
    {
        if ($installed === null || trim($installed) === '') {
            return false;
        }

        return self::compare($target, $installed) < 0;
    }

    /**
     * $candidate 是否**高于**当前 $current(自更新只推更高版本)。
     *
     * 当前版本未知 → 视为"可更新"(平台无法判断,不阻断;设备端应自行再判一次)。
     */
    public static function isNewer(string $candidate, ?string $current): bool
    {
        if ($current === null || trim($current) === '') {
            return true;
        }

        return self::compare($candidate, $current) > 0;
    }

    /**
     * 归一化:去空白、去前缀 `v`。空串按 "0" 处理,避免 version_compare 对空串的怪异结果。
     */
    protected static function normalize(string $version): string
    {
        $version = trim($version);

        if ($version !== '' && ($version[0] === 'v' || $version[0] === 'V')) {
            $version = substr($version, 1);
        }

        return $version === '' ? '0' : $version;
    }
}
