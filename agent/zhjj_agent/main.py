"""召唤机甲 Agent CLI 启动入口"""

import argparse
import json
import os
import sys
import time
from typing import Optional

from zhjj_agent import __version__
from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.adapters.unitree_g1 import UnitreeG1Adapter
from zhjj_agent.client import AgentClient
from zhjj_agent.storage import Storage
from zhjj_agent.task_manager import TaskManager


def create_adapter(model_code: str, install_dir: Optional[str] = None):
    if model_code == "unitree_g1":
        return UnitreeG1Adapter(app_dir=install_dir)
    elif model_code == "mock":
        return MockAdapter()
    raise ValueError(f"不支持或未知的机型适配器: {model_code}。生产环境禁止静默回落到 MockAdapter。")


def run(config_path: str, once: bool = False, adapter_override: Optional[str] = None):
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
        pubkey_pem=pubkey_pem
    )

    poll_interval = int(config.get("poll_interval", 5))
    print(f"[zhjj-agent] 启动 v{__version__} did={did} adapter={model_code} dir={install_dir}")

    # 发送首联心跳
    client.heartbeat(
        agent_version=__version__,
        capability_snapshot=adapter.health()
    )

    if once:
        count = manager.poll_once()
        print(f"[zhjj-agent] 单次轮询结束，处理指令数: {count}")
        return

    while True:
        try:
            manager.poll_once()
        except Exception as e:
            print(f"[zhjj-agent] 轮询异常 (继续等待): {e}")
        time.sleep(poll_interval)


def main():
    parser = argparse.ArgumentParser(description="召唤机甲 · 设备端 Agent (zhjj_agent)")
    parser.add_argument("--config", required=True, help="Agent 配置文件 config.json 路径")
    parser.add_argument("--once", action="store_true", help="单次轮询后退出 (测试/调试用)")
    parser.add_argument("--adapter", choices=["mock", "unitree_g1"], help="强制指定机型适配器")
    parser.add_argument("--version", action="version", version=f"%(prog)s {__version__}")
    args = parser.parse_args()

    run(args.config, once=args.once, adapter_override=args.adapter)


if __name__ == "__main__":
    main()
