"""五机型 Adapter 注册表。模型代码是唯一选择依据。"""

from typing import Optional

from zhjj_agent.adapters.agibot_a3 import AgibotA3Adapter
from zhjj_agent.adapters.agibot_x2 import AgibotX2Adapter
from zhjj_agent.adapters.base import BaseInstallAdapter
from zhjj_agent.adapters.engineai_t800 import EngineAIT800Adapter
from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.adapters.unitree_g1 import UnitreeG1Adapter
from zhjj_agent.adapters.unitree_r1 import UnitreeR1Adapter


def create_adapter(model_code: str, install_dir: Optional[str] = None, stop_observer: Optional[object] = None) -> BaseInstallAdapter:
    if model_code == "unitree_g1":
        return UnitreeG1Adapter(app_dir=install_dir, stop_observer=stop_observer)
    if model_code == "unitree_r1":
        return UnitreeR1Adapter(app_dir=install_dir, stop_observer=stop_observer)
    if model_code == "agibot_x2":
        return AgibotX2Adapter(app_dir=install_dir, stop_observer=stop_observer)
    if model_code == "agibot_a3":
        return AgibotA3Adapter(app_dir=install_dir, stop_observer=stop_observer)
    if model_code == "engineai_t800":
        return EngineAIT800Adapter(app_dir=install_dir, stop_observer=stop_observer)
    if model_code == "mock":
        return MockAdapter()
    raise ValueError(f"不支持或未知的机型适配器: {model_code}。生产环境禁止静默回落到 MockAdapter。")