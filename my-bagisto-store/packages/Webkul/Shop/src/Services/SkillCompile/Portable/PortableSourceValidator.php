<?php

namespace Webkul\Shop\Services\SkillCompile\Portable;

/**
 * 标准技能源包语义与安全校验器 (Work Package C02)。
 * 严格校验 btcpp/xml4 标准格式、行为树结构、首版受限控制流、白名单动作及安全边界。
 */
class PortableSourceValidator
{
    /**
     * 首版仅允许 Sequence 控制流，拒绝 Parallel/Fallback/Reactive/Repeat/Retry。
     */
    public const ALLOWED_CONTROL_NODES = [
        'Sequence',
    ];

    public const ALLOWED_DECORATORS = [];

    /**
     * 首版白名单动作：彻底移除 SM_MotionSequence 假支持。
     */
    public const ALLOWED_ACTIONS = [
        'SM_GestureWave',
        'SM_AudioPlay',
        'SM_Wait',
    ];

    public const MAX_XML_SIZE = 524288; // 512 KB
    public const MAX_TREE_DEPTH = 16;
    public const MAX_TOTAL_NODES = 128;

    /**
     * 校验清单中的 runtime 配置是否符合标准可移植契约。
     */
    public function validateManifest(array $manifest): array
    {
        $errors = [];

        if (($manifest['schema'] ?? '') !== 'zhaohuan.skill/v2') {
            $errors[] = "不支持的 schema: " . ($manifest['schema'] ?? 'null');
        }

        $runtime = $manifest['runtime'] ?? null;
        if (! is_array($runtime)) {
            $errors[] = "缺少 runtime 配置段";
            return $errors;
        }

        if (($runtime['source_format'] ?? '') !== 'btcpp/xml4') {
            $errors[] = "不支持的 source_format: " . ($runtime['source_format'] ?? 'null') . " (仅支持 btcpp/xml4)";
        }

        if (($runtime['capability_contract'] ?? '') !== 'portable-actions/v1') {
            $errors[] = "不支持的 capability_contract: " . ($runtime['capability_contract'] ?? 'null') . " (仅支持 portable-actions/v1)";
        }

        if (empty($runtime['source_entrypoint'])) {
            $errors[] = "缺少 source_entrypoint 入口声明";
        }

        return $errors;
    }

