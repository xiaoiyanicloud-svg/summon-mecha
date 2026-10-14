<?php

declare(strict_types=1);

namespace Webkul\Shop\Services\SkillDelivery;

use InvalidArgumentException;

/**
 * 交付通道解析器 —— 按 config/skill_delivery.php 的注册表把 key 解析成实现。
 *
 * 解析结果按 key 缓存,避免同一次请求里重复构造。
 */
class SkillDeliveryManager
{
    /**
     * 已解析的通道实例。
     *
     * @var array<string, DeliveryChannel>
     */
    protected array $resolved = [];

    /**
     * 取默认通道。
     */
    public function default(): DeliveryChannel
    {
        return $this->channel(config('skill_delivery.default'));
    }

    /**
     * 按 key 取通道;传 null 则取默认。
     */
    public function channel(?string $key = null): DeliveryChannel
    {
        $key = $key ?: (string) config('skill_delivery.default');

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $class = config("skill_delivery.channels.{$key}.class");

        if (! $class || ! class_exists($class)) {
            throw new InvalidArgumentException("技能交付通道 [{$key}] 未注册或类不存在。");
        }

        $channel = app($class);

        if (! $channel instanceof DeliveryChannel) {
            throw new InvalidArgumentException("技能交付通道 [{$key}] 必须实现 DeliveryChannel 接口。");
        }

        return $this->resolved[$key] = $channel;
    }

    /**
     * 所有已注册的通道 key。
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys((array) config('skill_delivery.channels', []));
    }

    /**
     * 通道的中文名;key 未注册时回退成 key 本身,避免展示层炸掉。
     */
    public function label(?string $key): string
    {
        try {
            return $this->channel($key)->label();
        } catch (InvalidArgumentException) {
            return (string) $key;
        }
    }
}
