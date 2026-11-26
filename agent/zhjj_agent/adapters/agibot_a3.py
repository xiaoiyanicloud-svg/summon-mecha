"""Agibot Expedition A3 独立 Adapter。"""

from zhjj_agent.adapters.vendor_plan_adapter import VendorPlanAdapter


class AgibotA3Adapter(VendorPlanAdapter):
    MODEL_CODE = "agibot_a3"
    SDK_MODULES = ("aimdk",)