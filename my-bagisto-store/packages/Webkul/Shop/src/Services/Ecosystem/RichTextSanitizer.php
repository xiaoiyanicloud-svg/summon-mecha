<?php

namespace Webkul\Shop\Services\Ecosystem;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use HTMLPurifier;
use HTMLPurifier_Config;
use InvalidArgumentException;
use Stevebauman\Purify\Definitions\Html5Definition;

/**
 * 生态富文本净化（ADR-0001 §3 G1-D06 / §4.3 / §4.7 / G1-07）。
 *
 * 净化分三步，缺一不可：
 *   1. **输入预处理**：只保留 `data-media-id` 命中 `$allowedMediaIds` 的图片；
 *      合法图片写入临时 `src=https://media.invalid/{id}`，让 HTMLPurifier
 *      在 `URI.DisableExternalResources=true` 下仍保留 `<img>`；非法图片整条删除；
 *   2. **HTMLPurifier**：`HTML.Allowed` 只含注册表白名单；只允许 http/https；
 *      禁止外部资源；`Cache.DefinitionImpl=null` 保证每次按当前定义执行；
 *   3. **输出后处理**（再跑一遍 DOM，防御 Purifier 版本差异）：
 *      · `img` 只保留 `data-media-id/alt/title/width/height`，删除 `src/srcset`；
 *      · `a` 的 `href` 必须是 http/https，否则拆掉链接保留文字；
 *        合法链接统一 `rel="noopener noreferrer"`；
 *      · script/style/iframe/form/object/embed/svg/math 与 `on*` 属性一律移除。
 *
 * 为什么图片不在 HTMLPurifier 里直接放行：
 *   `<img>` 的 `src` 是 HTMLPurifier 的必填属性；禁用外部资源后 `src` 被
 *   剥离，整个 `<img>` 会被丢弃。媒体真相源是 `ecosystem_media`，最终
 *   渲染必须按 `data-media-id` 走受控代理，不能信任任何 `src`。
 *
 * 空输入返回 NULL；超长输入直接拒绝，避免截断后产生半个标签。
 */
class RichTextSanitizer
{
    /**
     * 需要显式声明属性白名单的元素。其余元素只放行标签本身。
     *
     * @var array<string, array<int, string>>
     */
    private const ELEMENT_ATTRIBUTES = [
        'a'   => ['href', 'title', 'rel'],
        'img' => ['data-media-id', 'src', 'alt', 'title', 'width', 'height'],
    ];

    /**
     * 无论 HTMLPurifier 如何配置，输出阶段都必须消失的标签。
     *
     * @var array<int, string>
     */
    private const FORBIDDEN_ELEMENTS = [
        'script',
        'style',
        'iframe',
        'form',
        'object',
        'embed',
        'svg',
        'math',
    ];

    /**
     * 默认允许标签；配置缺失时兜底，正常路径以 config 为准。
     *
     * @var array<int, string>
     */
    private const DEFAULT_ALLOWED_TAGS = [
        'p',
        'br',
        'strong',
        'em',
        'u',
        's',
        'blockquote',
        'ul',
        'ol',
        'li',
        'a',
        'img',
        'figure',
        'figcaption',
        'table',
        'thead',
        'tbody',
        'tr',
        'th',
        'td',
        'code',
        'pre',
        'hr',
    ];

    private const ROOT_ID = 'g1-07-rich-text-root';

    private HTMLPurifier $purifier;

    private string $version;

    private int $maxInputChars;

    private string $mediaHost;

    public function __construct()
    {
        $this->version = (string) config('ecosystem_rich_text.sanitizer_version', 'g1-07-v1');
        $this->maxInputChars = (int) config('ecosystem_rich_text.max_input_chars', 200000);
        $this->mediaHost = (string) config('ecosystem_rich_text.media_placeholder_host', 'media.invalid');

        $this->purifier = $this->makePurifier();
    }

    /**
     * 净化一段富文本。
     *
     * @param  array<int, int|string>  $allowedMediaIds  本 revision 已审核的媒体 ID
     *
     * @throws InvalidArgumentException 输入超过配置上限
     */
    public function sanitize(?string $html, array $allowedMediaIds = []): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        if ($this->maxInputChars > 0 && mb_strlen($html) > $this->maxInputChars) {
            throw new InvalidArgumentException(sprintf(
                '富文本长度 %d 超过上限 %d，已拒绝入库。',
                mb_strlen($html),
                $this->maxInputChars,
            ));
        }

