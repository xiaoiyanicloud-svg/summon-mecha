<?php

namespace Webkul\Shop\Services\SkillPackage;

/**
 * 技能包检查器 —— v3.4 步骤 6。
 *
 * 打开 .skill(ZIP)→ 列文件 → 找清单(manifest.json/yaml)→ 解析 → 契约校验。
 * **只读清单,不落地解压,不执行任何内容。**
 *
 * ⚠ 安全:黑名单扩展名一律拒绝;不把压缩包解到磁盘。
 */
class PackageInspector
{
    /** 清单候选文件名(按优先级)。 */
    public const MANIFEST_FILES = ['manifest.json', 'manifest.yaml', 'manifest.yml'];

    /** 黑名单扩展名(可执行/脚本 —— 不允许出现)。 */
    public const FORBIDDEN_EXT = [
        'sh', 'bash', 'exe', 'dll', 'bat', 'cmd', 'ps1', 'vbs',
        'py', 'pl', 'rb', 'jar', 'msi', 'scr',
    ];

    public function __construct(
        protected ManifestParser $parser,
        protected ManifestValidator $validator,
    ) {}

    /**
     * 检查一个 .skill 包。
     *
     * @return array{ok: bool, manifest: array, errors: array, warnings: array, files: array}
     */
    public function inspect(string $zipPath): array
    {
        if (! is_file($zipPath)) {
            return $this->fail('包文件不存在。');
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return $this->fail('无法打开技能包(不是有效的 ZIP)。');
        }

        try {
            $files = $this->listFiles($zip);
            $errors = [];
            $warnings = [];

            // 黑名单扩展名
            foreach ($files as $f) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (in_array($ext, self::FORBIDDEN_EXT, true)) {
                    $errors[] = "包内含禁止的可执行/脚本文件 [{$f}]。";
                }
            }

            // 找清单
            $manifestFile = $this->findManifest($files);
            if (! $manifestFile) {
                $zip->close();

                return ['ok' => false, 'manifest' => [], 'errors' => ['缺少清单文件(manifest.json / manifest.yaml)。'], 'warnings' => [], 'files' => $files];
            }

            $content = $zip->getFromName($manifestFile);
            $zip->close();

            $parsed = $this->parser->parse((string) $content, $manifestFile);
            if (! $parsed['ok']) {
                return ['ok' => false, 'manifest' => [], 'errors' => [$parsed['error']], 'warnings' => [], 'files' => $files];
            }

            // 契约校验
            $v = $this->validator->validate($parsed['manifest']);
            $errors = array_merge($errors, $v['errors']);
            $warnings = array_merge($warnings, $v['warnings']);

            return [
                'ok'       => empty($errors),
                'manifest' => $parsed['manifest'],
                'errors'   => $errors,
                'warnings' => $warnings,
                'files'    => $files,
            ];
        } catch (\Throwable $e) {
            $zip->close();

            return $this->fail('检查失败:'.$e->getMessage());
        }
    }

    /**
     * 列出包内文件(不落地)。
     */
    protected function listFiles(\ZipArchive $zip): array
    {
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * 在文件列表里找清单(按 MANIFEST_FILES 优先级;允许在子目录内)。
     */
    protected function findManifest(array $files): ?string
    {
        foreach (self::MANIFEST_FILES as $candidate) {
            foreach ($files as $f) {
                if (basename($f) === $candidate) {
                    return $f;
                }
            }
        }

        return null;
    }

    protected function fail(string $error): array
    {
        return ['ok' => false, 'manifest' => [], 'errors' => [$error], 'warnings' => [], 'files' => []];
    }
}
