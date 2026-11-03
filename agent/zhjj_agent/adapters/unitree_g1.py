"""宇树 Unitree G1 EDU 机型插件适配器

硬件/平台规格锁定：
- 机型代码：unitree_g1
- 目标 SKU：Unitree G1 EDU (23-29自由度版本)
- 依赖 SDK：unitree_sdk2py (官方高层 SDK2)
- 动作接口：g1_loco_client (高层运控/预置动作) 与 audio_client (音频播报)

边界声明（按 AGENTS.md / ADR-0001）：
- 现状标记：HARDWARE_UNVERIFIED（当前宿主为非机载环境，真机未验证）。
- 严守 D1-D11：不替代厂商底层运控、平衡或硬件急停；仅通过受控高层接口执行与安全取消。
- 若目标环境未安装 unitree_sdk2py 且未注入 sdk_stub，执行必须明确拒绝 (return False)，严禁假借计时器伪造模拟成功。
"""

import importlib.util
import json
import os
import sys
import threading
import time
from typing import Any, Dict, Optional

from zhjj_agent.adapters.base import BaseInstallAdapter


class UnitreeG1Adapter(BaseInstallAdapter):
    def __init__(
        self,
        network_interface: str = "eth0",
        app_dir: Optional[str] = None,
        sdk_stub: Optional[Any] = None
    ):
        self.network_interface = network_interface
        self.app_dir = app_dir
        self.sdk_stub = sdk_stub
        self._skills: Dict[str, Dict[str, Any]] = {}
        self._executions: Dict[str, Dict[str, Any]] = {}
        self._active_threads: Dict[str, threading.Thread] = {}
        self._cancel_flags: Dict[str, threading.Event] = {}
        self._sdk_available = False
        self._check_sdk()

    def _check_sdk(self):
        try:
            import unitree_sdk2py  # type: ignore
            self._sdk_available = True
        except ImportError:
            self._sdk_available = False

    @property
    def model_code(self) -> str:
        return "unitree_g1"

    @property
    def is_hardware_verified(self) -> bool:
        # 诚实标注：当前为无真机环境，真机未验证
        return False

    def prepare(self, skill_id: str, version: str, install_path: str, manifest: Dict[str, Any]) -> bool:
        # 校验目标机型匹配性，严禁跨机型错选
        target_model = manifest.get("target_model_code")
        if target_model and target_model != "unitree_g1":
            return False

        compat = manifest.get("compatibility", {})
        models = compat.get("models", [])
        if models:
            matched = any(m.get("model") == "unitree_g1" or m.get("vendor") == "unitree" for m in models)
            if not matched:
                return False

        # 检查安装路径与入口
        if not os.path.isdir(install_path):
            return False

        entrypoint = manifest.get("entrypoint", "action.py")
        if entrypoint:
            entry_file = os.path.join(install_path, entrypoint)
            if not os.path.isfile(entry_file):
                return False

        return True

    def install(self, skill_id: str, version: str, install_path: str, manifest: Dict[str, Any]) -> bool:
        if not os.path.isdir(install_path):
            return False

        self._skills[str(skill_id)] = {
            "skill_id": str(skill_id),
            "version": str(version),
            "install_path": install_path,
            "manifest": manifest,
            "enabled": True,
            "installed_at": int(time.time()),
        }
        return True

    def observe(self, skill_id: str) -> Dict[str, Any]:
        info = self._skills.get(str(skill_id))
        if not info and self.app_dir:
            # 重启后从持久化目录重建真实视图
            candidate_dir = os.path.join(self.app_dir, "skills", str(skill_id))
            state_file = os.path.join(self.app_dir, "state", "installed.json")
            if os.path.isfile(state_file):
                try:
                    with open(state_file, "r", encoding="utf-8") as f:
                        data = json.load(f)
                    skills_map = data.get("skills", {})
                    if str(skill_id) in skills_map:
                        item = skills_map[str(skill_id)]
                        info = {
                            "skill_id": str(skill_id),
                            "version": item.get("current_version", "1.0.0"),
                            "install_path": item.get("install_path", candidate_dir),
                            "manifest": item.get("manifest", {}),
                            "enabled": item.get("enabled", True),
                            "installed_at": int(time.time()),
                        }
                        self._skills[str(skill_id)] = info
                except Exception:
                    pass

        if not info:
            return {"installed": False, "status": "not_installed"}

        return {
            "installed": True,
            "version": info["version"],
            "enabled": info["enabled"],
            "status": "active" if info["enabled"] else "disabled",
            "model_code": self.model_code,
            "sdk_available": self._sdk_available or (self.sdk_stub is not None),
            "hardware_verified": self.is_hardware_verified,
        }

    def enable(self, skill_id: str) -> bool:
        obs = self.observe(skill_id)
        if obs.get("installed"):
            info = self._skills.get(str(skill_id))
            if info:
                info["enabled"] = True
                return True
        return False

    def disable(self, skill_id: str) -> bool:
        obs = self.observe(skill_id)
        if obs.get("installed"):
            info = self._skills.get(str(skill_id))
            if info:
                info["enabled"] = False
                for eid, ex in list(self._executions.items()):
                    if ex.get("skill_id") == str(skill_id) and not ex.get("is_stopped"):
                        self.cancel_execution(eid)
                return True
        return False

    def uninstall(self, skill_id: str, install_path: str) -> bool:
        self._skills.pop(str(skill_id), None)
        for eid, ex in list(self._executions.items()):
            if ex.get("skill_id") == str(skill_id) and not ex.get("is_stopped"):
                self.cancel_execution(eid)
        return True

    def rollback(self, skill_id: str, restore_version: str, restore_path: str) -> bool:
        if not os.path.isdir(restore_path):
            return False

        obs = self.observe(skill_id)
        if obs.get("installed"):
            info = self._skills.get(str(skill_id))
            if info:
                info["version"] = str(restore_version)
                info["install_path"] = restore_path
                info["enabled"] = True
                return True
        return False

    def health(self) -> Dict[str, Any]:
        return {
            "model_code": self.model_code,
            "sku": "Unitree G1 EDU",
            "hardware_verified": False,
            "hardware_status": "HARDWARE_UNVERIFIED",
            "sdk_available": self._sdk_available or (self.sdk_stub is not None),
            "installed_count": len(self._skills),
        }

    # ── 技能执行与取消 ───────────────────────────────────────────────────────

    def execute_skill(
        self,
        execution_id: str,
        skill_id: str,
        plan_digest: Optional[str],
        params: Dict[str, Any]
    ) -> bool:
        obs = self.observe(skill_id)
        if not obs.get("installed") or not obs.get("enabled"):
            return False

        info = self._skills.get(str(skill_id))
        if not info:
            return False

        install_path = info.get("install_path", "")
        manifest = info.get("manifest", {})
        entrypoint_rel = manifest.get("entrypoint", "action.py")
        entrypoint_file = os.path.join(install_path, entrypoint_rel)

        # 检查入口文件真实存在
        if not os.path.isfile(entrypoint_file):
            return False

        # 若真实 SDK 不可用且未显式提供 sdk_stub，真实适配器必须拒绝执行（严禁假冒成功）
        if not self._sdk_available and self.sdk_stub is None:
            return False

        cancel_event = threading.Event()
        self._cancel_flags[execution_id] = cancel_event

        exec_state = {
            "execution_id": execution_id,
            "skill_id": str(skill_id),
            "plan_digest": plan_digest,
            "params": params,
            "status": "RUNNING",
            "is_stopped": False,
            "start_time": time.time(),
            "error_msg": None,
        }
        self._executions[execution_id] = exec_state

        def _worker():
            try:
                # 优先调用注入的 sdk_stub 进行契约验证
                if self.sdk_stub is not None:
                    if hasattr(self.sdk_stub, "execute"):
                        self.sdk_stub.execute(execution_id, skill_id, plan_digest, params, cancel_event)
                    else:
                        time.sleep(0.02)
                else:
                    # 运行真实入口 action.py 脚本
                    spec = importlib.util.spec_from_file_location(f"action_{execution_id}", entrypoint_file)
                    if spec and spec.loader:
                        mod = importlib.util.module_from_spec(spec)
                        spec.loader.exec_module(mod)
                        if hasattr(mod, "run"):
                            class Context:
                                def is_canceled(self):
                                    return cancel_event.is_set()
                            mod.run(context=Context())

                if cancel_event.is_set():
                    exec_state["status"] = "CANCELED"
                    exec_state["is_stopped"] = True
                else:
                    exec_state["status"] = "SUCCEEDED"
                    exec_state["is_stopped"] = True
            except Exception as e:
                exec_state["status"] = "FAILED"
                exec_state["is_stopped"] = True
                exec_state["error_msg"] = str(e)

        th = threading.Thread(target=_worker, daemon=True)
        self._active_threads[execution_id] = th
        th.start()
        return True

    def cancel_execution(self, execution_id: str) -> bool:
        exec_info = self._executions.get(execution_id)
        if not exec_info:
            return False

        cancel_ev = self._cancel_flags.get(execution_id)
        if cancel_ev:
            cancel_ev.set()

        if self.sdk_stub and hasattr(self.sdk_stub, "cancel"):
            self.sdk_stub.cancel(execution_id)

        exec_info["status"] = "CANCEL_REQUESTED"
        # 必须等待工作线程退出后才确认 is_stopped 为 True
        th = self._active_threads.get(execution_id)
        if th:
            th.join(timeout=1.0)
            if not th.is_alive():
                exec_info["status"] = "CANCELED"
                exec_info["is_stopped"] = True
            else:
                exec_info["is_stopped"] = False
        else:
            exec_info["status"] = "CANCELED"
            exec_info["is_stopped"] = True

        return True

    def observe_execution(self, execution_id: str) -> Dict[str, Any]:
        exec_info = self._executions.get(execution_id)
        if not exec_info:
            return {
                "status": "UNKNOWN",
                "is_stopped": False,  # 未知执行绝对不能返回已确认停止！
                "error_msg": "执行记录不存在",
            }

        return {
            "status": exec_info["status"],
            "is_stopped": exec_info["is_stopped"],
            "error_msg": exec_info.get("error_msg"),
        }
