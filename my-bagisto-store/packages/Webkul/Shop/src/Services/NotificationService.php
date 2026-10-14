<?php

namespace Webkul\Shop\Services;

use Webkul\Shop\Models\Notification;

/**
 * 通知服务(v3.4 步骤 4)。
 *
 * 本次实现「站内信」(inapp);微信订阅消息(subscribe_msg)待小程序模板配置后接入——
 * 但落库与"未读"状态现在就通,前端"通知中心"可以先用。
 */
class NotificationService
{
    /**
     * 给一个接收者发通知。落库 + 标记已发送。
     *
     * @param  string  $recipientType  customer / developer / admin
     */
    public function send(
        string $recipientType,
        int $recipientId,
        string $type,
        string $title,
        string $content = '',
        array $payload = [],
        string $channel = Notification::CHANNEL_INAPP,
    ): Notification {
        return Notification::create([
            'recipient_type' => $recipientType,
            'recipient_id'   => $recipientId,
            'type'           => $type,
            'title'          => $title,
            'content'        => $content,
            'payload'        => $payload,
            'channel'        => $channel,
            'sent_at'        => now(),
        ]);
    }

    /**
     * 给客户发通知的便捷方法。
     */
    public function toCustomer(int $customerId, string $type, string $title, string $content = '', array $payload = []): Notification
    {
        return $this->send('customer', $customerId, $type, $title, $content, $payload);
    }
}
