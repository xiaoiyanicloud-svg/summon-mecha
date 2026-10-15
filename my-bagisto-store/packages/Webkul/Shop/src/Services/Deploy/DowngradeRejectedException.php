<?php

namespace Webkul\Shop\Services\Deploy;

/**
 * 拒绝一次降级下发(§4.22 F1 / B-13)。
 *
 * 与 `SkillBlockedException`(熔断)同类:create() 在建任务前抛,由控制器映射成明确的 4xx,
 * 而不是"先落库再失败"。回滚(OP_ROLLBACK)是**显式**的向下动作,不走本闸。
 */
class DowngradeRejectedException extends \RuntimeException
{
    public function __construct(
        public readonly string $installedVersion,
        public readonly string $targetVersion,
    ) {
        parent::__construct(
            "拒绝降级:目标版本 {$targetVersion} 低于本机已装 {$installedVersion}(§4.22 F1 版本单调)。"
        );
    }
}
