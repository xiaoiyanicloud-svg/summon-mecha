<?php

namespace Webkul\Shop\Services\Scenes;

/** 专业调查字段不隐含能力承诺；unknown 保留为 null，由评估及证据继续判定。 */
class SceneFieldRegistry
{
    public static function fields(string $category): array
    {
        $text = static fn ($key, $label, $max = 2000) => compact('key', 'label', 'max') + ['type' => 'text', 'required' => false, 'assess_required' => true];
        $bool = static fn ($key, $label) => compact('key', 'label') + ['type' => 'boolean', 'required' => false, 'assess_required' => true];
        $number = static fn ($key, $label, $unit, $max, $min = 0, $type = 'decimal') => compact('key', 'label', 'unit', 'max', 'min', 'type') + ['required' => false, 'assess_required' => true];
        $choice = static fn ($key, $label, $options) => compact('key', 'label', 'options') + ['type' => 'choice', 'required' => false, 'assess_required' => true];
        $fields = [$text('city', '服务城市', 100), $text('site', '现场地址', 1000), $text('timezone', '服务地 IANA 时区', 80),
            $text('starts_at', '服务开始（ISO 8601，含 UTC offset）', 40), $text('ends_at', '服务结束（ISO 8601，含 UTC offset）', 40),
            $text('task_goal', '任务目标、范围与不包含的工作'), $bool('indoor', '是否室内'), $bool('floor_level', '运行地面是否平整'),
            $bool('power', '现场电源是否具备'), $bool('safety_contact', '现场安全责任人是否确认'),
            $number('audience', '现场人数', '人', 100000, 1, 'integer')];
        $fields[array_key_last($fields)]['assess_required'] = false;
        $specific = match ($category) {
            'business_event' => [$bool('script_approved', '互动/表演脚本已确认'), $bool('crowd_separated', '观众与设备运行区域已隔离'), $bool('rehearsal_available', '具备现场彩排条件'), $number('sessions', '计划服务场次', '场', 1000, 1, 'integer'), $bool('photography_planned', '是否安排拍摄'), $bool('photography_authorized', '涉及拍摄内容的授权已确认')],
            'enterprise' => [$text('languages', '接待语言与知识库责任范围'), $number('route_width_mm', '最窄通道净宽', 'mm', 100000, 1, 'integer'), $number('floors', '服务楼层数', '层', 200, 1, 'integer'), $bool('lift_integration', '电梯/门禁联动已实测'), $bool('network_available', '现场网络及离线方案已确认'), $bool('knowledge_owner_confirmed', '接待内容责任人已确认')],
            'culture_tourism' => [$number('route_length_m', '导览路线长度', 'm', 100000, 1), $number('max_slope_deg', '路线最大坡度', '度', 90), $bool('outdoor', '是否包含室外路段'), $bool('weather_plan', '恶劣天气停用及转移预案已确认'), $bool('content_rights', '讲解/音视频内容授权已确认')],
            'education' => [$number('age_min', '参与者最小年龄', '岁', 120, 0, 'integer'), $number('age_max', '参与者最大年龄', '岁', 120, 0, 'integer'), $number('class_size', '每场参与人数', '人', 1000, 1, 'integer'), $bool('instructor_present', '现场有负责讲师'), $bool('guardian_consent', '未成年参与及拍摄授权已确认')],
            'security' => [$choice('environment', '巡检环境', [['value' => 'ordinary', 'label' => '普通环境'], ['value' => 'hazardous', 'label' => '高危或特殊防护环境']]), $number('inspection_points', '巡检点位数', '点', 100000, 1, 'integer'), $number('payload_kg', '任务载荷', 'kg', 100000), $bool('emergency_plan', '异常处置及人工接管预案已确认'), $bool('site_permit', '现场准入与安全许可已确认'), $bool('connectivity', '通信覆盖及失联处置已确认')],
            'warehouse_logistics' => [$number('payload_kg', '单次搬运载荷', 'kg', 100000), $number('aisle_width_mm', '最窄通道净宽', 'mm', 100000, 1, 'integer'), $number('flow_units_hour', '目标处理节拍', '件/小时', 1000000, 1, 'integer'), $text('interface_scope', '上下游交接、货物规格与接口边界'), $bool('traffic_separation', '人车分流措施已确认'), $bool('handover_safety', '装卸及交接防护已确认')],
            'industry' => [$choice('task_context', '任务用途', [['value' => 'demonstration', 'label' => '展示/演示'], ['value' => 'production', 'label' => '生产作业']]), $number('payload_kg', '任务载荷', 'kg', 100000), $bool('safety_zone', '安全作业区域已确认'), $bool('integration_owner', '集成及工装责任方已确认'), $text('production_acceptance_criteria', '生产质量与节拍验收指标'), $bool('commissioning_complete', '生产工装及联锁已完成现场调试')],
            'medical' => [$choice('care_scope', '服务范围', [['value' => 'guidance', 'label' => '导览'], ['value' => 'interaction', 'label' => '互动陪伴'], ['value' => 'clinical', 'label' => '诊疗'], ['value' => 'emergency', 'label' => '急救/应急处置']]), $text('privacy_scope', '信息采集、接触与隐私边界'), $bool('on_site_caregiver', '有现场服务/照护负责人'), $bool('data_collection', '是否采集人员信息'), $bool('recorded_consents', '相关人员信息处理授权已确认')],
            'home' => [$choice('task_scope', '实际用途', [['value' => 'experience', 'label' => '受控体验'], ['value' => 'diy', 'label' => 'DIY/学习'], ['value' => 'care', 'label' => '独立照护'], ['value' => 'emergency', 'label' => '紧急救助']]), $text('access_limits', '通行、楼梯、宠物及操作限制'), $bool('operator_present', '有具备能力的现场操作者'), $bool('minors_present', '是否有未成年人参与'), $bool('privacy_confirmed', '家庭隐私与拍摄边界已确认')],
            default => throw new SceneWorkflowException('SCENE_SCHEMA_UNSUPPORTED', '场景类别无效。'),
        };
        // 条件项由规则评估按实际情况要求，不将“不适用”伪装成已通过。
        foreach ($specific as &$field) {
            if (in_array($field['key'], ['lift_integration', 'weather_plan', 'guardian_consent', 'production_acceptance_criteria', 'commissioning_complete', 'recorded_consents', 'photography_authorized'], true)) {
                $field['assess_required'] = false;
            }
        }

        return array_merge($fields, $specific);
    }
}
