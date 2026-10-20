<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Models\EventRuleVersion;

/**
 * G3-01 · 赛项规则契约校验器。
 *
 * 本类只做两件事：
 *   1. 校验调用方提交的 `rules_profile` 是否完整、类型合法、无占位符；
 *   2. 计算稳定指纹，供报名/参赛/赛程引用。
 *
 * **不提供任何业务默认值**：缺人数上限就拒绝，不会猜 5；缺同分策略就拒绝，
 * 不会按报名时间排序。占位符（TBD / 待定 / 待填写）等同缺失。
 */
class CompetitionRuleProfile
{
    private const PLACEHOLDERS = ['', '-', 'n/a', 'na', 'tbd', 'todo', 'pending', '待定', '待填写', '待确认'];

    /**
     * 校验并返回规范化 profile；失败抛 CompetitionException::rulesIncomplete。
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function assertComplete(array $profile, ?EventRuleVersion $version = null): array
    {
        $errors = $this->errors($profile, $version);

        if ($errors !== []) {
            throw CompetitionException::rulesIncomplete($errors);
        }

        return $this->normalize($profile);
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<int, string>
     */
    public function errors(array $profile, ?EventRuleVersion $version = null): array
    {
        $errors = [];

        foreach ((array) config('ecosystem_competition.required_rule_keys', []) as $key) {
            $value = $this->get($profile, $key);

            if ($value === null || (is_string($value) && $this->isBlank($value))) {
                $errors[] = $key.' 缺失';
            }
        }

        // owners：每项必须是非占位字符串。
        foreach (['rules', 'scoring', 'appeal', 'safety'] as $owner) {
            $value = $this->get($profile, 'owners.'.$owner);

            if (! is_string($value) || $this->isBlank($value)) {
                $errors[] = 'owners.'.$owner.' 必须是明确的负责人';
            }
        }

        $min = $this->get($profile, 'eligibility.min_members');
        $max = $this->get($profile, 'eligibility.max_members');
        $hardLimit = (int) config('ecosystem_competition.hard_limits.team_members', 32);

        if (! is_int($min) && ! (is_string($min) && ctype_digit($min))) {
            $errors[] = 'eligibility.min_members 必须是整数';
        } elseif ((int) $min < 1 || (int) $min > $hardLimit) {
            $errors[] = 'eligibility.min_members 必须在 1~'.$hardLimit.' 之间';
        }

        if (! is_int($max) && ! (is_string($max) && ctype_digit($max))) {
            $errors[] = 'eligibility.max_members 必须是整数';
        } elseif ((int) $max < 1 || (int) $max > $hardLimit) {
            $errors[] = 'eligibility.max_members 必须在 1~'.$hardLimit.' 之间';
        } elseif (is_numeric($min) && (int) $max < (int) $min) {
            $errors[] = 'eligibility.max_members 不得小于 min_members';
        }

        $robotRequired = $this->get($profile, 'eligibility.robot_declaration_required');

        if (! is_bool($robotRequired)) {
            $errors[] = 'eligibility.robot_declaration_required 必须是布尔值';
        }

        if ($robotRequired === true) {
            $fields = $this->get($profile, 'eligibility.robot_declaration_fields');

            if (! is_array($fields) || $fields === []) {
                $errors[] = 'eligibility.robot_declaration_fields 在机器人声明必填时必须非空';
            } else {
                foreach ($fields as $field) {
                    if (! is_string($field) || $this->isBlank($field)) {
                        $errors[] = 'eligibility.robot_declaration_fields 含空字段';
                        break;
                    }
                }
            }
        }

        $scoring = $this->get($profile, 'scoring.policy');
        $allowedScoring = array_keys((array) config('ecosystem_competition.scoring_policies', ['manual' => null]));

        if (! is_string($scoring) || ! in_array($scoring, $allowedScoring, true)) {
            $errors[] = 'scoring.policy 必须是已登记策略：'.implode('/', $allowedScoring);
        }

        $tie = $this->get($profile, 'tie.policy');
        $allowedTie = (array) config('ecosystem_competition.allowed_tie_policies', ['manual']);

        if (! is_string($tie) || ! in_array($tie, $allowedTie, true)) {
            $errors[] = 'tie.policy 当前只允许 '.implode('/', $allowedTie);
        }

        // G3-07：计分字段/精度/缺赛/弃权的条件校验。
        // manual 策略可以不定义字段（人工名次）；一旦定义字段或使用自动策略，
        // 就必须把精度与缺赛/弃权语义写清楚，代码不猜。
        $fields = $this->get($profile, 'scoring.fields');
        $hasFields = is_array($fields) && $fields !== [];
        $scale = $this->get($profile, 'scoring.scale');
        $maxFields = (int) config('ecosystem_competition.scoring.max_fields', 8);
        $maxScale = (int) config('ecosystem_competition.scoring.max_scale', 6);

        if ($hasFields) {
            if (count($fields) > $maxFields) {
                $errors[] = 'scoring.fields 最多 '.$maxFields.' 项';
            }

            $seen = [];

            foreach ($fields as $index => $field) {
                $position = 'scoring.fields['.$index.']';

                if (! is_array($field)) {
                    $errors[] = $position.' 必须是对象';
                    continue;
                }

                $key = $field['key'] ?? null;

                if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,31}$/', $key)) {
                    $errors[] = $position.'.key 只允许小写字母/数字/下划线，长度 1~32';
                } elseif (isset($seen[$key])) {
                    $errors[] = $position.'.key 重复：'.$key;
                } else {
                    $seen[$key] = true;
                }

                $label = $field['label'] ?? null;

                if (! is_string($label) || $this->isBlank($label) || mb_strlen($label) > 60) {
                    $errors[] = $position.'.label 必须是 1~60 字的明确名称';
                }

                $type = $field['type'] ?? 'decimal';

                if (! in_array($type, ['decimal', 'integer'], true)) {
                    $errors[] = $position.'.type 只允许 decimal/integer';
                }

                $direction = $field['direction'] ?? 'higher';

                if (! in_array($direction, ['higher', 'lower'], true)) {
                    $errors[] = $position.'.direction 只允许 higher/lower';
                }

                foreach (['min', 'max'] as $bound) {
                    if (array_key_exists($bound, $field)
                        && $field[$bound] !== null
                        && ! $this->isDecimalString($field[$bound])) {
                        $errors[] = $position.'.'.$bound.' 必须是十进制数字符串（不接受浮点/表达式）';
                    }
                }
            }

