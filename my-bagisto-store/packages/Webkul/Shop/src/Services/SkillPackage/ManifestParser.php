<?php

namespace Webkul\Shop\Services\SkillPackage;

/**
 * 中立清单解析器 —— v3.4 步骤 6。
 *
 * 支持两种格式:
 *   - JSON(manifest.json)—— 原生解析,始终可用
 *   - YAML(manifest.yaml)—— 仅在 symfony/yaml 可用时启用
 *
 * ⚠ 不手搓 YAML 解析(那才是毁架构)。本项目的 composer 安全策略当前拦下了
 *   symfony/yaml,故 YAML 路径"就绪但未启用";一旦依赖可用,无需改代码即自动生效。
 */
class ManifestParser
{
    /**
     * 按文件扩展名解析清单内容。
     *
     * @return array{ok: bool, manifest: array, error: ?string}
     */
    public function parse(string $content, string $filename): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($ext === 'json') {
            return $this->parseJson($content);
        }

        if (in_array($ext, ['yaml', 'yml'], true)) {
            return $this->parseYaml($content);
        }

        return ['ok' => false, 'manifest' => [], 'error' => "不支持的清单格式 [{$ext}](用 manifest.json 或 manifest.yaml)。"];
    }

    protected function parseJson(string $content): array
    {
        $data = json_decode($content, true);
        if (! is_array($data)) {
            return ['ok' => false, 'manifest' => [], 'error' => 'JSON 解析失败:'.json_last_error_msg()];
        }

        return ['ok' => true, 'manifest' => $data, 'error' => null];
    }

    protected function parseYaml(string $content): array
    {
        $class = 'Symfony\\Component\\Yaml\\Yaml';

        if (! class_exists($class)) {
            return ['ok' => false, 'manifest' => [], 'error' => 'YAML 解析库未启用;请改用 manifest.json,或安装 symfony/yaml。'];
        }

        try {
            $data = $class::parse($content);
        } catch (\Throwable $e) {
            return ['ok' => false, 'manifest' => [], 'error' => 'YAML 解析失败:'.$e->getMessage()];
        }

        return is_array($data)
            ? ['ok' => true, 'manifest' => $data, 'error' => null]
            : ['ok' => false, 'manifest' => [], 'error' => 'YAML 顶层必须是映射。'];
    }
}
