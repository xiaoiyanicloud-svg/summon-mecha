<?php

namespace Webkul\Shop\Services\SkillImport;

/**
 * 导入结果值对象。
 */
class ImportResult
{
    public function __construct(
        public bool $ok,
        public array $manifest = [],       // 中立 manifest
        public array $warnings = [],       // 无法自动映射的部分
        public array $lockedModels = [],   // 自动锁定的机型(如宇树容器只跑宇树)
        public array $lockedChannels = [], // 自动锁定的通道
        public ?string $error = null,
    ) {}

    public static function ok(array $manifest, array $warnings = [], array $lockedModels = [], array $lockedChannels = []): self
    {
        return new self(true, $manifest, $warnings, $lockedModels, $lockedChannels);
    }

    public static function fail(string $error): self
    {
        return new self(false, [], [], [], [], $error);
    }
}
