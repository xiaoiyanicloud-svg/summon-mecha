<?php

namespace Webkul\Shop\Services\Deploy;

use Illuminate\Support\Facades\Cache;
use Webkul\Shop\Models\DeviceBinding;

/**
 * 模拟 Agent 网关 —— v3.4 步骤 8 开发替身。
 *
 * 把下发的指令存进缓存,供"模拟设备"拉取/回报联调。
 * 真传输(MQTT/EMQX 或厂商云)到位后,新增一个实现即可,业务零改动。
 */
class MockAgentGateway implements AgentGateway
{
    protected const QUEUE_KEY = 'agent_cmd_queue:';

    public function key(): string
    {
        return 'mock';
    }

    public function dispatch(string $did, array $command): array
    {
        // 追加到该设备的待执行队列(模拟"下发到了设备")
        $key = self::QUEUE_KEY.$did;
        $queue = Cache::get($key, []);
        $queue[] = $command;
        Cache::put($key, $queue, 3600);

        return ['ok' => true, 'message' => '已入队(模拟网关)'];
    }

    /**
     * ⚠ 读时判断，不看 `online_status` 那个粘滞列（详见 `DeviceBinding` 的类注释）。
     *   模拟网关与真传输必须用**同一套在线判据** —— 否则联调时"能下发"，
     *   换到真网关就下不去了。
     */
    public function isOnline(string $did): bool
    {
        return DeviceBinding::query()->onlineNow()->where('did', $did)->exists();
    }

    /**
     * 模拟设备拉取待执行指令(联调用)。
     */
    public function pull(string $did): array
    {
        $key = self::QUEUE_KEY.$did;
        $queue = Cache::get($key, []);
        Cache::forget($key);

        return $queue;
    }
}
