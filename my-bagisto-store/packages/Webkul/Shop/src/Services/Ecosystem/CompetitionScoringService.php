<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Services\Ecosystem\Scoring\CompetitionScoringPolicy;

/**
 * G3-07 · 计分契约、定点精度与排名策略入口。
 *
 * 设计边界：
 *   · 分数一律转成“定点整数十进制字符串”再参与计算/比较，从不使用 float；
 *   · 输入浮点直接拒绝（JSON 里的 12.5 是 float，必须写成字符串 "12.5"）；
 *   · 字段、精度、缺赛、弃权、同分策略全部来自已确认规则，不补默认业务值；
 *   · 排名只走 `config('ecosystem_competition.scoring_policies')` 中登记的类，
 *     不执行任何用户公式/表达式。
 */
class CompetitionScoringService
{
    private const FIELD_STATUSES = ['scored', 'absent', 'forfeit'];

    public function __construct(private readonly CompetitionRuleProfile $rules) {}

    /**
     * @return array{policy:string, scale:int, fields:array<int, array<string, mixed>>, missing:string, forfeit:string, tie_policy:string}
     */
    public function contract(CompetitionTrack $track): array
    {
        $profile = (array) $track->rules_profile;
        $scoring = (array) ($profile['scoring'] ?? []);
        $tie = (array) ($profile['tie'] ?? []);

        $fields = [];

        foreach ((array) ($scoring['fields'] ?? []) as $field) {
            if (! is_array($field)) {
                continue;
            }

            $fields[] = [
                'key'       => (string) ($field['key'] ?? ''),
                'label'     => (string) ($field['label'] ?? ''),
                'type'      => (string) ($field['type'] ?? 'decimal'),
                'direction' => (string) ($field['direction'] ?? 'higher'),
                'min'       => $field['min'] ?? null,
                'max'       => $field['max'] ?? null,
            ];
        }

        return [
            'policy'     => (string) ($scoring['policy'] ?? ''),
            'scale'      => (int) ($scoring['scale'] ?? 0),
            'fields'     => $fields,
            'missing'    => (string) ($scoring['missing'] ?? ''),
            'forfeit'    => (string) ($scoring['forfeit'] ?? ''),
            'tie_policy' => (string) ($tie['policy'] ?? ''),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fields(CompetitionTrack $track): array
    {
        return $this->contract($track)['fields'];
    }

    public function hasSchema(CompetitionTrack $track): bool
    {
        return $this->fields($track) !== [];
    }

    /**
     * 分数录入规整。返回的值可直接写入结果版本。
     *
     * @param  array<string, mixed>  $values  字段 key => 值 | ['value'=>..., 'status'=>scored|absent|forfeit]
     * @return array{values:array<string, array{value:string|null, scaled:string|null, status:string}>, total_scaled:string|null, forfeit_status:string, excluded:bool}
     */
    public function normalizeValues(CompetitionTrack $track, array $values, string $rowStatus = 'none'): array
    {
        $this->rules->assertTrackReady($track);

        if (! in_array($rowStatus, ['none', 'absent', 'forfeit', 'disqualified'], true)) {
            throw CompetitionException::invalid('成绩行状态不合法。');
        }

        $contract = $this->contract($track);
        $fields = $contract['fields'];
        $scale = $contract['scale'];

        if ($fields === []) {
            if ($values !== []) {
                throw CompetitionException::invalid('该赛项规则未定义计分字段，不能录入数值；请使用人工名次。');
            }

            $disqualified = $rowStatus === 'disqualified';

            return [
                'values'         => [],
                'total_scaled'   => null,
                'forfeit_status' => $disqualified ? 'disqualified' : ($rowStatus === 'absent' || $rowStatus === 'forfeit' ? $rowStatus : 'none'),
                'excluded'       => $disqualified,
            ];
        }

        $known = array_column($fields, 'key');
        $normalized = [];
        $provided = array_keys($values);

        foreach (array_diff($provided, $known) as $unknown) {
            throw CompetitionException::invalid(sprintf('计分字段 %s 不在规则中。', (string) $unknown));
        }

        $excluded = $rowStatus === 'disqualified';
        $forfeitStatus = $rowStatus === 'disqualified' ? 'disqualified' : ($rowStatus === 'forfeit' ? 'forfeit' : ($rowStatus === 'absent' ? 'absent' : 'none'));
        $total = '0';

        foreach ($fields as $field) {
            $key = (string) $field['key'];
            $raw = $values[$key] ?? null;
            $status = 'scored';
            $value = $raw;

            if (is_array($raw)) {
                $status = (string) ($raw['status'] ?? 'scored');
                $value = $raw['value'] ?? null;
            }

            if (! in_array($status, self::FIELD_STATUSES, true)) {
                throw CompetitionException::invalid(sprintf('计分字段 %s 的状态不合法。', $key));
            }

            if ($rowStatus !== 'none') {
                $status = $rowStatus === 'disqualified' ? 'forfeit' : $rowStatus;
            }

            if ($status === 'scored') {
                if ($value === null || $value === '') {
                    throw CompetitionException::invalid(sprintf('计分字段 %s 必须提供分数或明确标记缺赛/弃权。', $key));
                }

                $scaled = $this->decimalToScaled($value, $scale, (string) $field['type']);

                $this->assertBounds($field, $scaled, $scale);

                $normalized[$key] = ['value' => $this->scaledToDecimal($scaled, $scale), 'scaled' => $scaled, 'status' => 'scored'];
                $total = bcadd($total, $scaled, 0);
                continue;
            }

            $normalized[$key] = ['value' => null, 'scaled' => null, 'status' => $status];

            $policy = $status === 'forfeit' ? $contract['forfeit'] : $contract['missing'];

            if ($policy === 'zero') {
                continue;
            }

            $excluded = true;

            if ($policy === 'disqualify') {
                $forfeitStatus = 'disqualified';
            } elseif ($status === 'forfeit' && $forfeitStatus === 'none') {
                $forfeitStatus = 'forfeit';
            } elseif ($status === 'absent' && $forfeitStatus === 'none') {
                $forfeitStatus = 'absent';
            }
        }

        return [
            'values'         => $normalized,
            'total_scaled'   => $excluded ? null : $total,
            'forfeit_status' => $forfeitStatus,
            'excluded'       => $excluded,
        ];
    }

    /**
     * @param  array<int, array{entry_id:int, total_scaled:string|null, excluded?:bool, forfeit_status?:string}>  $rows
     * @return array<int, array{rank_no:int|null, tie_group:string|null}>
     */
    public function rank(CompetitionTrack $track, array $rows): array
    {
        $contract = $this->contract($track);
        $policy = $this->policy($contract['policy']);

        return $policy->rank($rows, ['tie_policy' => $contract['tie_policy']]);
    }

    public function policy(string $key): CompetitionScoringPolicy
    {
        $registry = (array) config('ecosystem_competition.scoring_policies', []);
        $class = $registry[$key] ?? null;

        if (! is_string($class) || ! class_exists($class)) {
            throw CompetitionException::invalid(sprintf('计分策略 %s 未注册。', $key));
        }

        $instance = app($class);

        if (! $instance instanceof CompetitionScoringPolicy) {
            throw CompetitionException::invalid(sprintf('计分策略 %s 未实现 CompetitionScoringPolicy。', $key));
        }

        return $instance;
    }

    /**
     * 成绩版本不可变快照：规则版本 + 计分契约 + 指纹。
     *
     * @return array{snapshot:array<string, mixed>, fingerprint:string}
     */
    public function snapshot(CompetitionTrack $track): array
    {
        $contract = $this->contract($track);
        $snapshot = [
            'rule_profile_version' => (string) config('ecosystem_competition.rule_profile_version', ''),
            'rules_version_id'     => $track->rules_version_id !== null ? (int) $track->rules_version_id : null,
            'rules_fingerprint'    => (string) ($track->rules_fingerprint ?? ''),
            'contract'             => $contract,
        ];

        return [
            'snapshot'    => $snapshot,
            'fingerprint' => hash('sha256', (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }

    /**
     * 十进制字面量 → 定点整数（字符串）。拒绝浮点、科学计数法、表达式。
     */
    public function decimalToScaled(mixed $value, int $scale, string $type = 'decimal'): string
    {
        if (is_float($value)) {
            throw CompetitionException::invalid('分数必须是字符串或整数，不接受浮点数。');
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            throw CompetitionException::invalid('分数必须是数字字符串或整数。');
        }

        $value = trim($value);

        if ($value === '' || preg_match('/^-?\d+(?:\.\d+)?$/', $value) !== 1) {
            throw CompetitionException::invalid('分数只允许十进制数字面量，不接受表达式或科学计数法。');
        }

        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');

        if ($type === 'integer' && $fraction !== '' && trim($fraction, '0') !== '') {
            throw CompetitionException::invalid('该计分字段只允许整数。');
        }

        if (mb_strlen($fraction) > $scale) {
            throw CompetitionException::invalid(sprintf('分数最多允许 %d 位小数。', $scale));
        }

        $fraction = str_pad($fraction, $scale, '0');
        $digits = ltrim($integer.$fraction, '0');
        $digits = $digits === '' ? '0' : $digits;

        $maxDigits = (int) config('ecosystem_competition.scoring.max_digits', 18);

        if (mb_strlen($digits) > $maxDigits) {
            throw CompetitionException::invalid('分数超出允许的位数。');
        }

        if ($digits === '0') {
            return '0';
        }

        return ($negative ? '-' : '').$digits;
    }

    /**
     * 定点整数 → 固定小数位的展示字符串（如 "12.500"）。
     */
    public function scaledToDecimal(string $scaled, int $scale): string
    {
        $negative = str_starts_with($scaled, '-');
        $digits = $negative ? substr($scaled, 1) : $scaled;
        $digits = ltrim($digits, '0');
        $digits = $digits === '' ? '0' : $digits;

        if ($scale === 0) {
            return ($negative ? '-' : '').$digits;
        }

        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $integer = substr($digits, 0, -$scale);
        $fraction = substr($digits, -$scale);

        return ($negative ? '-' : '').$integer.'.'.$fraction;
    }

    /**
     * 缺赛/弃权语义：用于公开 DTO 的稳定文案。
     */
    public function forfeitLabel(string $status): ?string
    {
        return match ($status) {
            'absent'       => '缺赛',
            'forfeit'      => '弃权',
            'disqualified' => '取消资格',
            default        => null,
        };
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function assertBounds(array $field, string $scaled, int $scale): void
    {
        foreach (['min', 'max'] as $bound) {
            if ($field[$bound] === null) {
                continue;
            }

            $limit = $this->decimalToScaled($field[$bound], $scale, 'decimal');
            $comparison = bccomp($scaled, $limit);

            $outOfRange = $bound === 'min' ? $comparison < 0 : $comparison > 0;

            if ($outOfRange) {
                throw CompetitionException::invalid(sprintf(
                    '计分字段 %s 超出规则范围（%s=%s）。',
                    (string) $field['key'],
                    $bound,
                    (string) $field[$bound],
                ));
            }
        }
    }
}
