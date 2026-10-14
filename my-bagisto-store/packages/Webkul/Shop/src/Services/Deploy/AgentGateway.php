<?php

namespace Webkul\Shop\Services\Deploy;

/**
 * Agent 下行/上行网关接口 —— v3.4 步骤 8。
 *
 * 抽象出这一层:传输方式(MQTT/EMQX、厂商云、HTTP 轮询)还没定/未就绪时,
 * 业务(状态机、签名、幂等)不受影响。用 MockGateway 跑通,真传输到位再加实现。
 *
 * 写法对齐项目既有套路(接口 + 配置注册表 + 容器解析)。
 */
interface AgentGateway
{
    public function key(): string;

    /**
     * 向设备下发一条指令(异步)。
     *
     * @param  array  $command  {task_id, operation, skill_id, version, package_url, package_hash, signature, ts}
     * @return array{ok: bool, message: string}
     */
    public function dispatch(string $did, array $command): array;

    /**
     * 设备是否在线(用于 pending_online 判定)。
     */
    public function isOnline(string $did): bool;
}