        $allowed = $this->normalizeMediaIds($allowedMediaIds);

        $prepared = $this->prepareImages($html, $allowed);

        if (trim($prepared) === '') {
            return null;
        }

        $purified = $this->purifier->purify($prepared);

        $result = $this->postProcess($purified, $allowed);

        return trim($result) === '' ? null : $result;
    }

    /**
     * 当前净化规则版本，写入 revision.sanitizer_version。
     */
    public function version(): string
    {
        return $this->version;
    }

    private function makePurifier(): HTMLPurifier
    {
        $config = HTMLPurifier_Config::create([
            'Core.Encoding'                => 'UTF-8',
            'HTML.Doctype'                 => 'HTML 4.01 Transitional',
            'HTML.Allowed'                 => $this->allowedHtml(),
            'HTML.ForbiddenElements'       => implode(',', self::FORBIDDEN_ELEMENTS),
            // ADR G1-D06：只允许 http / https。
            'URI.AllowedSchemes'           => [
                'http'  => true,
                'https' => true,
            ],
            // 禁止任何外部资源；临时 src 走到 media.invalid 才被保留。
            'URI.DisableExternalResources' => true,
            'URI.Host'                     => $this->mediaHost,
            // 不落定义缓存：白名单变化必须立即生效，避免读到旧序列化定义。
            'Cache.DefinitionImpl'         => null,
            'AutoFormat.AutoParagraph'     => false,
            'AutoFormat.RemoveEmpty'       => false,
        ]);

        $config->set('HTML.DefinitionID', 'g1-07-rich-text');
        $config->set('HTML.DefinitionRev', 1);

        $definition = $config->maybeGetRawHTMLDefinition();

        if ($definition !== null) {
            // figure / figcaption / u / s 不在 HTML 4.01 核心模块里。
            Html5Definition::apply($definition);
            // HTMLPurifier 不原生支持 data-* 属性，显式注册。
            $definition->addAttribute('img', 'data-media-id', 'Number');
        }

        return new HTMLPurifier($config);
    }

    /**
     * 由注册表生成 HTML.Allowed：只含白名单标签，附加元素级属性限制。
     */
    private function allowedHtml(): string
    {
        $tags = (array) config('ecosystem_rich_text.allowed_tags', self::DEFAULT_ALLOWED_TAGS);
        $allowed = [];

        foreach ($tags as $tag) {
            if (! is_string($tag) || $tag === '') {
                continue;
            }

            $allowed[] = isset(self::ELEMENT_ATTRIBUTES[$tag])
                ? $tag.'['.implode('|', self::ELEMENT_ATTRIBUTES[$tag]).']'
                : $tag;
        }

        if ($allowed === []) {
            $allowed = self::DEFAULT_ALLOWED_TAGS;
        }

        return implode(',', $allowed);
    }

    /**
     * @param  array<int, int|string>  $allowedMediaIds
     * @return array<int, int>
     */
    private function normalizeMediaIds(array $allowedMediaIds): array
    {
        $normalized = [];

        foreach ($allowedMediaIds as $id) {
            if (is_int($id) && $id > 0) {
                $normalized[] = $id;

                continue;
            }

            if (is_string($id) && preg_match('/^[1-9]\d*$/', $id) === 1) {
                $normalized[] = (int) $id;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * 预处理图片：非法 `data-media-id` 删除整条 img，合法图片写临时 src。
     *
     * @param  array<int, int>  $allowedMediaIds
     */
    private function prepareImages(string $html, array $allowedMediaIds): string
    {
        $dom = $this->loadFragment($html);
        $xpath = new DOMXPath($dom);

        $images = [];

        foreach ($xpath->query('//img') as $image) {
            $images[] = $image;
        }

        foreach ($images as $image) {
            $mediaId = trim($image->getAttribute('data-media-id'));

            if (! $this->isAllowedMediaId($mediaId, $allowedMediaIds)) {
                $image->parentNode?->removeChild($image);

                continue;
            }

            $image->setAttribute('data-media-id', (string) (int) $mediaId);
            $image->setAttribute('src', sprintf('https://%s/%d', $this->mediaHost, (int) $mediaId));
            $image->removeAttribute('srcset');
        }

        return $this->innerHtml($dom);
    }

    /**
     * 输出后处理：按最终白名单重建属性，防御 Purifier 版本差异。
     *
     * @param  array<int, int>  $allowedMediaIds
     */
    private function postProcess(string $html, array $allowedMediaIds): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = $this->loadFragment($html);
        $xpath = new DOMXPath($dom);

        // 快照节点，避免边遍历边删除导致 NodeList 跳过元素。
        $elements = [];

        foreach ($xpath->query('//*') as $element) {
            $elements[] = $element;
        }

        foreach ($elements as $element) {
            if (! $element instanceof DOMElement || $element->parentNode === null) {
                continue;
            }

            $tag = strtolower($element->tagName);

            if (in_array($tag, self::FORBIDDEN_ELEMENTS, true)) {
                $element->parentNode->removeChild($element);

                continue;
            }

            foreach (iterator_to_array($element->attributes) as $attribute) {
                if (str_starts_with(strtolower($attribute->name), 'on')) {
                    $element->removeAttribute($attribute->name);
                }
            }
        }

        $this->normalizeImages($xpath, $allowedMediaIds);
        $this->normalizeLinks($xpath);

        return $this->innerHtml($dom);
    }

    /**
     * @param  array<int, int>  $allowedMediaIds
     */
    private function normalizeImages(DOMXPath $xpath, array $allowedMediaIds): void
    {
        $images = [];

        foreach ($xpath->query('//img') as $image) {
            $images[] = $image;
        }

        foreach ($images as $image) {
            if (! $image instanceof DOMElement || $image->parentNode === null) {
                continue;
            }

            $mediaId = trim($image->getAttribute('data-media-id'));

            if (! $this->isAllowedMediaId($mediaId, $allowedMediaIds)) {
                $image->parentNode->removeChild($image);

                continue;
            }

            $keep = ['data-media-id' => (string) (int) $mediaId];

            foreach (['alt', 'title', 'width', 'height'] as $attribute) {
                if ($image->hasAttribute($attribute)) {
                    $keep[$attribute] = $image->getAttribute($attribute);
                }
            }

            // 先清空全部属性，再写回白名单，src / srcset / style / on* 都不会留下。
            while ($image->attributes->length > 0) {
                $image->removeAttribute($image->attributes->item(0)->name);
            }

            foreach ($keep as $attribute => $value) {
                $image->setAttribute($attribute, $value);
            }
        }
    }

    private function normalizeLinks(DOMXPath $xpath): void
    {
        $links = [];

        foreach ($xpath->query('//a') as $link) {
            $links[] = $link;
        }

        foreach ($links as $link) {
            if (! $link instanceof DOMElement || $link->parentNode === null) {
                continue;
            }

            $href = trim($link->getAttribute('href'));

            // 只允许绝对 http(s)；相对链接、协议相对链接、javascript: 等一律拆链。
            if (preg_match('#^https?://#i', $href) !== 1) {
                $this->unwrap($link);

                continue;
            }

            $title = $link->hasAttribute('title') ? $link->getAttribute('title') : null;

            while ($link->attributes->length > 0) {
                $link->removeAttribute($link->attributes->item(0)->name);
            }

            $link->setAttribute('href', $href);

            if ($title !== null) {
                $link->setAttribute('title', $title);
            }

            $link->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /**
     * 拆掉标签但保留子节点（危险链接不应吞掉正文）。
     */
    private function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    /**
     * @param  array<int, int>  $allowedMediaIds
     */
    private function isAllowedMediaId(string $mediaId, array $allowedMediaIds): bool
    {
        return preg_match('/^[1-9]\d*$/', $mediaId) === 1
            && in_array((int) $mediaId, $allowedMediaIds, true);
    }

    private function loadFragment(string $html): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);

        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="'.self::ROOT_ID.'">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    private function innerHtml(DOMDocument $dom): string
    {
        $xpath = new DOMXPath($dom);
        $root = $xpath->query('//*[@id="'.self::ROOT_ID.'"]')->item(0);

        if (! $root instanceof DOMNode) {
            return '';
        }

        $html = '';

        foreach ($root->childNodes as $child) {
            $html .= $dom->saveHTML($child);
        }

        return $html;
    }
}