    /**
     * 校验 BehaviorTree XML4 字符串的语法、结构与安全白名单。
     */
    public function validateXml(string $xmlContent): array
    {
        $errors = [];

        // 1. 大小预算检查
        if (strlen($xmlContent) > self::MAX_XML_SIZE) {
            $errors[] = "XML 大小超过预算上限 (" . self::MAX_XML_SIZE . " 字节)";
            return $errors;
        }

        // 2. 防御 XXE、外部实体、XInclude、外部 URL
        if (preg_match('/<!ENTITY|<!DOCTYPE|SYSTEM|PUBLIC|<xi:include/i', $xmlContent)) {
            $errors[] = "禁止使用 XML DTD / 外部实体注入 (XXE) / XInclude";
            return $errors;
        }

        if (preg_match('/(https?|ftp|file):\/\//i', $xmlContent)) {
            $errors[] = "XML 属性中禁止包含外部 URL 或外部协议";
        }

        // 3. 检查危险代码模式与路径穿越
        if (preg_match('/\.\.[\/\\\\]/', $xmlContent)) {
            $errors[] = "XML 属性中包含非法路径穿越字符 (../)";
        }

        if (preg_match('/(bash|sh|cmd|powershell|curl|wget|eval|exec|subprocess)\b/i', $xmlContent)) {
            $errors[] = "XML 属性中检测到疑似系统命令或恶意注入代码";
        }

        // 4. XML 解析
        $prevEntityLoader = libxml_disable_entity_loader(true);
        $prevErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOWARNING);
            if ($xml === false) {
                foreach (libxml_get_errors() as $err) {
                    $errors[] = "XML 语法错误 [行 {$err->line}]: " . trim($err->message);
                }
                return $errors;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prevErrors);
            if (PHP_VERSION_ID < 80000) {
                libxml_disable_entity_loader($prevEntityLoader);
            }
        }

        // 5. 校验根节点格式
        if ($xml->getName() !== 'root') {
            $errors[] = "根元素必须是 <root>";
            return $errors;
        }

        $format = (string) $xml['BTCPP_format'];
        if ($format !== '4') {
            $errors[] = "BTCPP_format 必须为 4";
        }

        $mainTree = (string) $xml['main_tree_to_execute'];
        if ($mainTree === '') {
            $errors[] = "缺少 main_tree_to_execute 属性";
            return $errors;
        }

        // 6. 查找主执行树
        $trees = $xml->xpath('//BehaviorTree');
        if (empty($trees)) {
            $errors[] = "未定义任何 <BehaviorTree>";
            return $errors;
        }

        $mainTreeNode = null;
        foreach ($trees as $t) {
            if ((string) $t['ID'] === $mainTree) {
                $mainTreeNode = $t;
                break;
            }
        }

        if (! $mainTreeNode) {
            $errors[] = "未找到主执行树: {$mainTree}";
            return $errors;
        }

        // 7. 递归校验主执行树深度与节点总数
        $nodeCount = 0;
        $maxDepth = 0;
        $this->inspectNodeBudget($mainTreeNode, 1, $nodeCount, $maxDepth);

        if ($maxDepth > self::MAX_TREE_DEPTH) {
            $errors[] = "行为树深度 ({$maxDepth}) 超过最大限制 (" . self::MAX_TREE_DEPTH . ")";
        }

        if ($nodeCount > self::MAX_TOTAL_NODES) {
            $errors[] = "行为树节点总数 ({$nodeCount}) 超过最大限制 (" . self::MAX_TOTAL_NODES . ")";
        }

        // 8. 递归校验主树节点语法
        $this->validateNode($mainTreeNode, $errors);

        return $errors;
    }

    /**
     * 交叉校验清单与 XML 语义一致性（权限、资源、超时）。
     */
    public function validateCrossSemantics(array $manifest, array $ir): array
    {
        $errors = [];
        $declaredPerms = $manifest['permissions'] ?? [];

        // 校验权限声明是否涵盖全部所需动作权限
        foreach ($ir['required_permissions'] as $reqPerm) {
            if (! in_array($reqPerm, $declaredPerms, true)) {
                $errors[] = "源声明权限不足：行为树需要权限 '{$reqPerm}'，但清单未声明";
            }
        }

        // 校验执行总时长是否超过清单安全上限
        $maxDurationS = (int) ($manifest['safety']['max_duration_s'] ?? 0);
        if ($maxDurationS > 0) {
            $totalTimeoutMs = 0;
            foreach ($ir['actions'] as $act) {
                $totalTimeoutMs += (int) ($act['timeout_ms'] ?? 10000);
            }
            if ($totalTimeoutMs > ($maxDurationS * 1000)) {
                $errors[] = "行为树总预估超时 ({$totalTimeoutMs}ms) 超过清单 safety.max_duration_s ({$maxDurationS}s)";
            }
        }

        return $errors;
    }

    /**
     * 计算树的深度与节点数量。
     */
    protected function inspectNodeBudget(\SimpleXMLElement $node, int $currentDepth, int &$nodeCount, int &$maxDepth): void
    {
        $nodeCount++;
        if ($currentDepth > $maxDepth) {
            $maxDepth = $currentDepth;
        }

        foreach ($node->children() as $child) {
            $this->inspectNodeBudget($child, $currentDepth + 1, $nodeCount, $maxDepth);
        }
    }

    /**
     * 递归校验单个节点。
     */
    protected function validateNode(\SimpleXMLElement $node, array &$errors): void
    {
        foreach ($node->children() as $child) {
            $tag = $child->getName();

            if ($tag === 'Action') {
                $actionId = (string) $child['ID'];
                if (! in_array($actionId, self::ALLOWED_ACTIONS, true)) {
                    $errors[] = "未知的非白名单动作节点: {$actionId}";
                } else {
                    $this->validateActionAttributes($actionId, $child, $errors);
                }
            } elseif (in_array($tag, self::ALLOWED_CONTROL_NODES, true)) {
                $this->validateNode($child, $errors);
            } else {
                $errors[] = "首版仅支持受限 Sequence 控制流，不支持的控制或装饰节点: <{$tag}>";
            }
        }
    }

    /**
     * 校验具体动作属性（严格类型检查，杜绝盲目强制转换）。
     */
    protected function validateActionAttributes(string $actionId, \SimpleXMLElement $node, array &$errors): void
    {
        switch ($actionId) {
            case 'SM_GestureWave':
                $hand = (string) $node['hand'];
                if (! in_array($hand, ['left', 'right', 'both'], true)) {
                    $errors[] = "SM_GestureWave hand 属性必须为 left/right/both，实际为: '{$hand}'";
                }

                $rawCycles = (string) $node['cycles'];
                if (! preg_match('/^[1-9]\d*$/', $rawCycles)) {
                    $errors[] = "SM_GestureWave cycles 必须为正整数且不可为空，实际为: '{$rawCycles}'";
                } else {
                    $cycles = (int) $rawCycles;
                    if ($cycles > 50) {
                        $errors[] = "SM_GestureWave cycles 必须在 1~50 之间，实际为: {$cycles}";
                    }
                }

                if (isset($node['timeout_ms'])) {
                    $rawTimeout = (string) $node['timeout_ms'];
                    if (! preg_match('/^[1-9]\d*$/', $rawTimeout)) {
                        $errors[] = "SM_GestureWave timeout_ms 必须为正整数，实际为: '{$rawTimeout}'";
                    }
                }
                break;

            case 'SM_AudioPlay':
                $asset = (string) $node['asset'];
                if (empty($asset)) {
                    $errors[] = "SM_AudioPlay 缺少 asset 音频文件路径";
                } elseif (preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $asset) !== 1) {
                    $errors[] = "SM_AudioPlay asset 包含非法字符: '{$asset}'";
                }

                if (isset($node['wait'])) {
                    $waitStr = strtolower((string) $node['wait']);
                    if (! in_array($waitStr, ['true', 'false', '1', '0'], true)) {
                        $errors[] = "SM_AudioPlay wait 必须为布尔值 (true/false)，实际为: '{$node['wait']}'";
                    }
                }

                if (isset($node['timeout_ms'])) {
                    $rawTimeout = (string) $node['timeout_ms'];
                    if (! preg_match('/^[1-9]\d*$/', $rawTimeout)) {
                        $errors[] = "SM_AudioPlay timeout_ms 必须为正整数，实际为: '{$rawTimeout}'";
                    }
                }
                break;

            case 'SM_Wait':
                $rawDuration = (string) ($node['duration_ms'] ?? $node['duration'] ?? '');
                if (! preg_match('/^[1-9]\d*$/', $rawDuration)) {
                    $errors[] = "SM_Wait 缺少有效 duration_ms 正整数参数，实际为: '{$rawDuration}'";
                } else {
                    $dur = (int) $rawDuration;
                    if ($dur > 60000) {
                        $errors[] = "SM_Wait duration_ms 超过最大单步等待限制 (60000ms)，实际为: {$dur}";
                    }
                }
                break;
        }
    }
}
