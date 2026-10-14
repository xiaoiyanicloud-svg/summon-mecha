<?php

namespace Webkul\Shop\Services\SkillCompile;

use Webkul\Shop\Models\SkillBuild;

/**
 * 载体构建器接口 —— v3.4 步骤 6(计划 §4.20 转换层)。
 *
 * 一个目标机型/形态一个实现。构建器**只负责把"中立定义 + 目标机型"变成该机型载体**,
 * 不含业务编排(编排在 SkillBuildService)。
 */
interface CarrierBuilder
{
    /**
     * 能否处理该技能形态(kind)。
     */
    public function supports(string $kind): bool;

    /**
     * 构建一个机型载体。
     *
     * @param  int  $skillVersionId  技能版本 id
     * @param  array  $manifest      中立清单(已解析)
     * @param  string  $targetModelCode  目标机型 model_code
     * @return BuildResult
     */
    public function build(int $skillVersionId, array $manifest, string $targetModelCode): BuildResult;
}
