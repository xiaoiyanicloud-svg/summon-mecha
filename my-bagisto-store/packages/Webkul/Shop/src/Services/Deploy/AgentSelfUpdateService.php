<?php

namespace Webkul\Shop\Services\Deploy;

/**
 * Agent 自更新工件服务 —— §4.22 F1「自更新」/ F3「签名与回滚」(B-13)。
 *
 * ── 背景 ──
 *   计划要求 Agent 自身可升级、且升级失败自动回滚旧版(balena supervisor 范式)。
 *   全仓此前无任何自更新概念。**设备侧执行**(下载→验签→替换→失败回滚)待真机,
 *   本服务只做实**服务端能兑现的那一半**:告诉设备"有没有更高的版本、包在哪、怎么验签"。
 *
 * ── 防降级(与 F3 同源)──
 *   本服务**绝不**提供 <= 设备当前版本的工件 —— 即使平台发布的 latest 被人为改低,
 *   对已装更高版本的设备也只会回 `DOWNGRADE_BLOCKED`。这是服务端侧的版本单调闸。
 *
 * ── 签名 ──
 *   工件信息用平台私钥签(`agent_self_update|version|url|hash|ts`),设备用内置公钥验签
 *   —— 与技能包签名同一套密钥/{@see SigningService}(端侧 `verify()` 已可复用)。
 *
 * ⚠ 待真机:工件的下载/替换/失败自动回滚是**设备侧**行为,本机无真机,未验证。
 */
class AgentSelfUpdateService
{
    public function __construct(protected SigningService $signing) {}

    /**
     * 平台发布的最新 Agent 元数据(config/deploy_gateways.php: agent_latest)。
     */
    public function manifest(): array
    {
        return (array) config('deploy_gateways.agent_latest', []);
    }

    public function isConfigured(): bool
    {
        $m = $this->manifest();

        return filled($m['version'] ?? null)
            && filled($m['url'] ?? null)
            && filled($m['hash'] ?? null);
    }

    /**
     * 给一台"当前版本为 $current"的设备算出可用的自更新工件。
     *
     * @return array<string,mixed>
     */
    public function offerFor(?string $current): array
    {
        $m = $this->manifest();
        $latest = (string) ($m['version'] ?? '');

        if (! $this->isConfigured()) {
            return [
                'update_available' => false,
                'reason'           => 'NOT_CONFIGURED',
                'current_version'  => $current,
            ];
        }

        if (! AgentVersion::isNewer($latest, $current)) {
            // 已是最新,或平台 latest **反而更低**(防降级)—— 一律不提供。
            return [
                'update_available' => false,
                'reason'           => AgentVersion::isDowngrade($current, $latest) ? 'DOWNGRADE_BLOCKED' : 'UP_TO_DATE',
                'current_version'  => $current,
                'latest_version'   => $latest,
            ];
        }

        $ts = now()->timestamp;
        $url = (string) $m['url'];
        $hash = (string) $m['hash'];

        return [
            'update_available' => true,
            'version'          => $latest,
            'url'              => $url,
            'hash'             => $hash,
            'signature'        => $this->signing->sign($this->canonical($latest, $url, $hash, $ts)),
            'ts'               => $ts,
            'canonical_hint'   => 'agent_self_update|version|url|hash|ts',
        ];
    }

    /**
     * 自更新工件的签名规范串 —— 端云逐字一致(设备侧镜像)。
     */
    public function canonical(string $version, string $url, string $hash, int $ts): string
    {
        return implode('|', ['agent_self_update', $version, $url, $hash, (string) $ts]);
    }

    /**
     * 校验一份自更新工件签名(供自测/端侧复用)。
     */
    public function verify(string $version, string $url, string $hash, int $ts, string $signature): bool
    {
        return $this->signing->verify($this->canonical($version, $url, $hash, $ts), $signature);
    }
}
