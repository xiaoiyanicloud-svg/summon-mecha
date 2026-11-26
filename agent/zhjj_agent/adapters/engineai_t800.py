"""EngineAI T800 独立 Adapter。"""

from zhjj_agent.adapters.vendor_plan_adapter import VendorPlanAdapter


class EngineAIT800Adapter(VendorPlanAdapter):
    MODEL_CODE = "engineai_t800"
    SDK_MODULES = ("engineai_native_sdk",)