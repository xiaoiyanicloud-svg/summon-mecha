<?php

namespace Webkul\Shop\Services\SkillCompile\Portable;

use RuntimeException;

/**
 * 源包安全拒绝（B4）。
 *
 * 携带**机器可读的拒绝码**，让编译结果能把"为什么没编"如实传到 HTTP 层，
 * 而不是笼统的"未找到源包"。码表与 `SourcePackageReader::*_CODE` 常量一一对应，
 * 新增一条拒绝路径时**必须先加常量再用**，禁止在异常里手写字符串码。
 */
class SourcePackageRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
