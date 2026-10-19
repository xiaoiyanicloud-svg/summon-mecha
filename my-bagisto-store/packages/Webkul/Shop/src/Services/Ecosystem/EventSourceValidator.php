<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\EcosystemOrganizer;
use Webkul\Shop\Models\Event;

/**
 * 活动来源 / 主办方校验（ADR-0001 G1-D01 / G1-D05 / §4.2 / §9）。
 *
 * 校验分两层：
 *   · `assertDraftAssignment()`：草稿可保存的边界 —— 三字段同时为空或同时齐全、
 *     枚举合法、来源/角色组合合法、organizer 存在且角色一致、状态未被拒绝；
 *   · `assertPublishable()`：发布前置 —— 在草稿边界之上，要求 organizer 已核验、
 *     官方来源 URL 合法，且命中 B-01/B-03 的发布放行组合。
 *
 * 错误码只有两个（ADR §6）：
 *   · `EVENT_SOURCE_REQUIRED`：缺来源/枚举未知/组合非法/organizer 不存在或角色不一致/URL 非法；
 *   · `EVENT_ORGANIZER_UNVERIFIED`：organizer 未核验、被拒绝/暂缓，或发布组合未放行。
 *
 * **不抓取 URL**：这里只做字面校验（协议、宿主、私网/回环），不发起任何网络请求，
 * 也不复制外部正文/图片（ADR G1-D05）。
 */
class EventSourceValidator
{
    /**
     * 草稿态：校验事件模型当前的三字段组合。
     */
    public function assertDraftAssignment(Event $event): void
    {
        $this->assertDraftValues(
            $event->source_type,
            $event->platform_role,
            $event->organizer_id === null ? null : (int) $event->organizer_id,
        );
    }

    /**
     * 草稿态：直接校验待写入的三字段。
     *
     * 三个都 NULL（或调用方显式清空）才允许；任一为 NULL、空串、0 都视为“来源不完整”。
     * 空串**不**等价于 NULL —— 要清空请传 NULL，避免库里出现“有值但无意义”的空串。
     */
    public function assertDraftValues(?string $sourceType, ?string $platformRole, ?int $organizerId): void
    {
        if ($sourceType === null && $platformRole === null && $organizerId === null) {
            return;
        }

        if (
            $sourceType === null
            || $sourceType === ''
            || $platformRole === null
            || $platformRole === ''
            || $organizerId === null
            || $organizerId <= 0
        ) {
            throw $this->sourceRequired('活动来源三字段（source_type / platform_role / organizer_id）必须同时填写或同时留空。');
        }

        $this->assertSourceType($sourceType);
        $this->assertPlatformRole($platformRole);

        // 组合约束（ADR G1-D01）。MySQL 5.7 不用 CHECK，全部在应用层强制。
        if ($sourceType === 'external' && $platformRole !== 'directory_only') {
            throw $this->sourceRequired('source_type=external 时 platform_role 只能是 directory_only。');
        }

        if ($sourceType === 'platform' && $platformRole === 'directory_only') {
            throw $this->sourceRequired('source_type=platform 时 platform_role 不得为 directory_only。');
        }

        $organizer = EcosystemOrganizer::query()->find($organizerId);

        if ($organizer === null) {
            throw $this->sourceRequired(sprintf('主办方 %d 不存在。', $organizerId));
        }

        // 三方不推定：活动引用的角色必须与主体经核验可承担的角色一致。
        if ($organizer->platform_role !== $platformRole) {
            throw $this->sourceRequired(sprintf(
                '主办方 %d 的平台角色为 %s，与活动声明的 %s 不一致，请新建主体并单独核验。',
                $organizerId,
                (string) $organizer->platform_role,
                $platformRole,
            ));
        }

        // 草稿允许 pending / verified；rejected / suspended 不得继续挂到新草稿上。
        if (! in_array($organizer->status, ['pending', 'verified'], true)) {
            throw $this->organizerUnverified(sprintf(
                '主办方 %d 当前状态为 %s，不能作为活动来源。',
                $organizerId,
                (string) $organizer->status,
            ));
        }
    }

    /**
     * 发布前置：在草稿边界之上追加核验与发布放行条件。
     */
    public function assertPublishable(Event $event): void
    {
        $this->assertDraftAssignment($event);

        $sourceType = $event->source_type;
        $platformRole = $event->platform_role;
        $organizerId = $event->organizer_id === null ? null : (int) $event->organizer_id;

        if ($sourceType === null || $platformRole === null || $organizerId === null || $organizerId <= 0) {
            throw $this->sourceRequired('发布前必须完整填写活动来源与主办方。');
        }

        $organizer = EcosystemOrganizer::query()->find($organizerId);

        if ($organizer === null) {
            throw $this->sourceRequired(sprintf('主办方 %d 不存在。', $organizerId));
        }

        if ($organizer->status !== 'verified') {
            throw $this->organizerUnverified(sprintf('主办方 %d 尚未通过核验。', $organizerId));
        }

        // 官方来源 URL 是发布前置；organizer 的 source_url 为唯一来源（events 不存这一列）。
        $this->assertSourceUrl($organizer->source_url);

        if (
            ! in_array($sourceType, $this->publishAllowedSourceTypes(), true)
            || ! in_array($platformRole, $this->publishAllowedPlatformRoles(), true)
        ) {
            throw $this->organizerUnverified(sprintf(
                '来源类型 %s / 平台角色 %s 尚未开放发布（B-01/B-03 未签署前只放行 platform + organizer）。',
                $sourceType,
                $platformRole,
            ));
        }
    }

