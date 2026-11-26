"""Unitree R1 独立 Adapter。"""

from zhjj_agent.adapters.vendor_plan_adapter import VendorPlanAdapter


class UnitreeR1Adapter(VendorPlanAdapter):
    MODEL_CODE = "unitree_r1"
    SDK_MODULES = ("unitree_sdk2py",)