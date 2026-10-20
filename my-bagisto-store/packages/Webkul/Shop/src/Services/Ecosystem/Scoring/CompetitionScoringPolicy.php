<?php

namespace Webkul\Shop\Services\Ecosystem\Scoring;

/**
 * G3-07 · 计分策略接口。
 *
 * 策略只做**确定性**的排名计算：输入是已规整的定点总分，输出名次/并列组。
 * 永不接受用户表达式/脚本/公式；新增策略必须显式登记在
 * `config/ecosystem_competition.php` 的 `scoring_policies`。
 */
interface CompetitionScoringPolicy
{
    public function key(): string;

    /**
     * @param  array<int, array{entry_id:int, total_scaled:string|null, excluded:bool, forfeit_status:string}>  $rows
     * @param  array{tie_policy:string}  $context
     * @return array<int, array{rank_no:int|null, tie_group:string|null}>
     */
    public function rank(array $rows, array $context): array;
}