            if (! is_int($scale) && ! (is_string($scale) && ctype_digit($scale))) {
                $errors[] = 'scoring.scale 必须是非负整数（小数位）';
            } elseif ((int) $scale < 0 || (int) $scale > $maxScale) {
                $errors[] = 'scoring.scale 必须在 0~'.$maxScale.' 之间';
            }

            $missing = $this->get($profile, 'scoring.missing');

            if (! is_string($missing) || ! in_array($missing, (array) config('ecosystem_competition.scoring.missing_policies', []), true)) {
                $errors[] = 'scoring.missing 必须是 '.implode('/', (array) config('ecosystem_competition.scoring.missing_policies', []));
            }

            $forfeit = $this->get($profile, 'scoring.forfeit');

            if (! is_string($forfeit) || ! in_array($forfeit, (array) config('ecosystem_competition.scoring.forfeit_policies', []), true)) {
                $errors[] = 'scoring.forfeit 必须是 '.implode('/', (array) config('ecosystem_competition.scoring.forfeit_policies', []));
            }
        } elseif ($scoring !== 'manual') {
            $errors[] = 'scoring.fields 在使用自动计分策略时必须非空';
            $errors[] = 'scoring.scale 在使用自动计分策略时必须明确';
        }

        $window = $this->get($profile, 'appeal.window_minutes');

        if (! is_int($window) && ! (is_string($window) && ctype_digit($window))) {
            $errors[] = 'appeal.window_minutes 必须是整数分钟';
        } elseif ((int) $window < 1) {
            $errors[] = 'appeal.window_minutes 必须大于 0';
        }

        $roles = $this->get($profile, 'appeal.allowed_roles');
        $allowedRoles = (array) config('ecosystem_competition.allowed_appeal_roles', ['captain']);

        if (! is_array($roles) || $roles === []) {
            $errors[] = 'appeal.allowed_roles 必须非空';
        } elseif (array_diff($roles, $allowedRoles) !== []) {
            $errors[] = 'appeal.allowed_roles 含未支持角色（本批只支持 '.implode('/', $allowedRoles).'）';
        }

        $evidence = $this->get($profile, 'appeal.evidence_types');

        if (! is_array($evidence) || $evidence === []) {
            $errors[] = 'appeal.evidence_types 必须非空';
        }

        foreach (['safety.owner_role', 'safety.plan_ref', 'safety.emergency_contact_ref'] as $key) {
            $value = $this->get($profile, $key);

            if (! is_string($value) || $this->isBlank($value)) {
                $errors[] = $key.' 必须是明确的引用/岗位';
            }
        }

        // 规则版本必须真实存在；赛项绑定是否属于同一活动由 CompetitionSeriesService 校验。
        if ($version !== null && $version->exists !== true) {
            $errors[] = '规则版本记录无效';
        }

        return array_values(array_unique($errors));
    }

    /**
     * 规则指纹 = sha256(规范化 profile + 规则版本指纹)。
     *
     * @param  array<string, mixed>  $profile
     */
    public function fingerprint(array $profile, ?EventRuleVersion $version = null): string
    {
        $normalized = $this->normalize($profile);
        $versionPrint = $version?->content_fingerprint !== null
            ? (string) $version->content_fingerprint
            : 'no-rule-version';

        return hash('sha256', json_encode([
            'version' => (string) config('ecosystem_competition.rule_profile_version', 'g3-01-v1'),
            'rules_version_fingerprint' => $versionPrint,
            'profile' => $normalized,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 赛项是否已完整确认，且库内指纹与 profile 一致（防手工改库）。
     */
    public function assertTrackReady(CompetitionTrack $track): void
    {
        if (! $track->rulesConfirmed() || ! is_array($track->rules_profile)) {
            throw CompetitionException::rulesUnconfirmed('赛项规则尚未确认。');
        }

        $version = $track->rules_version_id !== null
            ? EventRuleVersion::query()->find($track->rules_version_id)
            : null;

        if ($version === null) {
            throw CompetitionException::rulesUnconfirmed('赛项绑定的规则版本不存在。');
        }

        $expected = $this->fingerprint($track->rules_profile, $version);

        if (! hash_equals($expected, (string) $track->rules_fingerprint)) {
            throw CompetitionException::rulesUnconfirmed('赛项规则指纹与当前 profile 不一致，请重新确认规则。');
        }
    }

    public function writeEnabled(): bool
    {
        return (bool) config('ecosystem_competition.write_enabled', false);
    }

    public function assertWriteEnabled(): void
    {
        if (! $this->writeEnabled()) {
            throw CompetitionException::disabled();
        }
    }

    /**
     * 稳定排序：键按字典序递归排序，便于计算指纹与比较。
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function normalize(array $profile): array
    {
        $normalized = [];

        foreach ($profile as $key => $value) {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    // 列表元素也可能是对象（如 scoring.fields）；必须递归规范化，
                    // 否则 MySQL JSON 回读的键顺序不同会导致指纹误报不一致。
                    $normalized[(string) $key] = array_map(function (mixed $item): mixed {
                        if (is_array($item)) {
                            return $this->normalize($item);
                        }

                        return is_string($item) ? trim($item) : $item;
                    }, $value);
                } else {
                    $normalized[(string) $key] = $this->normalize($value);
                }
            } elseif (is_string($value)) {
                $normalized[(string) $key] = trim($value);
            } else {
                $normalized[(string) $key] = $value;
            }
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function get(array $profile, string $path): mixed
    {
        $value = $profile;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private function isBlank(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));

        return $normalized === '' || in_array($normalized, self::PLACEHOLDERS, true);
    }

    /**
     * 只接受十进制数字面量（可有符号、可带小数），不接受科学计数法/表达式。
     */
    private function isDecimalString(mixed $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        if (! is_string($value)) {
            return false;
        }

        return preg_match('/^-?\d+(?:\.\d+)?$/', trim($value)) === 1;
    }
}
