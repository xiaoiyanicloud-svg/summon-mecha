import copy
import hashlib
import json
import pathlib
import tempfile
import unittest

from hil_evidence_validator import REQUIRED_SCENARIOS, ValidationError, clopper_pearson_upper, validate_manifest


class HILEvidenceValidatorTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="hil_gate_")
        self.root = pathlib.Path(self.temp.name)
        self.raw = self.root / "raw.log"
        self.raw.write_text("physical observer log\n", encoding="utf-8")
        self.replay = self.root / "replay.py"
        self.replay.write_text("print('replay')\n", encoding="utf-8")
        self.raw_hash = hashlib.sha256(self.raw.read_bytes()).hexdigest()
        self.replay_hash = hashlib.sha256(self.replay.read_bytes()).hexdigest()

    def tearDown(self):
        self.temp.cleanup()

    def _manifest(self):
        return {
            "schema": "summon-mecha/hil-evidence/v1",
            "claim_level": "HIL",
            "target": {
                "vendor": "unitree",
                "model_code": "unitree_g1",
                "hardware_serial_hash": "sha256:" + "a" * 64,
                "firmware_version": "fw-1",
                "sdk_name": "unitree_sdk2py",
                "sdk_version": "2.0.0",
                "agent_version": "0.3.0",
                "artifact_sha256": "b" * 64,
                "target_profile_digest": "sha256:" + "c" * 64,
            },
            "run": {
                "started_at": "2026-11-04T08:00:00+08:00",
                "ended_at": "2026-11-04T09:00:00+08:00",
                "operator_id": "qa-operator-1",
                "facility_id": "lab-shanghai-1",
                "observation_source": "independent_encoder_and_contact_sensor",
                "actual_started": True,
                "actual_stopped": True,
                "physical_stop_observed": True,
                "estop_independent": True,
                "raw_log": {"path": "raw.log", "sha256": self.raw_hash},
                "replay_script": {"path": "replay.py", "sha256": self.replay_hash},
            },
            "scenarios": [
                {
                    "id": scenario,
                    "result": "PASS",
                    "observed_by": "independent_encoder_and_contact_sensor",
                    "n_trials": 10,
                    "failures": 0,
                    "confidence_level": 0.95,
                    "max_failure_rate_upper_bound": 0.30,
                    "evidence": {"path": "raw.log", "sha256": self.raw_hash},
                }
                for scenario in sorted(REQUIRED_SCENARIOS)
            ],
        }

    def _write(self, manifest):
        path = self.root / "evidence.json"
        path.write_text(json.dumps(manifest), encoding="utf-8")
        return path

    def test_clopper_pearson_zero_failure_bound(self):
        self.assertAlmostEqual(clopper_pearson_upper(0, 10, 0.95), 0.258865, places=5)

    def test_clopper_pearson_nonzero_failure_bound(self):
        self.assertAlmostEqual(clopper_pearson_upper(1, 10, 0.95), 0.3941633024, places=8)
    def test_valid_hil_bundle_passes(self):
        result = validate_manifest(self._write(self._manifest()))
        self.assertTrue(result["passed"])
        self.assertEqual(result["claim_level"], "HIL")

    def test_sdk_return_cannot_be_stop_observer(self):
        manifest = self._manifest()
        manifest["run"]["observation_source"] = "sdk_return"
        with self.assertRaises(ValidationError) as ctx:
            validate_manifest(self._write(manifest))
        self.assertIn("forbidden stop observation source", str(ctx.exception))

    def test_all_forbidden_stop_sources_rejected(self):
        for source in (
            "sdk_return",
            "sdk_true",
            "bt_halt",
            "cancel_requested",
            "cloud_ack",
            "script_assertion",
            "mock",
            "sil",
            "SDK return (wrapped)",
            "bt-halt",
            "cloud ack",
        ):
            with self.subTest(source=source):
                manifest = self._manifest()
                manifest["run"]["observation_source"] = source
                with self.assertRaises(ValidationError) as ctx:
                    validate_manifest(self._write(manifest))
                self.assertIn("forbidden stop observation source", str(ctx.exception))

    def test_non_independent_observation_source_rejected(self):
        manifest = self._manifest()
        manifest["run"]["observation_source"] = "operator_visual_claim"
        with self.assertRaises(ValidationError) as ctx:
            validate_manifest(self._write(manifest))
        self.assertIn("independent physical observation", str(ctx.exception))
    def test_missing_required_scenario_fails(self):
        manifest = self._manifest()
        manifest["scenarios"] = manifest["scenarios"][:-1]
        with self.assertRaises(ValidationError) as ctx:
            validate_manifest(self._write(manifest))
        self.assertIn("missing HIL scenarios", str(ctx.exception))

    def test_evidence_hash_mismatch_fails(self):
        manifest = self._manifest()
        manifest["run"]["raw_log"]["sha256"] = "d" * 64
        with self.assertRaises(ValidationError) as ctx:
            validate_manifest(self._write(manifest))
        self.assertIn("sha256 mismatch", str(ctx.exception))

    def test_a2_and_ga_without_limits_are_rejected(self):
        manifest = self._manifest()
        manifest["claim_level"] = "GA"
        manifest["production_ready_claim"] = True
        manifest["field_validation"] = True
        manifest["open_safety_findings"] = []
        manifest["unresolved_limits"] = ["torque"]
        with self.assertRaises(ValidationError) as ctx:
            validate_manifest(self._write(manifest))
        self.assertIn("unresolved physical limits", str(ctx.exception))

        bad_target = copy.deepcopy(manifest)
        bad_target["claim_level"] = "HIL"
        bad_target["target"]["model_code"] = "agibot_a2"
        with self.assertRaises(ValidationError) as ctx2:
            validate_manifest(self._write(bad_target))
        self.assertIn("five GA targets", str(ctx2.exception))


if __name__ == "__main__":
    unittest.main()