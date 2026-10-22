<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\Validator;

/** 只解释已冻结的取消费率；不从当前配置为旧承诺补造扣罚规则。 */
class SceneCancellationPolicy
{
    public function normalize(array $input): array
    {
        if (isset($input['version']) && is_int($input['version'])) {
            $input['version'] = (string) $input['version'];
        }
        $data = Validator::make($input, [
            'version' => 'required|string|max:80',
            'basis' => 'sometimes|in:verified_paid_total',
            'tiers' => 'required|array|min:1|max:30',
            'tiers.*' => 'required|array:hours_before,penalty_bps,penalty_ratio',
            'tiers.*.hours_before' => 'required|integer|between:0,87600',
            'tiers.*.penalty_bps' => 'sometimes|integer|between:0,10000',
            'tiers.*.penalty_ratio' => 'sometimes|numeric|between:0,1',
            'default_penalty_bps' => 'sometimes|integer|between:0,10000',
            'default_penalty_ratio' => 'sometimes|numeric|between:0,1',
        ])->validate();
        $rate = function (array $values, string $prefix = ''): int {
            $bps = $prefix.'penalty_bps';
            $ratio = $prefix.'penalty_ratio';
            if (array_key_exists($bps, $values) === array_key_exists($ratio, $values)) {
                throw new SceneWorkflowException('SCENE_CANCELLATION_POLICY_INVALID', '每个扣罚档位及兜底费率须明确提供一种费率。', 409);
            }
            if (array_key_exists($bps, $values)) {
                return (int) $values[$bps];
            }
            // 兼容历史冻结的小数比例，转换成整数基点后再计算金额。
            if (! preg_match('/^(?:0(?:\.\d{1,4})?|1(?:\.0{1,4})?)$/D', (string) $values[$ratio])) {
                throw new SceneWorkflowException('SCENE_CANCELLATION_POLICY_INVALID', '扣罚比例最多保留四位小数。', 409);
            }
            [$whole, $fraction] = array_pad(explode('.', (string) $values[$ratio], 2), 2, '');

            return (int) $whole * 10000 + (int) str_pad($fraction, 4, '0');
        };
        $tiers = array_map(fn ($tier) => ['hours_before' => (int) $tier['hours_before'], 'penalty_bps' => $rate($tier)], $data['tiers']);
        if (count(array_unique(array_column($tiers, 'hours_before'))) !== count($tiers)) {
            throw new SceneWorkflowException('SCENE_CANCELLATION_POLICY_INVALID', '取消政策的时间档位不能重复。', 409);
        }
        usort($tiers, fn ($left, $right) => $right['hours_before'] <=> $left['hours_before']);

        return ['version' => $data['version'], 'basis' => 'verified_paid_total', 'tiers' => $tiers, 'default_penalty_bps' => $rate($data, 'default_')];
    }

    public function frozen(array $snapshot): array
    {
        $policy = $snapshot['terms']['cancellation'] ?? $snapshot['terms']['policy']['cancellation'] ?? $snapshot['cancellation_policy'] ?? null;
        if (! is_array($policy)) {
            throw new SceneWorkflowException('SCENE_CANCELLATION_POLICY_MISSING', '项目未冻结客户已接受的取消政策，请先协商并核验依据，不能自动扣罚或结算。', 409);
        }

        return $this->normalize($policy);
    }

    public function penalty(array $policy, string $serviceStartsAt, int $paid): int
    {
        $secondsBefore = \Illuminate\Support\Carbon::parse($serviceStartsAt, 'UTC')->timestamp - now('UTC')->timestamp;
        $bps = $policy['default_penalty_bps'];
        foreach ($policy['tiers'] as $tier) {
            if ($secondsBefore >= $tier['hours_before'] * 3600) {
                $bps = $tier['penalty_bps'];
                break;
            }
        }

        return intdiv($paid * $bps + 5000, 10000);
    }
}
