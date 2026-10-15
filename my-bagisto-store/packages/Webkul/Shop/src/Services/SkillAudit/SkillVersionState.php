<?php

namespace Webkul\Shop\Services\SkillAudit;

use Webkul\Shop\Models\SkillVersion;

/**
 * 版本生命周期的**唯一写入口** —— 2026-10-16 收敛（裁决 Q2）。
 *
 * 为什么必须收敛成一处：
 *   `skill_versions.status` 此前被多处各写各的（提审不写、发布写 published、归档走批量 update），
 *   结果同一件"审核到哪一步了"的事存了两份且不一致 —— 提审后库里还是 draft，
 *   L2 驳回与"从没提审过"在 status 上分不出，"已发布版本被重复提审"还会写一条
 *   audit_record 却永远不进审核台（静默黑洞）。
 *
 * 现在划清边界：
 *   - **审核判决**（谁审的、判成什么、为什么）唯一事实是 `audit_record`；
 *   - **版本生命周期**（draft / in_review / rejected / published / archived）就是本字段，
 *     且从此**只允许经 `transition()` 写**。任何别处再直接 `$version->status = ...; save()`
 *     都是这条收敛的退路。
 *
 * 迁移是**显式白名单**（见 TRANSITIONS），不在表里的路径一律 ok=false —— 尤其是
 * `draft → published`：没过审的草稿不能一步跳到已发布，那是把闸门绕过去。发布前
 * 必须各自经过 in_review（见 SkillPublisher：先闸门、再 transition）。
 */
class SkillVersionState
{
    /** 迁移成功。 */
    public const OK = 'TRANSITION_OK';

    /** 迁移不在白名单里。 */
    public const INVALID = 'TRANSITION_INVALID';

    /**
     * 合法迁移白名单：`from => [允许到的 to ...]`。
     *
     * 刻意用「从状态 → 允许的目标集合」而不是逐条列 pair —— 目标集合一眼能看出
     * 某个状态有几条出路；漏掉的 pair 会表现为某个 to 缺失，比漏掉一整条更难写错。
     *
     * ⚠ 这里**没有** `draft → published` / `rejected → published`：
     *   发布只能从 in_review 走，且必须过了闸门（SkillPublisher 保证顺序）。
     */
    protected const TRANSITIONS = [
        // 新建的草稿：只能提审
        SkillVersion::STATUS_DRAFT => [
            SkillVersion::STATUS_IN_REVIEW,
        ],
        // 被驳回 / 待补充：整改后重新提审（回到 in_review）
        SkillVersion::STATUS_REJECTED => [
            SkillVersion::STATUS_IN_REVIEW,
        ],
        // 审核中：判驳回 / 提审人撤回 / 过闸门后发布
        SkillVersion::STATUS_IN_REVIEW => [
            SkillVersion::STATUS_REJECTED,   // L0 机审不过 / L2 驳回 / L2 待补充
            SkillVersion::STATUS_DRAFT,      // 撤回提审（本卡只立规则，尚未接调用方）
            SkillVersion::STATUS_PUBLISHED,  // 过闸门发布
        ],
        // 已发布：发新版本时归档旧的
        SkillVersion::STATUS_PUBLISHED => [
            SkillVersion::STATUS_ARCHIVED,
        ],
        // 已归档是终态
        SkillVersion::STATUS_ARCHIVED => [],
    ];

    /**
     * 迁移一个版本的状态。
     *
     * 迁移成功才 `save()`；**除 status 外不碰任何其它字段** —— 这个类只负责生命周期这一根轴，
     * 别的字段（released_at / 授权参数 …）归各自的调用方。调用方若要在同一次落库里带上别的字段，
     * 请在调本方法**之前**把它设到模型上（本方法的 save() 会一并写入）。
     *
     * @return array{ok: bool, code: string, message: string}
     */
    public function transition(SkillVersion $version, string $to): array
    {
        $from = (string) $version->status;

        if (! $this->isAllowed($from, $to)) {
            return [
                'ok'      => false,
                'code'    => self::INVALID,
                'message' => "不允许的状态迁移：{$from} → {$to}。",
            ];
        }

        $version->status = $to;
        $version->save();

        return [
            'ok'      => true,
            'code'    => self::OK,
            'message' => "版本状态已迁移：{$from} → {$to}。",
        ];
    }

    /**
     * 该版本当前**是否允许提审**。
     *
     * 只有「草稿」和「被驳回」可以提出审核 —— 堵掉"已发布版本可重复提审"这类
     * 会污染审核队列的动作。与 TRANSITIONS 保持一致（能从这两个状态去到 in_review）。
     */
    public function canSubmit(SkillVersion $version): bool
    {
        return in_array($version->status, [
            SkillVersion::STATUS_DRAFT,
            SkillVersion::STATUS_REJECTED,
        ], true);
    }

    /**
     * 该迁移是否在白名单里。
     */
    protected function isAllowed(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
