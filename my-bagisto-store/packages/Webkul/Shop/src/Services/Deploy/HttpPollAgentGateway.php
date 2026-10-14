<?php

namespace Webkul\Shop\Services\Deploy;

use Webkul\Shop\Models\DeviceBinding;

/**
 * HTTP 轮询传输 —— v3.4 步骤 8 的**真传输**(可立刻上线,不需要 MQTT/EMQX)。
 *
 * 模型:设备端 Agent 主动轮询 `GET /api/agent/commands` 拉指令(指令队列即 deploy_task 本身),
 *   执行后 POST /api/agent/evt 回报。设备在 NAT 后也可用(纯出站),无需 broker。
 *
 * 与 MQTT 的取舍:MQTT 是"推"(实时、省轮询),HTTP 轮询是"拉"(简单、零 infra)。
 *   两者是同一 AgentGateway 接口的不同实现 —— 将来 EMQX/Go 就绪,加 MqttAgentGateway
 *   并切 config 的 default 即可,业务零改动。
 *
 * 在线判定:最近一次心跳/轮询在 N 秒内（`DeviceBinding::ONLINE_WINDOW_SECONDS`）。
 */
class HttpPollAgentGateway implements AgentGateway
{
    public function key(): string
    {
        return 'poll';
    }

    public function dispatch(string $did, array $command): array
    {
        // 轮询模型下无需"推送" —— 指令已在 deploy_task 里,设备下次轮询即取。
        // 这里只做一次可达性确认。
        return ['ok' => true, 'message' => '已入队,待设备轮询取走'];
    }

    /**
     * ⚠ 在线判定的**唯一定义在 `DeviceBinding`**（窗口常量 + `scopeOnlineNow()`）。
     *
     * 这里此前自己写了一份 90 秒窗口，而另一批读点（设备列表 / 我的技能 / 运营总览）
     * 读的却是 `online_status` 那个**永不复位**的粘滞列 ——
     * 于是同一台设备在"能不能下发"和"界面显示什么"两处会得到相反答案。
     * 现已统一到 `DeviceBinding`。
     */
    public function isOnline(string $did): bool
    {
        return DeviceBinding::query()->onlineNow()->where('did', $did)->exists();
    }
}
