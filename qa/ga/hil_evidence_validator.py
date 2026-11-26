#!/usr/bin/env python3
"""Validate per-target HIL evidence without promoting SIL/mock evidence.

This tool is a gate, not a proof that physical testing occurred. It enforces the
minimum machine-checkable evidence bundle and explicitly rejects SDK/BT/cloud
responses as stop observations.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import pathlib
import re
import sys
from datetime import datetime
from typing import Any, Dict, List

ALLOWED_TARGETS = {
    "unitree_g1",
    "unitree_r1",
    "agibot_x2",
    "agibot_a3",
    "engineai_t800",
}
CLAIM_LEVELS = {"UNVERIFIED", "SIL", "HIL", "LIMITED_FIELD", "GA"}
HIL_OR_ABOVE = {"HIL", "LIMITED_FIELD", "GA"}
FORBIDDEN_STOP_SOURCES = {
    "sdk_return",
    "sdk_true",
    "bt_halt",
    "cancel_requested",
    "cloud_ack",
    "script_assertion",
    "mock",
    "sil",
}
REQUIRED_SCENARIOS = {
    "e_stop",
    "watchdog_timeout",
    "communication_loss",
    "power_loss",
    "sensor_failure",
    "thermal_derating",
    "rollback_failure",
    "crash_after_start",
}
SHA256_RE = re.compile(r"^(?:sha256:)?[a-fA-F0-9]{64}$")



def binomial_cdf(k: int, n: int, p: float) -> float:
    if p <= 0.0:
        return 1.0
    if p >= 1.0:
        return 0.0 if k < n else 1.0
    return sum(math.comb(n, i) * (p ** i) * ((1.0 - p) ** (n - i)) for i in range(k + 1))


def clopper_pearson_upper(failures: int, trials: int, confidence: float) -> float:
    """One-sided exact binomial upper bound for the failure probability."""
    if trials <= 0 or failures < 0 or failures > trials:
        raise ValueError("invalid binomial counts")
    alpha = 1.0 - confidence
    if failures == trials:
        return 1.0
    if failures == 0:
        return 1.0 - (alpha ** (1.0 / trials))
    low, high = 0.0, 1.0
    for _ in range(100):
        mid = (low + high) / 2.0
        if binomial_cdf(failures, trials, mid) > alpha:
            low = mid
        else:
            high = mid
    return high

class ValidationError(Exception):
    pass


def sha256_file(path: pathlib.Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def normalize_observation_source(value: Any) -> str:
    return re.sub(r"[^a-z0-9]+", "_", str(value).lower()).strip("_")


def is_forbidden_observation_source(value: Any) -> bool:
    normalized = normalize_observation_source(value)
    return any(token in normalized for token in FORBIDDEN_STOP_SOURCES)


def is_independent_observation_source(value: Any) -> bool:
    normalized = normalize_observation_source(value)
    return normalized.startswith("independent_") and not is_forbidden_observation_source(normalized)

def require(condition: bool, message: str, errors: List[str]) -> None:
    if not condition:
        errors.append(message)


def parse_time(value: Any, field: str, errors: List[str]) -> datetime | None:
    if not isinstance(value, str) or not value.strip():
        errors.append(f"{field} must be an ISO-8601 timestamp")
        return None
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        errors.append(f"{field} is not a valid ISO-8601 timestamp")
        return None


def validate_file_ref(ref: Any, base_dir: pathlib.Path, field: str, errors: List[str]) -> None:
    if not isinstance(ref, dict):
        errors.append(f"{field} must be an object with path/sha256")
        return
    raw_path = ref.get("path")
    expected_hash = ref.get("sha256")
    if not isinstance(raw_path, str) or not raw_path.strip():
        errors.append(f"{field}.path is required")
        return
    if not isinstance(expected_hash, str) or not SHA256_RE.match(expected_hash):
        errors.append(f"{field}.sha256 must be a SHA-256 digest")
        return
    path = (base_dir / raw_path).resolve()
    try:
        path.relative_to(base_dir.resolve())
    except ValueError:
        errors.append(f"{field}.path escapes the evidence bundle directory")
        return
    if not path.is_file():
        errors.append(f"{field}.path does not exist: {path}")
        return
    actual = sha256_file(path)
    require(
        actual == expected_hash.removeprefix("sha256:").lower(),
        f"{field}.sha256 mismatch: expected {expected_hash}, got {actual}",
        errors,
    )


def validate_manifest(manifest_path: pathlib.Path) -> Dict[str, Any]:
    errors: List[str] = []
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ValidationError(f"cannot read evidence manifest: {exc}") from exc

    if not isinstance(manifest, dict):
        raise ValidationError("evidence manifest must be a JSON object")

    require(manifest.get("schema") == "summon-mecha/hil-evidence/v1", "schema mismatch", errors)
    level = manifest.get("claim_level")
    require(level in CLAIM_LEVELS, "claim_level is invalid", errors)

    target = manifest.get("target") or {}
    if not isinstance(target, dict):
        errors.append("target must be an object")
        target = {}
    model_code = target.get("model_code")
    require(model_code in ALLOWED_TARGETS, "target.model_code must be one of the five GA targets", errors)
    for field in ("vendor", "firmware_version", "sdk_name", "sdk_version", "agent_version"):
        require(isinstance(target.get(field), str) and target.get(field).strip(), f"target.{field} is required", errors)
    serial_hash = target.get("hardware_serial_hash")
    require(
        isinstance(serial_hash, str) and serial_hash.startswith("sha256:") and SHA256_RE.match(serial_hash) is not None,
        "target.hardware_serial_hash must be sha256:<digest>",
        errors,
    )
    for field in ("artifact_sha256", "target_profile_digest"):
        value = target.get(field)
        require(isinstance(value, str) and SHA256_RE.match(value) is not None, f"target.{field} must be SHA-256", errors)

    run = manifest.get("run") or {}
    if not isinstance(run, dict):
        errors.append("run must be an object")
        run = {}
    start = parse_time(run.get("started_at"), "run.started_at", errors)
    end = parse_time(run.get("ended_at"), "run.ended_at", errors)
    if start and end:
        require(end >= start, "run.ended_at must not precede run.started_at", errors)
    for field in ("operator_id", "facility_id"):
        require(isinstance(run.get(field), str) and run.get(field).strip(), f"run.{field} is required", errors)

    observation_source = run.get("observation_source", "")
    require(not is_forbidden_observation_source(observation_source), "forbidden stop observation source", errors)
    require(is_independent_observation_source(observation_source), "run.observation_source must identify an independent physical observation", errors)
    require(run.get("actual_started") is True, "run.actual_started must be true", errors)
    require(run.get("actual_stopped") is True, "run.actual_stopped must be true", errors)
    require(run.get("physical_stop_observed") is True, "run.physical_stop_observed must be true", errors)
    require(run.get("estop_independent") is True, "run.estop_independent must be true", errors)

    if level in HIL_OR_ABOVE:
        validate_file_ref(run.get("raw_log"), manifest_path.parent, "run.raw_log", errors)
        validate_file_ref(run.get("replay_script"), manifest_path.parent, "run.replay_script", errors)
        scenarios = manifest.get("scenarios")
        if not isinstance(scenarios, list):
            errors.append("scenarios must be a list for HIL evidence")
            scenarios = []
        seen = set()
        statistics = []
        for index, scenario in enumerate(scenarios):
            if not isinstance(scenario, dict):
                errors.append(f"scenarios[{index}] must be an object")
                continue
            scenario_id = scenario.get("id")
            if scenario_id not in REQUIRED_SCENARIOS:
                errors.append(f"scenarios[{index}].id is not a required HIL scenario")
                continue
            seen.add(scenario_id)
            require(scenario.get("result") == "PASS", f"scenario {scenario_id} did not PASS", errors)
            observed_by = scenario.get("observed_by", "")
            require(
                not is_forbidden_observation_source(observed_by),
                f"scenario {scenario_id} uses a forbidden observation source",
                errors,
            )
            require(
                is_independent_observation_source(observed_by),
                f"scenario {scenario_id} must use an independent physical observation source",
                errors,
            )
            trials = scenario.get("n_trials")
            failures = scenario.get("failures")
            confidence = scenario.get("confidence_level")
            max_bound = scenario.get("max_failure_rate_upper_bound")
            if not isinstance(trials, int) or trials <= 0:
                errors.append(f"scenario {scenario_id}.n_trials must be a positive integer")
            if not isinstance(failures, int) or not isinstance(trials, int) or failures < 0 or failures > trials:
                errors.append(f"scenario {scenario_id}.failures must be an integer in [0,n_trials]")
            if not isinstance(confidence, (int, float)) or not (0.0 < float(confidence) < 1.0):
                errors.append(f"scenario {scenario_id}.confidence_level must be in (0,1)")
            if not isinstance(max_bound, (int, float)) or not (0.0 <= float(max_bound) <= 1.0):
                errors.append(f"scenario {scenario_id}.max_failure_rate_upper_bound must be in [0,1]")
            if isinstance(trials, int) and isinstance(failures, int) and isinstance(confidence, (int, float)) and isinstance(max_bound, (int, float)) and 0 < trials and 0 <= failures <= trials and 0 < float(confidence) < 1:
                upper = clopper_pearson_upper(failures, trials, float(confidence))
                statistics.append({
                    "id": scenario_id,
                    "n": trials,
                    "failures": failures,
                    "confidence_level": float(confidence),
                    "failure_rate_upper_bound": round(upper, 8),
                })
                require(
                    upper <= float(max_bound),
                    f"scenario {scenario_id} failure-rate upper bound {upper:.6f} exceeds {max_bound}",
                    errors,
                )
            validate_file_ref(scenario.get("evidence"), manifest_path.parent, f"scenarios[{index}].evidence", errors)
        missing = REQUIRED_SCENARIOS - seen
        require(not missing, f"missing HIL scenarios: {', '.join(sorted(missing))}", errors)

        if level in {"LIMITED_FIELD", "GA"}:
            require(manifest.get("field_validation") is True, "LIMITED_FIELD/GA requires field_validation=true", errors)
        if level == "GA":
            ga_bound = manifest.get("max_failure_rate_upper_bound")
            require(isinstance(ga_bound, (int, float)) and float(ga_bound) <= 0.01, "GA requires max_failure_rate_upper_bound <= 0.01", errors)
            require(manifest.get("production_ready_claim") is True, "GA requires production_ready_claim=true", errors)
            require(manifest.get("open_safety_findings", []) == [], "GA requires no open safety findings", errors)
            require(manifest.get("unresolved_limits", []) == [], "GA requires no unresolved physical limits", errors)

    result = {
        "manifest": str(manifest_path),
        "manifest_sha256": sha256_file(manifest_path),
        "model_code": model_code,
        "claim_level": level,
        "passed": not errors,
        "errors": errors,
        "statistics": statistics if level in HIL_OR_ABOVE else [],
    }
    if errors:
        raise ValidationError(json.dumps(result, ensure_ascii=False, indent=2))
    return result


def main(argv: List[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Validate per-target HIL evidence")
    parser.add_argument("manifest", type=pathlib.Path)
    parser.add_argument("--json", type=pathlib.Path)
    args = parser.parse_args(argv)
    try:
        result = validate_manifest(args.manifest.resolve())
    except ValidationError as exc:
        print(json.dumps({"passed": False, "error": str(exc)}, ensure_ascii=False, indent=2), file=sys.stderr)
        return 1
    output = json.dumps(result, ensure_ascii=False, indent=2)
    if args.json:
        args.json.write_text(output + "\n", encoding="utf-8")
    print(output)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())