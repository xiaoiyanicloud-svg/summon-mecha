<?php

namespace Webkul\Shop\Services\SkillCompile\Portable;

/**
 * 标准技能行为树 XML 解析器 —— 将 BT.CPP XML4 转换为平台中间表示（IR）。
 */
class SourceParser
{
    /**
     * 将 XML 字符串解析为结构化 IR 对象。
     */
    public function parse(string $xmlContent): array
    {
        $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOWARNING);
        if ($xml === false) {
            throw new \InvalidArgumentException("无法解析的行为树 XML");
        }

        $mainTreeId = (string) $xml['main_tree_to_execute'];
        $trees = [];
        $allActions = [];
        $requiredAssets = [];
        $requiredPermissions = [];

        foreach ($xml->xpath('//BehaviorTree') as $bt) {
            $treeId = (string) $bt['ID'];
            $nodeTree = $this->parseNode($bt, $allActions, $requiredAssets, $requiredPermissions);
            $trees[$treeId] = $nodeTree;
        }

        $ir = [
            'format'               => 'btcpp/xml4',
            'main_tree_id'         => $mainTreeId,
            'trees'                => $trees,
            'actions'              => $allActions,
            'required_assets'      => array_values(array_unique($requiredAssets)),
            'required_permissions' => array_values(array_unique($requiredPermissions)),
        ];

        $ir['ir_digest'] = 'sha256:' . hash('sha256', json_encode($ir, JSON_UNESCAPED_SLASHES));

        return $ir;
    }

    protected function parseNode(
        \SimpleXMLElement $node,
        array &$allActions,
        array &$requiredAssets,
        array &$requiredPermissions
    ): array {
        $children = [];
        foreach ($node->children() as $child) {
            $tag = $child->getName();
            if ($tag === 'Action') {
                $actionId = (string) $child['ID'];
                $actionName = (string) ($child['name'] ?? $actionId);
                $attrs = [];
                foreach ($child->attributes() as $k => $v) {
                    if ($k !== 'ID' && $k !== 'name') {
                        $attrs[$k] = (string) $v;
                    }
                }

                $perms = $this->permissionsForAction($actionId);
                foreach ($perms as $p) {
                    $requiredPermissions[] = $p;
                }

                if ($actionId === 'SM_AudioPlay' && ! empty($attrs['asset'])) {
                    $requiredAssets[] = $attrs['asset'];
                }

                $actionRecord = [
                    'type'        => 'action',
                    'action_id'   => $actionId,
                    'name'        => $actionName,
                    'params'      => $attrs,
                    'permissions' => $perms,
                    'timeout_ms'  => isset($attrs['timeout_ms']) ? (int) $attrs['timeout_ms'] : 10000,
                ];

                $allActions[] = $actionRecord;
                $children[] = $actionRecord;
            } else {
                $children[] = [
                    'type'     => 'control',
                    'tag'      => $tag,
                    'name'     => (string) ($child['name'] ?? $tag),
                    'children' => $this->parseNode($child, $allActions, $requiredAssets, $requiredPermissions)['children'] ?? [],
                ];
            }
        }

        return [
            'type'     => 'container',
            'children' => $children,
        ];
    }

    protected function permissionsForAction(string $actionId): array
    {
        return match ($actionId) {
            'SM_GestureWave', 'SM_MotionSequence' => ['motion_control'],
            'SM_AudioPlay' => ['audio_play'],
            default => [],
        };
    }
}
