"""Agibot Lingxi X2 独立 Adapter。"""

from zhjj_agent.adapters.vendor_plan_adapter import VendorPlanAdapter


class AgibotX2Adapter(VendorPlanAdapter):
    MODEL_CODE = "agibot_x2"
    SDK_MODULES = ("aimdk",)