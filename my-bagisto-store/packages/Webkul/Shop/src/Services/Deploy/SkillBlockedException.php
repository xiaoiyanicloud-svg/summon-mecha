<?php

namespace Webkul\Shop\Services\Deploy;

/**
 * 技能已被熔断,禁止新下发 —— v3.4 步骤 8(D9 熔断 kill switch)。
 *
 * 为什么单独一个异常类:熔断是安全动作,调用方(DeployController)必须能把它与
 * "网关挂了 / 参数错" 这类异常区分开,返回明确的 4xx(而不是 500)。
 */
class SkillBlockedException extends \RuntimeException
{
}
