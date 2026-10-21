<?php

namespace Webkul\Shop\Services\Scenes;

/** 规则只消费已冻结的勘察/设备依据；缺信息输出 unknown，不从名称或 AI 文字猜能力。 */
class SceneSiteRules
{
    public function site(array $schema, array $facts, array $sources): array
    {
        $checks = [];
        $add = static function ($key, $state, $reason) use (&$checks) {
            $checks[] = compact('key', 'state', 'reason');
        };
        foreach ($schema['fields'] as $field) {
            if (! ($field['assess_required'] ?? false)) {
                continue;
            }
            $known = array_key_exists($field['key'], $facts) && $facts[$field['key']] !== null && $facts[$field['key']] !== '';
            $verified = ($sources[$field['key']]['state'] ?? '') === 'verified';
            $add('site.'.$field['key'], $known && $verified ? 'pass' : 'unknown', $known && $verified ? '已由本次现场勘察核验' : '须有明确现场核验，客户填写或推测不能替代');
        }
        foreach (['power', 'safety_contact', 'script_approved', 'crowd_separated', 'rehearsal_available', 'network_available', 'knowledge_owner_confirmed', 'content_rights', 'instructor_present', 'emergency_plan', 'site_permit', 'connectivity', 'traffic_separation', 'handover_safety', 'safety_zone', 'integration_owner', 'on_site_caregiver', 'operator_present', 'privacy_confirmed'] as $key) {
            if (array_key_exists($key, $facts) && $facts[$key] !== null) {
                $add('safety.'.$key, $facts[$key] ? 'pass' : 'fail', '现场安全/运营前提须明确满足；不以排名或价格覆盖硬失败');
            }
        }
        $conditional = static function ($key, $needed, $reason) use ($facts, $sources, $add) {
            if (! $needed) {
                $add('conditional.'.$key, 'not_applicable', $reason);

                return;
            }
            $value = $facts[$key] ?? null;
            $state = $value === null || ($sources[$key]['state'] ?? '') !== 'verified' ? 'unknown' : ($value ? 'pass' : 'fail');
            $add('conditional.'.$key, $state, $reason);
        };
        if (isset($facts['floors'])) {
            $conditional('lift_integration', $facts['floors'] > 1, '跨楼层需实测电梯/门禁；单层不适用');
        }
        if (isset($facts['outdoor'])) {
            $conditional('weather_plan', (bool) $facts['outdoor'], '室外运行须有天气停用和转移预案');
        }
        if (isset($facts['age_min'])) {
            $conditional('guardian_consent', $facts['age_min'] < 18, '未成年人参与需确认授权范围');
        }
        if (isset($facts['data_collection'])) {
            $conditional('recorded_consents', (bool) $facts['data_collection'], '采集人员信息须核实授权及用途');
        }
        if (isset($facts['photography_planned'])) {
            $conditional('photography_authorized', (bool) $facts['photography_planned'], '安排拍摄须核实对象、用途与授权范围');
        }
        if (($facts['task_context'] ?? null) === 'production') {
            $conditional('commissioning_complete', true, '生产场景须完成现场工装/联锁调试');
            $add('production.acceptance', ! empty($facts['production_acceptance_criteria']) && ($sources['production_acceptance_criteria']['state'] ?? '') === 'verified' ? 'pass' : 'unknown', '生产质量及节拍须形成现场验收指标');
        }
        if (isset($facts['age_min'],$facts['age_max'])) {
            $add('education.age_range', $facts['age_min'] <= $facts['age_max'] ? 'pass' : 'fail', '最小年龄不得大于最大年龄');
        }
        if (in_array($facts['care_scope'] ?? '', ['clinical', 'emergency'], true) || in_array($facts['task_scope'] ?? '', ['care', 'emergency'], true)) {
            $add('service.boundary', 'fail', '当前受控服务范围不包含诊疗、急救或独立照护，不得由导览/互动能力推导');
        }
        if (! empty($facts['starts_at']) && ! empty($facts['ends_at']) && ! empty($facts['timezone'])) {
            try {
                SceneTime::schedule($facts + ['buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
                $add('site.schedule', 'pass', '时区及服务区间有效');
            } catch (SceneWorkflowException $e) {
                $add('site.schedule', 'fail', $e->getMessage());
            }
        }

        return $checks;
    }

    public function capability(string $category, array $facts, array $constraints, string $prefix): array
    {
        $checks = [];
        $add = static function ($key, $state, $reason) use (&$checks, $prefix) {
            $checks[] = ['key' => $prefix.'.'.$key, 'state' => $state, 'reason' => $reason];
        };
        $add('scope', in_array($category, $constraints['categories'] ?? [], true) ? 'pass' : 'fail', '任务证据必须明确覆盖本场景类别');
        $bounds = ['payload_kg' => ['max_payload_kg', 'max'], 'route_width_mm' => ['min_clearance_mm', 'min'], 'aisle_width_mm' => ['min_clearance_mm', 'min'],
            'max_slope_deg' => ['max_slope_deg', 'max'], 'flow_units_hour' => ['max_throughput_units_hour', 'max'], 'route_length_m' => ['max_route_length_m', 'max'],
            'sessions' => ['max_sessions', 'max'], 'class_size' => ['max_participants', 'max'], 'audience' => ['max_participants', 'max'], 'floors' => ['max_floors', 'max']];
        foreach ($bounds as $field => [$limit,$direction]) {
            if (! isset($facts[$field])) {
                continue;
            }
            if (! isset($constraints[$limit])) {
                $add($field, 'unknown', '任务依据未给出该字段的核验边界，不能默认满足');

                continue;
            }
            $pass = $direction === 'max' ? (float) $facts[$field] <= (float) $constraints[$limit] : (float) $facts[$field] >= (float) $constraints[$limit];
            $add($field, $pass ? 'pass' : 'fail', '现场值与本设备任务依据的定量边界比较');
        }
        foreach (['outdoor_verified' => (($facts['outdoor'] ?? false) || (($facts['indoor'] ?? null) === false)),
            'hazardous_environment_verified' => (($facts['environment'] ?? '') === 'hazardous'), 'production_verified' => (($facts['task_context'] ?? '') === 'production')] as $key => $required) {
            if ($required) {
                $add($key, ! array_key_exists($key, $constraints) || $constraints[$key] === null ? 'unknown' : ($constraints[$key] ? 'pass' : 'fail'), '特殊工况必须有本设备专项核验，通用演示依据不能替代');
            }
        }
        if (($facts['floor_level'] ?? null) === false) {
            $add('floor', 'fail', '当前标准履约要求平整地面，复杂地形须专项适配后建立新依据');
        }

        return $checks;
    }
}
