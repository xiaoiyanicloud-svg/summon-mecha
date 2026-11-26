"""召唤机甲 Agent CLI 启动入口"""

import argparse
import json
import os
import sys
import time
from typing import Optional

from zhjj_agent import __version__
from zhjj_agent.adapters.registry import create_adapter as create_registered_adapter
from zhjj_agent.client import AgentClient
from zhjj_agent.desired_state import DesiredStateTracker
from zhjj_agent.entitlement import EntitlementGuard
from zhjj_agent.health import HealthSampler
from zhjj_agent.metrics import AgentMetrics
from zhjj_agent.storage import Storage
from zhjj_agent.task_manager import TaskManager
from zhjj_agent.telemetry import configure_telemetry, log_event
from zhjj_agent.update import resolve_reported_version


def create_adapter(model_code: str, install_dir: Optional[str] = None):
    return create_registered_adapter(model_code, install_dir=install_dir)


def report_metrics(
    client: AgentClient,
    metrics: AgentMetrics,
    agent_version: str,
    capability_snapshot=None,
    health: Optional[dict] = None,
):
    """发一次心跳，并把指标增量带上（A3）。

    确认语义：
      · 心跳成功 → ack 该批（本地 pending 转 reported，之后不再重复上报）
      · 平台明确说"这批键不合法"（4xx/VALIDATION_ERROR）→ **丢弃**该批。
        键写错了重试一万次也一样错，留着只会堵死后面所有批次。
      · 其它失败（网络/5xx）→ 不 ack 不丢，下一轮用**同一个 batch_id** 重发。
    """
    batch = metrics.next_batch()
    # gauge 每轮都报（覆盖语义）；增量计数只在有 pending 时带上批次号。
    metrics_payload = {"gauges": metrics.gauges()}
    if batch:
        metrics_payload.update({"batch_id": batch[0], "counters": batch[1]})

    ok, resp = client.heartbeat(agent_version, capability_snapshot, metrics=metrics_payload, health=health)

    if batch:
        if ok:
            metrics.ack_batch(batch[0])
        elif (resp or {}).get("code") == "VALIDATION_ERROR":
            metrics.drop_batch(batch[0])

    return ok, resp


def run(config_path: str, once: bool = False, adapter_override: Optional[str] = None):
    configure_telemetry()
    cfg_abs = os.path.abspath(config_path)
    install_dir = os.path.dirname(cfg_abs)

    with open(cfg_abs, "r", encoding="utf-8") as f:
        config = json.load(f)

    server_url = config["server_url"].rstrip("/")
    did = config["did"]
    device_secret = config.get("device_secret")
    privkey_pem = config.get("private_key_pem")
    agent_key = config.get("agent_key")

    pubkey_pem = config.get("public_key_inline")
    if not pubkey_pem and config.get("public_key"):
        pub_path = os.path.join(install_dir, config["public_key"]) if not os.path.isabs(config["public_key"]) else config["public_key"]
        if os.path.isfile(pub_path):
            with open(pub_path, "r", encoding="utf-8") as pf:
                pubkey_pem = pf.read()

    if not pubkey_pem:
        raise ValueError("缺少平台签名验签公钥 (public_key 或 public_key_inline)")

    model_code = adapter_override or config.get("model_code")
    if not model_code:
        raise ValueError("配置中缺少必需的 model_code 参数")
    adapter = create_adapter(model_code, install_dir=install_dir)
    storage = Storage(install_dir)
    metrics = AgentMetrics(storage.db_path)
    health_sampler = HealthSampler(install_dir, metrics=metrics)
    client = AgentClient(
        server_url=server_url,
        did=did,
        device_secret=device_secret,
        private_key_pem=privkey_pem,
        agent_key=agent_key
    )

    manager = TaskManager(
        install_dir=install_dir,
        storage=storage,
        client=client,
        adapter=adapter,
        pubkey_pem=pubkey_pem,
        metrics=metrics,
        entitlement_guard=EntitlementGuard(client, storage),
    )

    poll_interval = int(config.get("poll_interval", 5))
    desired_interval = max(30, int(config.get("desired_state_interval", 300)))
    desired_tracker = DesiredStateTracker(client, storage, logger=log_event)
    # 由 launcher 启动时，环境变量里带着"真正在跑的版本"；直接跑这份代码时行为不变。
    agent_version = resolve_reported_version(__version__)
    print(f"[zhjj-agent] 启动 v{agent_version} did={did} adapter={model_code} dir={install_dir}")

    # A4：启动自愈 —— 计重启次数 + 清理上次崩溃留下的 staging 残骸（必须在 recovery 之前）
    recovery = manager.mark_agent_start()

    # 启动时执行崩溃恢复与对账
    manager.resume_recovery()

    # 发送首联心跳（带指标增量）
    report_metrics(client, metrics, agent_version, adapter.health(), health=health_sampler.sample())

    # A4：启动时对齐一次声明式目标状态（只读；失败不阻塞主链路）
    try:
        desired_tracker.refresh()
    except Exception as e:
        print(f"[zhjj-agent] 目标状态对齐异常 (继续运行): {e}")

    if once:
        count = manager.poll_once()
        print(f"[zhjj-agent] 单次轮询结束，处理指令数: {count}")
        return

    last_desired_check = time.time()

    while True:
        try:
            manager.poll_once()
        except Exception as e:
            print(f"[zhjj-agent] 轮询异常 (继续等待): {e}")

        # 每轮带一次指标增量：心跳本身就是"我还活着"，顺路把计数送上去，
        # 不额外开一条上报通道（设备端不要两个状态源）。
        try:
            report_metrics(client, metrics, agent_version, adapter.health(), health=health_sampler.sample())
        except Exception as e:
            print(f"[zhjj-agent] 指标上报异常 (继续等待): {e}")

        # A4：按间隔对齐目标状态（默认 300s；失败只记日志，不影响心跳/轮询）
        if time.time() - last_desired_check >= desired_interval:
            try:
                desired_tracker.refresh()
            except Exception as e:
                print(f"[zhjj-agent] 目标状态对齐异常 (继续等待): {e}")
            last_desired_check = time.time()

        time.sleep(poll_interval)


def main():
    parser = argparse.ArgumentParser(description="召唤机甲 · 设备端 Agent (zhjj_agent)")
    parser.add_argument("--config", help="Agent 配置文件 config.json 路径（--self-check 时可省略）")
    parser.add_argument("--once", action="store_true", help="单次轮询后退出 (测试/调试用)")
    parser.add_argument("--adapter", choices=["mock", "unitree_g1", "unitree_r1", "agibot_x2", "agibot_a3", "engineai_t800"], help="强制指定机型适配器")
    parser.add_argument("--install-dir", help="安装目录（自检用；默认取 --config 所在目录）")
    parser.add_argument("--self-check", action="store_true", help="启动自检：校验当前激活版本可被真实 import（无副作用）")
    parser.add_argument("--version", action="version", version=f"%(prog)s {__version__}")
    args = parser.parse_args()

    if args.self_check:
        from zhjj_agent.update import self_check_cli

        install_dir = args.install_dir or os.environ.get("ZHJJ_AGENT_HOME")
        if not install_dir and args.config:
            install_dir = os.path.dirname(os.path.abspath(args.config))
        sys.exit(self_check_cli(os.path.abspath(install_dir or os.getcwd())))

    if not args.config:
        parser.error("--config 是必需的（--self-check 除外）")

    run(args.config, once=args.once, adapter_override=args.adapter)


if __name__ == "__main__":
    main()
