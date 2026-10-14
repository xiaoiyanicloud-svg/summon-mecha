<?php

namespace Webkul\Shop\Services\SkillImport;

/**
 * 技能导入器接口 —— v3.4 步骤 6(计划 §4.4.3)。
 *
 * 把一个**他平台/通用格式**的输入,映射成**平台中立 manifest**(SkillManifest)。
 * 无法映射的部分写进 warnings,并**自动收紧**兼容性/通道(不允许"导入后能下发到不兼容设备")。
 */
interface SkillImporter
{
    /**
     * 识别:能否处理这份输入。
     */
    public function supports(array $input): bool;

    /**
     * 导入:产出中立 manifest + 警告 + 自动收紧的约束。
     *
     * @return ImportResult
     */
    public function import(array $input): ImportResult;
}
