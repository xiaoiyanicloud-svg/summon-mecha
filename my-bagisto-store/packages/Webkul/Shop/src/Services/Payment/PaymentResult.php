<?php

namespace Webkul\Shop\Services\Payment;

/**
 * 支付结果值对象。
 */
class PaymentResult
{
    public function __construct(
        public bool $paid,
        public string $outTradeNo,
        public ?string $transactionId = null,
        public float $amount = 0.0,
        public string $message = '',
        public array $raw = [],
    ) {}

    public static function paid(string $outTradeNo, float $amount, ?string $transactionId = null, array $raw = []): self
    {
        return new self(true, $outTradeNo, $transactionId, $amount, '支付成功', $raw);
    }

    public static function failed(string $outTradeNo, string $message, array $raw = []): self
    {
        return new self(false, $outTradeNo, null, 0.0, $message, $raw);
    }

    public function toArray(): array
    {
        return [
            'paid'           => $this->paid,
            'out_trade_no'   => $this->outTradeNo,
            'transaction_id' => $this->transactionId,
            'amount'         => $this->amount,
            'message'        => $this->message,
        ];
    }
}
