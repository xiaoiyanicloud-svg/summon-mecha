<?php

namespace Webkul\Shop\Services\SkillCompile\Portable;

/**
 * 标准技能源包语义与安全校验器。
 * 校验 btcpp/xml4 标准格式、行为树结构合法性、白名单能力动作及安全边界。
 */
class PortableSourceValidator
{
    public const ALLOWED_CONTROL_NODES = [
        'Sequence',
        'Fallback',
        'Parallel',
        'ReactiveSequence',
        'ReactiveFallback',
    ];

    public const ALLOWED_DECORATORS = [
        'Inverter',
        'RetryUntilSuccessful',
        'Repeat',
        'Timeout',
        'ForceSuccess',
        'ForceFailure',
    ];

    public const ALLOWED_ACTIONS = [
        'SM_GestureWave',
        'SM_AudioPlay',
        'SM_MotionSequence',
    ];

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

        // 1. 防御 XXE 与高危实体解析
        if (preg_match('/<!ENTITY|<!DOCTYPE|SYSTEM|PUBLIC/i', $xmlContent)) {
            $errors[] = "禁止使用 XML DTD / 外部实体注入 (XXE)";
            return $errors;
        }

        // 2. 检查危险代码模式与路径穿越
        if (preg_match('/\.\.[\/\\\\]/', $xmlContent)) {
            $errors[] = "XML 属性中包含非法路径穿越字符 (../)";
        }

        if (preg_match('/(bash|sh|cmd|powershell|curl|wget|eval|exec|subprocess)\b/i', $xmlContent)) {
            $errors[] = "XML 属性中检测到疑似系统命令或恶意注入代码";
        }

        // 3. XML 解析
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

        // 4. 校验根节点格式
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
        }

        // 5. 递归遍历树节点校验
        $trees = $xml->xpath('//BehaviorTree');
        if (empty($trees)) {
            $errors[] = "未定义任何 <BehaviorTree>";
            return $errors;
        }

        $hasMainTree = false;
        foreach ($trees as $t) {
            if ((string) $t['ID'] === $mainTree) {
                $hasMainTree = true;
            }
            $this->validateNode($t, $errors);
        }

        if (! $hasMainTree) {
            $errors[] = "未找到主执行树: {$mainTree}";
        }

        return $errors;
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
            } elseif (in_array($tag, self::ALLOWED_CONTROL_NODES, true) || in_array($tag, self::ALLOWED_DECORATORS, true)) {
                $this->validateNode($child, $errors);
            } else {
                $errors[] = "不支持的控制或装饰节点标签: <{$tag}>";
            }
        }
    }

    /**
     * 校验具体动作属性。
     */
    protected function validateActionAttributes(string $actionId, \SimpleXMLElement $node, array &$errors): void
    {
        switch ($actionId) {
            case 'SM_GestureWave':
                $hand = (string) $node['hand'];
                if (! in_array($hand, ['left', 'right', 'both'], true)) {
                    $errors[] = "SM_GestureWave hand 属性必须为 left/right/both，实际为: '{$hand}'";
                }
                $cycles = (int) $node['cycles'];
                if ($cycles <= 0 || $cycles > 50) {
                    $errors[] = "SM_GestureWave cycles 必须在 1~50 之间，实际为: '{$cycles}'";
                }
                break;

            case 'SM_AudioPlay':
                $asset = (string) $node['asset'];
                if (empty($asset)) {
                    $errors[] = "SM_AudioPlay 缺少 asset 音频文件路径";
                } elseif (preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $asset) !== 1) {
                    $errors[] = "SM_AudioPlay asset 包含非法字符: '{$asset}'";
                }
                break;

            case 'SM_MotionSequence':
                break;
        }
    }
}
