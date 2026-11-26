"""五机型共用的受限计划适配器运行时。

它不是“通用厂商 Adapter”：每个机型仍有独立 Adapter 类、model_code、SDK 模块与
Profile 绑定。本基类只复用生命周期、路径安全和执行监督，不得绕过厂商边界。
"""

import importlib.util
import json
import os
import sys
import shutil
import threading
import time
from typing import Any, Dict, List, Optional

from zhjj_agent.adapters.base import BaseInstallAdapter
from zhjj_agent.stop_observation import validate_observation


class VendorPlanAdapter(BaseInstallAdapter):
    MODEL_CODE = ""
    SDK_MODULES: tuple[str, ...] = ()

    def __init__(self, app_dir: Optional[str] = None, sdk_stub: Optional[Any] = None, stop_observer: Optional[Any] = None):
        if not self.MODEL_CODE:
            raise ValueError("VendorPlanAdapter 子类必须声明 MODEL_CODE")
        self.app_dir = os.path.abspath(app_dir or os.getcwd())
        self.sdk_stub = sdk_stub
        self.stop_observer = stop_observer
        self._skills: Dict[str, Dict[str, Any]] = {}
        self._executions: Dict[str, Dict[str, Any]] = {}
        self._active_threads: Dict[str, threading.Thread] = {}
        self._cancel_flags: Dict[str, threading.Event] = {}

    @property
    def model_code(self) -> str:
        return self.MODEL_CODE

    @property
    def is_hardware_verified(self) -> bool:
        return False

    def _sdk_available(self) -> bool:
        if self.sdk_stub is not None:
            return True
        for module in self.SDK_MODULES:
            try:
                importlib.import_module(module)
                return True
            except ImportError:
                continue
        return False

    def _safe_skill_root(self, skill_id: str) -> str:
        base = os.path.realpath(os.path.join(self.app_dir, "skills"))
        root = os.path.realpath(os.path.join(base, str(skill_id)))
        if root != base and not root.startswith(base + os.sep):
            raise ValueError("技能安装路径越界")
        return root

    def _read_json(self, path: str, max_bytes: int = 2 * 1024 * 1024) -> Dict[str, Any]:
        if not os.path.isfile(path):
            return {}
        if os.path.getsize(path) > max_bytes:
            raise ValueError(f"JSON 文件超过大小预算: {path}")
        with open(path, "r", encoding="utf-8") as f:
            data = json.load(f)
        return data if isinstance(data, dict) else {}

    def prepare(self, skill_id: str, version: str, install_path: str, manifest: Dict[str, Any]) -> bool:
        target = manifest.get("target_model_code")
        if target and target != self.MODEL_CODE:
            return False
        runtime = manifest.get("runtime") or {}
        if runtime.get("target_model") and runtime.get("target_model") != self.MODEL_CODE:
            return False
        digest = str(runtime.get("target_profile_digest") or "")
        if not digest.startswith("sha256:"):
            return False

        install_root = os.path.realpath(install_path)
        skill_root = self._safe_skill_root(skill_id)
        if install_root != skill_root and not install_root.startswith(skill_root + os.sep) and not skill_root.startswith(install_root + os.sep):
            return False

        binding = self._read_json(os.path.join(install_path, "bindings", "target_binding.json"))
        if binding.get("target_model_code") != self.MODEL_CODE:
            return False
        if binding.get("target_profile_digest") != digest:
            return False

        entrypoint = str(manifest.get("entrypoint") or "action.py")
        if os.path.isabs(entrypoint) or ".." in entrypoint.split("/") or "\\" in entrypoint:
            return False
        return os.path.isfile(os.path.join(install_path, entrypoint))

    def install(self, skill_id: str, version: str, install_path: str, manifest: Dict[str, Any]) -> bool:
        if not self.prepare(skill_id, version, install_path, manifest):
            return False
        marker = {
            "schema": "zhaohuan.adapter-install/v1",
            "model_code": self.MODEL_CODE,
            "version": str(version),
            "install_path": os.path.realpath(install_path),
            "target_profile_digest": (manifest.get("runtime") or {}).get("target_profile_digest"),
            "hardware_verification_status": "HARDWARE_UNVERIFIED",
            "installed_at": int(time.time()),
        }
        marker_path = os.path.join(install_path, ".adapter.json")
        tmp_path = marker_path + ".tmp"
        with open(tmp_path, "w", encoding="utf-8") as f:
            json.dump(marker, f, sort_keys=True)
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp_path, marker_path)
        self._skills[str(skill_id)] = {
            **marker,
            "enabled": True,
            "path": os.path.abspath(install_path),
            "entrypoint": str(manifest.get("entrypoint") or "action.py"),
        }
        return True

    def observe(self, skill_id: str) -> Dict[str, Any]:
        state = self._skills.get(str(skill_id))
        if not state:
            root = self._safe_skill_root(skill_id)
            candidates = sorted([p for p in (os.listdir(root) if os.path.isdir(root) else []) if p.startswith("v")])
            for name in reversed(candidates):
                path = os.path.join(root, name)
                marker = self._read_json(os.path.join(path, ".adapter.json"))
                if marker.get("model_code") == self.MODEL_CODE:
                    state = {
                        **marker,
                        "enabled": not os.path.exists(os.path.join(path, ".disabled")),
                        "path": path,
                        "entrypoint": "action.py",
                    }
                    self._skills[str(skill_id)] = state
                    break
        if not state:
            return {"installed": False, "enabled": False, "version": None, "status": "absent"}
        return {
            "installed": True,
            "enabled": bool(state.get("enabled")),
            "version": state.get("version"),
            "status": "installed" if state.get("enabled") else "disabled",
            "path": state.get("path"),
            "hardware_verification_status": "HARDWARE_UNVERIFIED",
        }

    def enable(self, skill_id: str) -> bool:
        state = self.observe(skill_id)
        if not state.get("installed"):
            return False
        disabled = os.path.join(state["path"], ".disabled")
        if os.path.exists(disabled):
            os.remove(disabled)
        self._skills[str(skill_id)]["enabled"] = True
        return True

    def disable(self, skill_id: str) -> bool:
        state = self.observe(skill_id)
        if not state.get("installed"):
            return False
        marker = os.path.join(state["path"], ".disabled")
        with open(marker, "w", encoding="utf-8") as f:
            f.write("disabled\n")
            f.flush()
            os.fsync(f.fileno())
        self._skills[str(skill_id)]["enabled"] = False
        return True

    def uninstall(self, skill_id: str, install_path: str) -> bool:
        root = self._safe_skill_root(skill_id)
        target = os.path.realpath(install_path)
        if target != root and not target.startswith(root + os.sep):
            return False
        for exec_id, info in list(self._executions.items()):
            if str(info.get("skill_id")) == str(skill_id) and not info.get("is_stopped"):
                return False
        self._skills.pop(str(skill_id), None)
        return True

    def rollback(self, skill_id: str, restore_version: str, restore_path: str) -> bool:
        root = self._safe_skill_root(skill_id)
        target = os.path.realpath(restore_path)
        if not os.path.isdir(target) or not (target == root or target.startswith(root + os.sep)):
            return False
        state = self.observe(skill_id)
        if not state.get("installed"):
            return False
        self._skills[str(skill_id)]["version"] = str(restore_version)
        self._skills[str(skill_id)]["path"] = target
        return True

    def health(self) -> Dict[str, Any]:
        return {
            "model_code": self.MODEL_CODE,
            "sdk_modules": list(self.SDK_MODULES),
            "sdk_available": self._sdk_available(),
            "hardware_verification_status": "HARDWARE_UNVERIFIED",
            "adapter_version": "zhaohuan-agent-adapter/v1",
        }

    def _observe_independent_stop(self, execution_id: str):
        """返回 (是否已停, 错误, 结构化观察)。只给布尔值 = 不可验证。"""
        observer = self.stop_observer
        if observer is None:
            return False, "STOP_OBSERVER_UNAVAILABLE", None
        try:
            result = observer.observe_execution(execution_id) if hasattr(observer, "observe_execution") else observer(execution_id)
            if not isinstance(result, dict):
                return False, "STOP_OBSERVER_INVALID_RESULT", None
            if result.get("is_stopped") is not True:
                return False, str(result.get("error_msg") or "STOP_NOT_OBSERVED"), None
            observation = validate_observation(result.get("observation"))
            if observation is None:
                return False, "STOP_OBSERVATION_MISSING", None
            return True, None, observation
        except Exception as exc:
            return False, f"STOP_OBSERVER_ERROR: {exc}", None
    def execute_skill(self, execution_id: str, skill_id: str, plan_digest: Optional[str], params: Dict[str, Any]) -> bool:
        state = self._skills.get(str(skill_id))
        if not state:
            observed = self.observe(skill_id)
            if not observed.get("installed") or not observed.get("enabled"):
                return False
            state = self._skills[str(skill_id)]

        if str(skill_id) not in self._skills:
            return False
        if execution_id in self._executions:
            return False

        entrypoint = os.path.realpath(os.path.join(state["path"], state.get("entrypoint", "action.py")))
        if not entrypoint.startswith(os.path.realpath(state["path"]) + os.sep) or not os.path.isfile(entrypoint):
            return False

        cancel_event = threading.Event()
        self._cancel_flags[execution_id] = cancel_event
        exec_state = {
            "execution_id": execution_id,
            "skill_id": str(skill_id),
            "plan_digest": plan_digest,
            "status": "RUNNING",
            "is_stopped": False,
            "error_msg": None,
        }
        self._executions[execution_id] = exec_state

        def worker():
            try:
                spec = importlib.util.spec_from_file_location(f"action_{execution_id}", entrypoint)
                if not spec or not spec.loader:
                    raise RuntimeError("无法加载技能入口")
                module = importlib.util.module_from_spec(spec)
                previous_dont_write_bytecode = sys.dont_write_bytecode
                sys.dont_write_bytecode = True
                try:
                    spec.loader.exec_module(module)
                finally:
                    sys.dont_write_bytecode = previous_dont_write_bytecode
                if not hasattr(module, "run"):
                    raise RuntimeError("技能入口缺少 run(context)")

                class Context:
                    def __init__(self):
                        self.stub = self_stub
                        self.adapter = adapter_ref
                        self.execution_id = execution_id
                        self.execution_params = params or {}
                        self.cancel_event = cancel_event

                    def is_canceled(self):
                        return cancel_event.is_set()

                self_stub = self.sdk_stub
                adapter_ref = self
                result = module.run(context=Context())
                if not isinstance(result, dict):
                    raise RuntimeError("技能入口必须返回结构化执行结果")
                status = result.get("status")
                if status not in ("SUCCEEDED", "FAILED", "CANCELED"):
                    raise RuntimeError("技能入口返回未知状态")
                stopped, stop_error, observation = self._observe_independent_stop(execution_id)
                if stopped:
                    exec_state["status"] = status
                    exec_state["is_stopped"] = True
                    exec_state["observation"] = observation
                else:
                    exec_state["status"] = "UNKNOWN"
                    exec_state["is_stopped"] = False
                    exec_state["error_msg"] = stop_error or "执行结束但未获独立停止证据"
                if result.get("error_msg"):
                    exec_state["error_msg"] = str(result["error_msg"])
            except Exception as exc:
                stopped, stop_error, observation = self._observe_independent_stop(execution_id)
                exec_state["status"] = "FAILED" if stopped else "UNKNOWN"
                exec_state["is_stopped"] = stopped
                exec_state["observation"] = observation if stopped else None
                exec_state["error_msg"] = str(exc) if stopped else f"{exc}; {stop_error or 'STOP_NOT_OBSERVED'}"

        thread = threading.Thread(target=worker, daemon=True, name=f"{self.MODEL_CODE}-{execution_id}")
        self._active_threads[execution_id] = thread
        thread.start()
        return True

    def execute_action(self, action_id: str, params: Dict[str, Any], cancel_event: Optional[threading.Event] = None) -> Dict[str, Any]:
        if not self._sdk_available():
            raise RuntimeError(f"{self.MODEL_CODE} SDK 不可用，拒绝执行真实动作")
        raise RuntimeError(f"{self.MODEL_CODE} 的真实厂商动作桥尚未取得 HIL 证据，拒绝把 SDK import 当作成功")

    def cancel_execution(self, execution_id: str) -> bool:
        state = self._executions.get(execution_id)
        if not state:
            return False
        event = self._cancel_flags.get(execution_id)
        if event:
            event.set()
        if self.sdk_stub is not None and hasattr(self.sdk_stub, "cancel"):
            self.sdk_stub.cancel(execution_id)
        state["status"] = "CANCEL_REQUESTED"
        thread = self._active_threads.get(execution_id)
        if thread:
            thread.join(timeout=1.0)
        stopped, stop_error, observation = self._observe_independent_stop(execution_id)
        if thread and not thread.is_alive() and stopped:
            state["status"] = "CANCELED"
            state["is_stopped"] = True
            state["observation"] = observation
            return True
        state["status"] = "CANCEL_REQUESTED"
        state["is_stopped"] = False
        state["error_msg"] = stop_error or "取消请求尚未获得独立停止证据"
        return True

    def observe_execution(self, execution_id: str) -> Dict[str, Any]:
        state = self._executions.get(execution_id)
        if not state:
            return {"status": "UNKNOWN", "is_stopped": False, "error_msg": "执行记录不存在"}
        return {
            "status": state.get("status", "UNKNOWN"),
            "is_stopped": bool(state.get("is_stopped", False)),
            "error_msg": state.get("error_msg"),
            "observation": state.get("observation") if state.get("is_stopped") else None,
        }