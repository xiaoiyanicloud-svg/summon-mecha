<?php

declare(strict_types=1);

namespace Webkul\Shop\Services\SkillDelivery;

/**
 * 一次交付尝试的结果。
 *
 * 刻意做成不可变值对象而不是抛异常:交付失败(比如"设备还没绑")在业务上是正常分支,
 * 不应该炸事务 —— 尤其监听器跑在建单事务里(见 Listeners/SkillDelivery.php)。
 */
final class DeliveryResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $message = '',
        public readonly array $data = [],
    ) {
    }

    /**
     * 交付成功。
     */
    public static function ok(string $message = '', array $data = []): self
    {
        return new self(true, $message, $data);
    }

    /**
     * 交付失败 —— 不是异常,是需要业务处理的正常状态(如尚未绑定设备)。
     */
    public static function fail(string $message, array $data = []): self
    {
        return new self(false, $message, $data);
    }

    /**
     * 转成数组,便于写进 skill_entitlements.meta 留痕。
     */
    public function toArray(): array
    {
        return [
            'ok'      => $this->ok,
            'message' => $this->message,
            'data'    => $this->data,
        ];
    }
}
