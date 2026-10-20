<?php

namespace Webkul\Shop\Services\Ecosystem\Scoring;

/**
 * 人工计分：不自动产生名次。
 *
 * 赛规没有可执行的排名策略，或策略为 manual 时使用；名次必须由运营
 * 通过显式排名动作写入。代码绝不按报名时间/ID 猜顺序。
 */
class ManualScoringPolicy implements CompetitionScoringPolicy
{
    public function key(): string
    {
        return 'manual';
    }

    public function rank(array $rows, array $context): array
    {
        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row['entry_id']] = [
                'rank_no'   => null,
                'tie_group' => null,
            ];
        }

        return $result;
    }
}
