<?php

namespace Webkul\Shop\Services\ContentSafety;

/**
 * 本地词表扫描器 —— v3.4 步骤 6 的 L0 内容关键字层。
 *
 * 词表放**文件**（不是代码里的数组）：合规词表会长期增删，运维/合规方应该能改它，
 * 而不必发版。文件格式：
 *
 *   # 注释行
 *   赌博            ← 普通子串匹配（大小写不敏感）
 *   re:办理.{0,4}证  ← re: 开头按正则
 *
 * ⚠ 能力边界（别过度宣称）：
 *   - 只做**文本**；图片/音频/视频扫不了；
 *   - 只做**字面 + 轻量规整**（去空白/常见分隔符），对付不了谐音、拆字、变体；
 *   - 它是"挡住明显违规"的第一道闸，**不是** msgSecCheck 的替代品。
 */
class LocalKeywordScanner implements ContentScanner
{
    /** @var string[]|null */
    private ?array $words = null;

    /** @var string[]|null */
    private ?array $patterns = null;

    private ?string $loadError = null;

    public function __construct(protected array $config = []) {}

    public function key(): string
    {
        return 'local';
    }

    public function scan(string $text): ScanResult
    {
        $this->load();

        // 词表没加载成功 → **degraded**，绝不返回 pass（详见 ScanResult 注释）
        if ($this->loadError !== null) {
            return ScanResult::degraded($this->key(), $this->loadError);
        }

        $hits = [];

        // 原文 + 去空白/分隔符的规整文本各扫一遍 —— 「赌 博」「赌-博」这类最朴素的规避
        $variants = array_unique([$text, $this->compact($text)]);

        foreach ($variants as $variant) {
            foreach ($this->words as $word) {
                if (mb_stripos($variant, $word) !== false) {
                    $hits[] = $word;
                }
            }

            foreach ($this->patterns as $pattern) {
                if (@preg_match($pattern, $variant, $m) === 1) {
                    $hits[] = $m[0] !== '' ? $m[0] : $pattern;
                }
            }
        }

        $hits = array_values(array_unique($hits));

        return $hits === []
            ? ScanResult::pass($this->key())
            : ScanResult::violation($hits, $this->key());
    }

    /**
     * 载入词表（一次，实例内缓存）。
     */
    protected function load(): void
    {
        if ($this->words !== null || $this->loadError !== null) {
            return;
        }

        $path = $this->config['words_path'] ?? null;

        if (! $path || ! is_file($path) || ! is_readable($path)) {
            $this->loadError = '词表文件不存在或不可读：'.($path ?: '(未配置 CONTENT_SAFETY_WORDS_PATH)');

            return;
        }

        $words = [];
        $patterns = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 're:')) {
                $pattern = $this->normalizePattern(trim(substr($line, 3)));

                // 非法正则直接跳过（不能因为运维写错一条正则就让整层挂掉）
                if ($pattern !== '' && @preg_match($pattern, '') !== false) {
                    $patterns[] = $pattern;
                }

                continue;
            }

            $words[] = $line;
        }

        $this->words = $words;
        $this->patterns = $patterns;
    }

    /**
     * 规整：去掉空白与常见分隔符，用来兜「赌 博」这类拆写规避。
     */
    protected function compact(string $text): string
    {
        return (string) preg_replace('/[\s\-\_\.\*\|~·・]+/u', '', $text);
    }

    /**
     * 规整运维写的正则，让它"随手写就能用"：
     *
     *   1. **裸模式自动包定界符** —— `办.{0,3}证` → `/办.{0,3}证/u`
     *      （PCRE 要求定界符；不写 `//` 是运维最常犯的错，且报错信息很难懂）
     *   2. **自动补 `u`** —— 不带 `u` 时 `.` 按**字节**匹配，一个汉字占 3 字节，
     *      `办.{0,3}证` 会永远匹配不上。写中文规则时不该还需要记住这件事。
     */
    protected function normalizePattern(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $first = $raw[0];

        // 首字节 >127 说明第一个字符是中文等多字节字符 —— 那不可能是定界符
        $isDelimiter = ord($first) < 128
            && ! ctype_alnum($first)
            && $first !== '\\'
            && ! ctype_space($first);

        $end = $isDelimiter ? strrpos($raw, $first) : false;

        // 看起来是 `/…/modifiers` 形式：只需确认有 u
        if ($isDelimiter && $end !== false && $end > 0
            && preg_match('/^[a-zA-Z]*$/', substr($raw, $end + 1)) === 1
        ) {
            $modifiers = substr($raw, $end + 1);

            return str_contains($modifiers, 'u')
                ? $raw
                : substr($raw, 0, $end + 1).$modifiers.'u';
        }

        // 裸模式：包起来 + 补 u
        return '/'.$raw.'/u';
    }
}
