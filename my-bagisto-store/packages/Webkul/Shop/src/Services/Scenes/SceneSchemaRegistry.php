<?php

namespace Webkul\Shop\Services\Scenes;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Webkul\Shop\Models\Scene;

class SceneSchemaRegistry
{
    public function schema(string $category, int $version = 2): array
    {
        if (! array_key_exists($category, Scene::CATEGORIES)) {
            throw new SceneWorkflowException('SCENE_SCHEMA_UNSUPPORTED', '场景类别无效。');
        }
        if ($version === 2) {
            return ['version' => 2, 'rule_version' => 'scene-site-v2', 'category' => $category,
                'fields' => SceneFieldRegistry::fields($category), 'unknown_policy' => 'manual_review',
                'notice' => '初次受理允许未知；正式方案须有现场核验及对应设备/任务证据。'];
        }
        if ($version !== 1) {
            throw new SceneWorkflowException('SCENE_SCHEMA_UNSUPPORTED', '表单版本不受支持。');
        }
        $fields = [
            ['key' => 'city', 'label' => '服务城市', 'type' => 'text', 'required' => true, 'max' => 100],
            ['key' => 'site', 'label' => '现场地址', 'type' => 'text', 'required' => true, 'max' => 1000],
            ['key' => 'timezone', 'label' => '服务地 IANA 时区', 'type' => 'text', 'required' => true, 'max' => 80],
            ['key' => 'starts_at', 'label' => '开始时间（含 UTC offset）', 'type' => 'text', 'required' => true, 'max' => 40],
            ['key' => 'ends_at', 'label' => '结束时间（含 UTC offset）', 'type' => 'text', 'required' => true, 'max' => 40],
            ['key' => 'audience', 'label' => '现场人数', 'type' => 'integer', 'unit' => '人', 'min' => 1, 'max' => 100000, 'required' => false],
            ['key' => 'indoor', 'label' => '是否室内（未知请留空）', 'type' => 'boolean', 'required' => false],
            ['key' => 'floor_level', 'label' => '是否平整单层地面', 'type' => 'boolean', 'required' => false],
            ['key' => 'power', 'label' => '是否有可用电源', 'type' => 'boolean', 'required' => false],
            ['key' => 'safety_contact', 'label' => '现场安全负责人是否确认', 'type' => 'boolean', 'required' => false],
        ];
        // 类别补充用于勘察；字段未知不会自动给出能力结论。
        $specific = [
            'education' => ['age_range', '参与者年龄范围'],
            'medical' => ['privacy_scope', '隐私与接触范围'],
            'industry' => ['payload_kg', '搬运载荷（kg）'],
            'warehouse_logistics' => ['payload_kg', '搬运载荷（kg）'],
            'home' => ['access_limits', '家庭通行限制'],
        ];
        [$key, $label] = $specific[$category] ?? ['task_goal', '任务目标与边界'];
        $fields[] = ['key' => $key, 'label' => $label, 'type' => 'text', 'required' => false, 'max' => 2000];

        return ['version' => 1, 'category' => $category, 'fields' => $fields];
    }

    public function validate(array $facts, array $schema, bool $complete = false): array
    {
        $rules = [];
        foreach ($schema['fields'] as $field) {
            $rule = [$complete && $field['required'] ? 'required' : 'nullable'];
            $rule[] = match ($field['type']) {
                'integer' => 'integer', 'boolean' => 'boolean', 'decimal' => 'numeric', default => 'string'
            };
            if ($field['type'] === 'choice') {
                $rule[] = Rule::in(array_column($field['options'], 'value'));
            }
            if ($field['type'] === 'decimal') {
                $rule[] = 'regex:/^\d{1,9}(?:\.\d{1,3})?$/D';
            }
            if (isset($field['max'])) {
                $rule[] = 'max:'.$field['max'];
            }
            if (isset($field['min'])) {
                $rule[] = 'min:'.$field['min'];
            }
            $rules[$field['key']] = $rule;
        }
        $unknown = array_diff(array_keys($facts), array_keys($rules));
        if ($unknown) {
            throw new SceneWorkflowException('SCENE_FACT_UNKNOWN', '存在当前表单不支持的字段，请刷新表单。');
        }

        $values = Validator::make($facts, $rules)->validate();
        foreach ($schema['fields'] as $field) {
            if (! array_key_exists($field['key'], $values) || $values[$field['key']] === null) {
                continue;
            }
            if ($field['type'] === 'boolean') {
                $values[$field['key']] = (bool) $values[$field['key']];
            }
            if ($field['type'] === 'integer') {
                $values[$field['key']] = (int) $values[$field['key']];
            }
        }

        return $values;
    }
}
