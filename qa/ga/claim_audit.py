#!/usr/bin/env python3
"""Audit legacy physics/safety claims and demote evidence that cannot support HIL."""

from __future__ import annotations

import hashlib
import json
import pathlib
import re

ROOT = pathlib.Path(__file__).resolve().parents[2]


def sha256(path: pathlib.Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main() -> int:
    hil_report = ROOT / "qa" / "hil-verification" / "hil_safety_report.json"
    hil_source = ROOT / "qa" / "hil-verification" / "verify_hil_safety_interlock.py"
    dyn_report = ROOT / "qa" / "physics-verification" / "dynamics_verification_report.json"
    dyn_source = ROOT / "qa" / "physics-verification" / "verify_kinematics_dynamics.py"
    dds_source = ROOT / "qa" / "dds-verification" / "verify_idl_layout.py"

    hil_text = hil_source.read_text(encoding="utf-8")
    hil_json = json.loads(hil_report.read_text(encoding="utf-8"))
    dyn_text = dyn_source.read_text(encoding="utf-8")
    dds_text = dds_source.read_text(encoding="utf-8")

    result = {
        "evidence_level": "RED_TEAM_AUDIT_ONLY",
        "claims": [
            {
                "claim_id": "AUDIT-HIL-LEGACY-001",
                "claim": "qa/hil-verification/hil_safety_report.json is HIL evidence",
                "verdict": "INVALID_NON_HIL",
                "reasons": [
                    "source contains MockHardwareSafetyInterlock",
                    "deceleration_time_ms is hard-coded to 12.5",
                    "source contains the comment '硬件实测响应时间 12.5ms' without a raw independent sensor artifact",
                    "report schema calls itself HIL but the implementation is an in-memory mock",
                ],
                "observed_report_verdict": hil_json.get("verdict"),
                "source_sha256": sha256(hil_source),
                "report_sha256": sha256(hil_report),
                "must_not_be_used_for": ["HIL", "L1", "Production Ready", "five-model GA"],
            },
            {
                "claim_id": "AUDIT-DYN-LEGACY-001",
                "claim": "dynamics_verification_report.json proves Pinocchio/RNEA multibody inverse dynamics",
                "verdict": "DEMOTED_LOGICAL_IDENTITIES_ONLY",
                "reasons": [
                    "source has no pinocchio import or RNEA call",
                    "joint inertia matrix is a hard-coded reduced model",
                    "minimum collision clearance is hard-coded to 28.5 mm rather than computed from URDF geometry",
                    "report text claiming Pinocchio/RNEA is not supported by its source",
                ],
                "source_sha256": sha256(dyn_source),
                "report_sha256": sha256(dyn_report),
                "maximum_supported_claim": "arithmetic trajectory/limit identity checks against a reduced model",
            },
            {
                "claim_id": "AUDIT-DDS-LEGACY-001",
                "claim": "DDS conformance proves live DDS interoperability",
                "verdict": "WIRE_LAYOUT_ARITHMETIC_ONLY",
                "reasons": [
                    "verifier uses Python struct arithmetic only",
                    "no live DDS participant, vendor IDL compiler, or loopback peer is used",
                    "jitter is a local CPU timing sample, not a live DDS transport measurement",
                ],
                "source_sha256": sha256(dds_source),
                "maximum_supported_claim": "document-level byte-layout and local timing arithmetic",
            },
        ],
        "source_markers": {
            "hil_mock_class": "MockHardwareSafetyInterlock" in hil_text,
            "hil_hardcoded_12_5ms": re.search(r"decel_time_ms\s*=\s*12\.5", hil_text) is not None,
            "pinocchio_import": re.search(r"^\s*(?:import|from)\s+pinocchio\b", dyn_text, re.MULTILINE) is not None,
            "rnea_call": re.search(r"\brnea\s*\(", dyn_text, re.IGNORECASE) is not None,
            "hardcoded_clearance_28_5": re.search(r"min_clearance_observed_mm[^\n]*28\.5", dyn_text) is not None,
            "live_dds_participant": re.search(r"^\s*(?:import|from)\s+(?:cyclonedds|fastdds|rclpy)\b", dds_text, re.MULTILINE) is not None,
        },
    }
    output = ROOT / "qa" / "ga" / "claim_audit_result.json"
    output.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())