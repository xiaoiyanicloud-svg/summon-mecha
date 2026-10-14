<?php

namespace Webkul\Shop\Services\ContentSafety;

use RuntimeException;

/**
 * 内容安全驱动的解析器（对齐 SkillDeliveryManager / PaymentManager 的注册表套路）。
 */
class ContentSafetyManager
{
    protected ?ContentScanner $resolved = null;

    public function scanner(): ContentScanner
    {
        if ($this->resolved) {
            return $this->resolved;
        }

        $key = (string) config('content_safety.driver', 'local');
        $driver = config("content_safety.drivers.{$key}");

        if (! $driver || empty($driver['class'])) {
            // 显式报错，不要静默退回 local —— 配错了要看得见
            throw new RuntimeException("内容安全驱动 [{$key}] 未注册（见 config/content_safety.php）。");
        }

        $class = $driver['class'];

        if (! class_exists($class)) {
            throw new RuntimeException("内容安全驱动 [{$key}] 的类不存在：{$class}");
        }

        return $this->resolved = new $class($driver);
    }

    /**
     * 当前配置的驱动名。
     *
     * 给留痕用：事后要能回答"当时跑的是哪个驱动"，否则一次词表事故
     * 与一次驱动切换在记录里长得一模一样。
     */
    public function driver(): string
    {
        return (string) config('content_safety.driver', 'local');
    }

    /**
     * 词表/驱动不可用时是否拦截（见 config 的 on_missing_words）。
     */
    public function failsClosed(): bool
    {
        return config('content_safety.on_missing_words', 'fail') === 'fail';
    }
}
