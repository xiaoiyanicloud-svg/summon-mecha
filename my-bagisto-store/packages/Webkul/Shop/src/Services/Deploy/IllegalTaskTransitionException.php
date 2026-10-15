<?php

namespace Webkul\Shop\Services\Deploy;

/**
 * 设备回报与当前状态**不构成合法流转** —— 服务端拒绝这次回报(P9)。
 *
 * 为什么必须是个显式异常、而不是"返回 false 让调用方自己去猜":
 *   `DeployTaskService::transition()` 用条件更新做裁决,命中 0 行只能回答"没生效",
 *   回答不了"是竞态落败、还是**根本不该发生**"。机器消费者(设备端 Agent)需要后者 ——
 *   它得知道"我这次报 failed 被拒了",否则端侧认为报成功、云侧状态没动,
 *   两边**永久分叉**且服务端不留任何痕迹(此前正是如此,还返 200 'ok')。
 *
 * ⚠ 与"状态已是目标态"区分开:对已 success 的任务**再报一次 done** 是幂等短路
 *   (`transition()` 返回 true),不会走到这里。走到这里的都是真的不该发生
 *   (例如 success 之后又报 failed)。
 */
class IllegalTaskTransitionException extends \RuntimeException
{
    public function __construct(
        public readonly string $taskNo,
        /** 服务端当前状态 */
        public readonly string $from,
        /** 设备声称要去的状态 */
        public readonly string $to,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : sprintf(
            '任务 %s 当前为 %s,不接受 %s 回报。',
            $taskNo,
            $from,
            $to,
        ));
    }
}
