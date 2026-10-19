<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Http\Controllers\API\ApiErrorCode;

/**
 * 核心层发布状态机（ADR-0001 §5.1 / 附录 B A-04 / G1-08A / G1-08B）。
 *
 * 单一职责：按 `config/ecosystem_publishing.php` 的状态转移表判断动作是否合法，
 * 并返回动作后的父对象 `publish_status`。不写库、不写审计、不碰 revision。
 *
 * 关键语义（A-04）：
 *   · 从未发布：draft -> in_review -> approved / rejected+回 draft；
 *   · 已发布：submit / approve / reject / schedule / 发布新候选后父状态**保持 published**，
 *     `published_revision_id` 在切换前不变；候选版本由 `approved_revision_id` 表达；
 *   · retracted 终态：任何动作都拒绝；
 *   · G1-08B 增加 schedule / unschedule / publish / withhold / restore / retract，
 *     以及按域判断的发布开关（内容域额外要求 B-04 / B-07）。
 */
class PublicationState
{
    /**
     * @throws PublicationTransitionException 审核开关关闭
     */
    public function assertReviewEnabled(string $domain): void
    {
        $key = $this->normalizeDomain($domain).'_review_enabled';

        if (! (bool) config('ecosystem_publishing.'.$key, true)) {
            throw $this->transition($domain, sprintf(
                '生态发布审核开关 %s 未开启，%s 审核动作不可用。',
                $key,
                $domain,
            ));
        }
    }

    /**
     * 只读判断：该域当前是否允许发布 / 排期 / 恢复。
     *
     * 与 `assertPublishEnabled()` 使用同一组配置，供 G1-09 到期扫描器在
     * **整个域被关闭时跳过扫描**。扫描器不能用 `assertPublishEnabled()`
     * 的异常来做“跳过”：那会把“运营关掉发布开关”误判成“这条排期业务失败”，
     * 进而回退已排期对象。判断逻辑必须与断言保持一致。
     */
    public function publishEnabled(string $domain): bool
    {
        $domain = $this->normalizeDomain($domain);

        if (! (bool) config('ecosystem_publishing.'.$domain.'_publish_enabled', false)) {
            return false;
        }

        if ($domain !== 'content') {
            return true;
        }

        return (bool) config('ecosystem_publishing.b04_content_slug_signed', false)
            && (bool) config('ecosystem_publishing.b07_content_legal_signed', false);
    }

    /**
     * 发布 / 排期 / 恢复的总开关（ADR 附录 B A-05 / §8.9）。
     *
     * 活动域只要求 `event_publish_enabled`；B-01/B-03 由
     * PublicationPreconditions + EventSourceValidator 按组合判断。
     *
     * 内容域在 B-04（slug 最终规则）与 B-07（法律责任）未签署时，
     * 即使 `content_publish_enabled=true` 也必须拒绝发布 / 排期 / 恢复。
     *
     * @throws PublicationTransitionException 开关未开启
     */
    public function assertPublishEnabled(string $domain): void
    {
        $domain = $this->normalizeDomain($domain);
        $enabledKey = $domain.'_publish_enabled';

        if (! (bool) config('ecosystem_publishing.'.$enabledKey, false)) {
            throw $this->transition($domain, sprintf(
                '生态发布开关 %s 未开启，%s 发布 / 排期动作不可用。',
                $enabledKey,
                $domain,
            ));
        }

        if ($domain !== 'content') {
            return;
        }

        $blocking = [];

        if (! (bool) config('ecosystem_publishing.b04_content_slug_signed', false)) {
            $blocking[] = 'b04_content_slug_signed';
        }

        if (! (bool) config('ecosystem_publishing.b07_content_legal_signed', false)) {
            $blocking[] = 'b07_content_legal_signed';
        }

        if ($blocking !== []) {
            throw $this->transition('content', sprintf(
                '内容发布前置未签署（%s），publish / schedule / restore 保持关闭。',
                implode(' / ', $blocking),
            ));
        }
    }

    /**
     * @throws PublicationTransitionException disposition 为 retracted
     */
    public function assertDispositionActionable(string $domain, string $disposition): void
    {
        if ($disposition === 'retracted') {
            throw $this->transition($domain, sprintf(
                '%s 已撤稿（disposition=retracted），任何提交/批准/发布/恢复动作都被拒绝。',
                $domain,
            ));
        }
    }

    /**
     * 发布 / 排期只允许 disposition=normal；暂缓对象必须先 restore，不得借发布绕过。
     *
     * @throws PublicationTransitionException disposition 不是 normal
     */
    public function assertDispositionNormal(string $domain, string $disposition): void
    {
        if ($disposition !== 'normal') {
            throw $this->transition($domain, sprintf(
                '%s 当前 disposition=%s，发布 / 排期只允许 normal；暂缓对象必须先恢复。',
                $domain,
                $disposition,
            ));
        }
    }

    /**
     * 解析动作后的父 `publish_status`。
     *
     * @throws PublicationTransitionException 当前状态不在转移表中
     */
    public function targetStatus(string $domain, string $action, string $currentStatus): string
    {
        $domain = $this->normalizeDomain($domain);
        $transitions = (array) config('ecosystem_publishing.transitions.'.$domain.'.'.$action, []);
        $target = $transitions[$currentStatus] ?? null;

        if (! is_string($target) || $target === '') {
            throw $this->transition($domain, sprintf(
                '%s 当前 publish_status=%s，不允许动作 %s。',
                $domain,
                $currentStatus,
                $action,
            ));
        }

        return $target;
    }

    /**
     * 只允许 `config('ecosystem_publishing.publish_statuses')` 中的父状态参与流转；
     * 其他值一律 fail-closed。
     */
    public function assertKnownPublishStatus(string $domain, string $status): void
    {
        $allowed = array_values((array) config('ecosystem_publishing.publish_statuses', []));

        if (! in_array($status, $allowed, true)) {
            throw $this->transition($domain, sprintf(
                '%s 的 publish_status=%s 不在已知状态表中。',
                $domain,
                $status,
            ));
        }
    }

    /**
     * 处置轴值必须来自配置词表。
     */
    public function assertKnownDisposition(string $domain, string $disposition): void
    {
        $allowed = array_values((array) config('ecosystem_publishing.dispositions', []));

        if (! in_array($disposition, $allowed, true)) {
            throw $this->transition($domain, sprintf(
                '%s 的 disposition=%s 不在已知处置表中。',
                $domain,
                $disposition,
            ));
        }
    }

    public function normalizeDomain(string $domain): string
    {
        if (! in_array($domain, ['event', 'content'], true)) {
            throw new \InvalidArgumentException(sprintf('未知的发布域「%s」。', $domain));
        }

        return $domain;
    }

    public function transition(string $domain, string $message): PublicationTransitionException
    {
        return $this->normalizeDomain($domain) === 'event'
            ? PublicationTransitionException::event($message)
            : PublicationTransitionException::content($message);
    }

    public function conflict(string $domain, string $message): PublicationPreconditionException
    {
        $code = $this->normalizeDomain($domain) === 'event'
            ? ApiErrorCode::EVENT_REVISION_CONFLICT
            : ApiErrorCode::CONTENT_REVISION_CONFLICT;

        return new PublicationPreconditionException($code, $message);
    }
}
