<?php

namespace Webkul\Shop\Services\Ecosystem\Scoring;

/**
 * G3-07 · 总分降序策略（确定性、定点整数）。
 *
 * 只比较已规整的 `total_scaled`（整数十进制字符串），不接触浮点。
 * 并列语义由规则里的 `tie.policy` 决定：
 *   · shared  —— 并列同名次，后续名次跳号；
 *   · strict  —— 不猜顺序，并列组内全部留空，等待人工核准；
 *   · manual  —— 非并列正常排名，并列组内留空等待人工核准。
 *
 * 注意：并列组是整体判断的。不能“组内第一个人有名次、第二个留空” ——
 * 那等于用排序实现细节偷偷决定了先后。
 */
class TotalDescScoringPolicy implements CompetitionScoringPolicy
{
    public function key(): string
    {
        return 'total_desc';
    }

    public function rank(array $rows, array $context): array
    {
        $tiePolicy = (string) ($context['tie_policy'] ?? 'manual');
        $rankable = [];
        $result = [];

        foreach ($rows as $row) {
            $entryId = (int) $row['entry_id'];

            if ((bool) ($row['excluded'] ?? false)
                || $row['total_scaled'] === null
                || (string) $row['total_scaled'] === '') {
                $result[$entryId] = ['rank_no' => null, 'tie_group' => null];
                continue;
            }

            $rankable[] = [
                'entry_id'     => $entryId,
                'total_scaled' => (string) $row['total_scaled'],
            ];
        }

        usort($rankable, static function (array $left, array $right): int {
            // 降序：右值更小则左值在前。
            return bccomp($right['total_scaled'], $left['total_scaled']);
        });

        $position = 0;
        $count = count($rankable);

        for ($index = 0; $index < $count; $index++) {
            $start = $index;
            $total = $rankable[$index]['total_scaled'];
            $end = $index;

            while ($end + 1 < $count
                && bccomp($rankable[$end + 1]['total_scaled'], $total) === 0) {
                $end++;
            }

            $position = $start + 1;
            $groupSize = $end - $start + 1;
            $tieGroup = $groupSize > 1
                ? 't:'.substr(hash('sha256', $total), 0, 12)
                : null;

            for ($cursor = $start; $cursor <= $end; $cursor++) {
                $entryId = $rankable[$cursor]['entry_id'];

                $result[$entryId] = [
                    'rank_no'   => $groupSize > 1 && $tiePolicy !== 'shared' ? null : $position,
                    'tie_group' => $tieGroup,
                ];
            }

            $index = $end;
        }

        return $result;
    }
}
