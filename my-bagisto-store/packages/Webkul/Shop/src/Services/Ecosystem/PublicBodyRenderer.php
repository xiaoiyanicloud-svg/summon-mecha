<?php

namespace Webkul\Shop\Services\Ecosystem;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * 公开正文渲染（ADR-0001 G1-D08 / 规划 §6.2 / G1-10）。
 *
 * 输入是**已经过 RichTextSanitizer 的** revision HTML；本类只做最后一步：
 * 把 `<img data-media-id="N">` 改写为受控媒体路由 URL。
 *
 * 约束：
 *   · 只允许当前 revision 的「已批准引用 + 已批准媒体」集合内的 ID；
 *     其余 `<img>` 整条删除（未知 ID 不得变成破图或远程 URL）；
 *   · 无论输入里是否残留 `src` / `srcset`，输出都只写我们生成的 `src`；
 *   · 不引入任何新的标签或协议，链接仍保持净化器后的结果；
 *   · 纯字符串输入 / 输出，不接触模型，避免把内部字段带进 DTO。
 */
class PublicBodyRenderer
{
    private const ROOT_ID = 'g1-10-public-body-root';

    /** img 输出允许保留的属性（其余一律删除）。 */
    private const IMG_ATTRIBUTES = [
        'data-media-id',
        'alt',
        'title',
        'width',
        'height',
        'src',
    ];

    /**
     * @param  array<int, int|string>  $allowedMediaIds  当前 revision 可公开的媒体 ID
     * @param  callable(int): string  $mediaUrl  由调用方提供受控路由 URL（不得含 revision/存储路径）
     */
    public function render(?string $html, array $allowedMediaIds, callable $mediaUrl): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $allowed = $this->normalizeMediaIds($allowedMediaIds);

        $dom = $this->loadFragment($html);
        $xpath = new DOMXPath($dom);

        $images = [];

        foreach ($xpath->query('//img') as $image) {
            $images[] = $image;
        }

        foreach ($images as $image) {
            if (! $image instanceof DOMElement || $image->parentNode === null) {
                continue;
            }

            $rawId = trim($image->getAttribute('data-media-id'));
            $mediaId = preg_match('/^[1-9]\d*$/', $rawId) === 1 ? (int) $rawId : 0;

            if ($mediaId === 0 || ! in_array($mediaId, $allowed, true)) {
                // 未批准 / 未知 ID：整条删除，绝不猜测或回退到原始 src。
                $image->parentNode->removeChild($image);

                continue;
            }

            $keep = [
                'data-media-id' => (string) $mediaId,
                'src'           => (string) $mediaUrl($mediaId),
            ];

            foreach (['alt', 'title', 'width', 'height'] as $attribute) {
                if ($image->hasAttribute($attribute)) {
                    $keep[$attribute] = $image->getAttribute($attribute);
                }
            }

            while ($image->attributes->length > 0) {
                $image->removeAttribute($image->attributes->item(0)->name);
            }

            foreach (self::IMG_ATTRIBUTES as $attribute) {
                if (isset($keep[$attribute])) {
                    $image->setAttribute($attribute, $keep[$attribute]);
                }
            }
        }

        $result = $this->innerHtml($dom);

        return trim($result) === '' ? null : $result;
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    private function normalizeMediaIds(array $ids): array
    {
        $normalized = [];

        foreach ($ids as $id) {
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
