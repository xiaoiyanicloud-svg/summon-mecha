<?php

namespace Webkul\Shop\Services\SkillDelivery;

use RuntimeException;

/**
 * 一个版本存在多个可下发的文件类交付物，且调用方没有指定要哪一个。
 *
 * 这不是"没有包"（那是 null / SKILL_ASSET_NOT_FOUND），而是**无法唯一确定**：
 * 平台若擅自取排序第一个，就会把"这一次到底发了哪份字节"交给排序实现决定，
 * 端侧复算的 sha256 与任务声明随时可能对不上。此处必须 fail-closed。
 */
class SkillAssetAmbiguousException extends RuntimeException
{
    /** @param array<int, int> $assetIds */
    public function __construct(
        public int $versionId,
        public array $assetIds = [],
    ) {
        parent::__construct('存在多个文件类交付物，无法唯一确定要下发的包。');
    }
}