    public function assertSourceType(?string $sourceType): void
    {
        if ($sourceType === null) {
            return;
        }

        if (! in_array($sourceType, $this->sourceTypes(), true)) {
            throw $this->sourceRequired(sprintf('未知的活动来源类型「%s」。', $sourceType));
        }
    }

    public function assertPlatformRole(?string $platformRole): void
    {
        if ($platformRole === null) {
            return;
        }

        if (! in_array($platformRole, $this->platformRoles(), true)) {
            throw $this->sourceRequired(sprintf('未知的平台角色「%s」。', $platformRole));
        }
    }

    /**
     * 官方来源 URL 字面校验。只允许 http/https，且不得指向本机/私网。
     * 不抓取、不解析响应、不复制内容。
     */
    public function assertSourceUrl(?string $url): void
    {
        if ($url === null || trim($url) === '') {
            throw $this->sourceRequired('来源 URL 不能为空。');
        }

        if (mb_strlen($url) > 2048) {
            throw $this->sourceRequired('来源 URL 长度不得超过 2048 个字符。');
        }

        // ASCII 空白/控制字符 + Unicode 分隔符/控制字符：URL 里出现即拒绝。
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1 || preg_match('/[\p{Z}\p{C}]/u', $url) === 1) {
            throw $this->sourceRequired('来源 URL 不得包含空白或控制字符。');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw $this->sourceRequired('来源 URL 格式非法。');
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw $this->sourceRequired('来源 URL 只允许 http / https 协议。');
        }

        // 拒绝 userinfo（user:pass@host）—— 不允许把凭据写进公开来源。
        if (array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            throw $this->sourceRequired('来源 URL 不得包含用户信息。');
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw $this->sourceRequired('来源 URL 不得指向 localhost。');
        }

        // IPv6 区域标识（fe80::1%25eth0）不是可公开访问的来源。
        if (str_contains($host, '%')) {
            throw $this->sourceRequired('来源 URL 的宿主格式非法。');
        }

        if ($this->isBlockedIpLiteral($host)) {
            throw $this->sourceRequired('来源 URL 不得指向回环、私网或保留地址。');
        }
    }

    /**
     * @return array<int, string>
     */
    private function sourceTypes(): array
    {
        return array_values((array) config('ecosystem_organizers.source_types', ['platform', 'external']));
    }

    /**
     * @return array<int, string>
     */
    private function platformRoles(): array
    {
        return array_values((array) config(
            'ecosystem_organizers.platform_roles',
            ['organizer', 'co_organizer', 'authorized_operator', 'directory_only']
        ));
    }

    /**
     * @return array<int, string>
     */
    private function publishAllowedSourceTypes(): array
    {
        return array_values((array) config('ecosystem_organizers.publish_allowed_source_types', ['platform']));
    }

    /**
     * @return array<int, string>
     */
    private function publishAllowedPlatformRoles(): array
    {
        return array_values((array) config('ecosystem_organizers.publish_allowed_platform_roles', ['organizer']));
    }

    private function isBlockedIpLiteral(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $lower = strtolower($host);

            // 回环、未指定、链路本地、唯一本地、组播。
            if (
                $lower === '::1'
                || $lower === '::'
                || str_starts_with($lower, 'fe80:')
                || str_starts_with($lower, 'fc')
                || str_starts_with($lower, 'fd')
                || str_starts_with($lower, 'ff')
            ) {
                return true;
            }

            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }

        // IPv4 简写 / 整数 / 十六进制形式（127.1、2130706433、0x7f000001）：
        // 浏览器可能解释成私网地址，一律拒绝；合法公网域名不会命中这几种形态。
        if (
            preg_match('/^[0-9.]+$/', $host) === 1
            || preg_match('/^0x[0-9a-f]+$/i', $host) === 1
        ) {
            return true;
        }

        return false;
    }

    private function sourceRequired(string $message): EventSourceValidationException
    {
        return new EventSourceValidationException(ApiErrorCode::EVENT_SOURCE_REQUIRED, $message);
    }

    private function organizerUnverified(string $message): EventSourceValidationException
    {
        return new EventSourceValidationException(ApiErrorCode::EVENT_ORGANIZER_UNVERIFIED, $message);
    }
}
