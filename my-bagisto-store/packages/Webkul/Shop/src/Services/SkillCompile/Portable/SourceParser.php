<?php

namespace Webkul\Shop\Services\SkillCompile\Portable;

use Webkul\Shop\Services\Deploy\SigningService;

/**
 * 标准技能行为树 XML 解析器 (Work Package C02 & C03)。
 * 将 BT.CPP XML4 转换为语义中间表示（IR），严格以 main_tree_to_execute 为准隔离孤立树。
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
        $mainActions = [];
        $mainAssets = [];
        $mainPermissions = [];

        foreach ($xml->xpath('//BehaviorTree') as $bt) {
            $treeId = (string) $bt['ID'];
            $isMainTree = ($treeId === $mainTreeId);

            $actionsCollector = [];
            $assetsCollector = [];
            $permsCollector = [];

            $nodeTree = $this->parseNode($bt, $actionsCollector, $assetsCollector, $permsCollector);
            $trees[$treeId] = $nodeTree;

            // 仅将主执行树的动作、资源与权限计入执行计划 (C-T05)
            if ($isMainTree) {
                $mainActions = $actionsCollector;
                $mainAssets = $assetsCollector;
                $mainPermissions = $permsCollector;
            }
        }

        $ir = [
            'format'               => 'btcpp/xml4',
            'main_tree_id'         => $mainTreeId,
            'trees'                => $trees,
            'actions'              => $mainActions,
            'required_assets'      => array_values(array_unique($mainAssets)),
            'required_permissions' => array_values(array_unique($mainPermissions)),
        ];

        // 规范化 JCS 摘要计算
        $cleanIr = [
            'format'               => $ir['format'],
            'main_tree_id'         => $ir['main_tree_id'],
            'actions'              => $ir['actions'],
            'required_assets'      => $ir['required_assets'],
            'required_permissions' => $ir['required_permissions'],
        ];

        try {
            $canonicalJson = app(SigningService::class)->jcsCanonicalize($cleanIr);
            $ir['ir_digest'] = 'sha256:' . hash('sha256', $canonicalJson);
        } catch (\Throwable $e) {
            $ir['ir_digest'] = 'sha256:' . hash('sha256', json_encode($cleanIr, JSON_UNESCAPED_SLASHES));
        }

        return $ir;
    }

    protected function parseNode(
        \SimpleXMLElement $node,
        array &$actionsCollector,
        array &$assetsCollector,
        array &$permsCollector
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
                    $permsCollector[] = $p;
                }

                if ($actionId === 'SM_AudioPlay' && ! empty($attrs['asset'])) {
                    $assetsCollector[] = $attrs['asset'];
                }

                $timeoutMs = 10000;
                if (isset($attrs['timeout_ms']) && preg_match('/^[1-9]\d*$/', (string) $attrs['timeout_ms'])) {
                    $timeoutMs = (int) $attrs['timeout_ms'];
                } elseif ($actionId === 'SM_Wait' && isset($attrs['duration_ms'])) {
                    $timeoutMs = (int) $attrs['duration_ms'] + 5000;
                }

                $actionRecord = [
                    'type'        => 'action',
                    'action_id'   => $actionId,
                    'name'        => $actionName,
                    'params'      => $attrs,
                    'permissions' => $perms,
                    'timeout_ms'  => $timeoutMs,
                ];

                $actionsCollector[] = $actionRecord;
                $children[] = $actionRecord;
            } else {
                $children[] = [
                    'type'     => 'control',
                    'tag'      => $tag,
                    'name'     => (string) ($child['name'] ?? $tag),
                    'children' => $this->parseNode($child, $actionsCollector, $assetsCollector, $permsCollector)['children'] ?? [],
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
            'SM_GestureWave' => ['motion_control'],
            'SM_AudioPlay'   => ['audio_play'],
            'SM_Wait'        => [],
            default          => [],
        };
    }
}
