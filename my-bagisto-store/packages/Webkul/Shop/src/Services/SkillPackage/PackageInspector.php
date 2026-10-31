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

    /** 黑名单扩展名(可执行/脚本 —— 默认不允许出现)。 */
    public const FORBIDDEN_EXT = [
        'sh', 'bash', 'exe', 'dll', 'bat', 'cmd', 'ps1', 'vbs',
        'py', 'pl', 'rb', 'jar', 'msi', 'scr',
    ];

    /** 任何技能类型（含 app / composite）一律禁止的二进制/宿主危险扩展名。 */
    public const ALWAYS_FORBIDDEN_EXT = [
        'exe', 'dll', 'bat', 'cmd', 'ps1', 'vbs', 'jar', 'msi', 'scr', 'php', 'phar',
    ];

    /** 仅在 kind=app / composite 时允许携带的脚本扩展名（须通过静态高危指令扫描）。 */
    public const APP_ALLOWED_SCRIPT_EXT = [
        'py', 'sh', 'bash', 'js', 'pl', 'rb',
    ];

    /** 允许包含运行时脚本的技能类型。 */
    public const SCRIPT_CAPABLE_KINDS = ['app', 'composite'];

    /** 脚本静态安全扫描：高危系统破坏指令特征。 */
    public const DANGEROUS_SCRIPT_PATTERNS = [
        '/\brm\s+-[rf]{1,2}\s+\/(?:\s|$|\*)/i',
        '/\bmkfs(?:\.[a-z0-9]+)?\b/i',
        '/\bdd\s+if=\/dev\/(?:zero|random|urandom)\s+of=\/dev\//i',
        '/\/dev\/tcp\//i',
        '/\b(?:curl|wget)\b[^\n|]*\|\s*(?:ba)?sh\b/i',
        '/:\(\)\s*\{\s*:\|:&\s*\}\s*;:/',
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

        if (! class_exists(\ZipArchive::class)) {
            return $this->fail('服务器缺少 ZipArchive 扩展支持，无法解包技能包。');
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return $this->fail('无法打开技能包(不是有效的 ZIP)。');
        }

        try {
            $files = $this->listFiles($zip);
            $errors = [];
            $warnings = [];

            // 先定位并尝试解析清单，判断技能 kind 是否为允许脚本的 app / composite
            $manifestFile = $this->findManifest($files);
            $parsed = null;
            $allowScripts = false;

            if ($manifestFile) {
                $content = $zip->getFromName($manifestFile);
                $parsed = $this->parser->parse((string) $content, $manifestFile);
                if ($parsed['ok']) {
                    $kind = $parsed['manifest']['kind'] ?? null;
                    $allowScripts = in_array($kind, self::SCRIPT_CAPABLE_KINDS, true);
                }
            }

            // 扩展名与脚本静态安全检查
            foreach ($files as $f) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));

                if (in_array($ext, self::ALWAYS_FORBIDDEN_EXT, true)) {
                    $errors[] = "包内含禁止的可执行/脚本文件 [{$f}]。";

                    continue;
                }

                if (in_array($ext, self::FORBIDDEN_EXT, true) || in_array($ext, self::APP_ALLOWED_SCRIPT_EXT, true)) {
                    if (! $allowScripts) {
                        $errors[] = "包内含禁止的可执行/脚本文件 [{$f}]。";

                        continue;
                    }

                    // app / composite 允许携带脚本，但须执行静态高危指令扫描
                    $scriptContent = (string) $zip->getFromName($f);
                    foreach (self::DANGEROUS_SCRIPT_PATTERNS as $pattern) {
                        if (preg_match($pattern, $scriptContent) === 1) {
                            $errors[] = "脚本文件 [{$f}] 含高危系统破坏指令，禁止上传。";
                            break;
                        }
                    }

                    $warnings[] = "应用/复合类技能包含脚本文件 [{$f}]，已纳入安全复核范围。";
                }
            }

            if (! $manifestFile) {
                $zip->close();

                return ['ok' => false, 'manifest' => [], 'errors' => array_merge($errors, ['缺少清单文件(manifest.json / manifest.yaml)。']), 'warnings' => $warnings, 'files' => $files];
            }

            $zip->close();

            if (! $parsed['ok']) {
                return ['ok' => false, 'manifest' => [], 'errors' => array_merge($errors, [$parsed['error']]), 'warnings' => $warnings, 'files' => $files];
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
