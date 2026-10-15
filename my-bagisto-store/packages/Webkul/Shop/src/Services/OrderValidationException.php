<?php

namespace Webkul\Shop\Services;

/**
 * 结账校验失败 —— **用户可纠正**的业务错误,不是服务端故障。
 *
 * ── 为什么单独一个异常类(M7)──
 *   结账前校验(`OnepageController::validateOrder()`)此前把「账号被停用 / 没选地址 /
 *   没选支付方式」这类错误一律 `throw new \Exception(...)`,控制器再 catch 成 **500**。
 *   后果不是"少个提示":前端把 500 当**服务端故障**,于是重试、上报、进客服工单 ——
 *   而用户其实只要选个地址就能过。
 *
 *   所以要把"校验失败"与"真出事了"分开:
 *     · 本异常 → 明确的 4xx + 错误码(见 ApiErrorCode)
 *     · 其它任何异常 → 继续向上抛,交给全局异常处理(500)
 *
 *   与 `Services\Deploy\SkillBlockedException` 同一套理由:调用方必须能把
 *   "用户能自己修的"和"服务端挂了"区分开。
 *
 * ⚠ 携带的 `$status` 由**抛出处**决定,不在这里按文案猜:账号状态类用 409(与当前状态冲突),
 *   缺字段类用 422(请求本身不完整)。
 */
class OrderValidationException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
