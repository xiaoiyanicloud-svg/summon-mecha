"""P0 五机型 Adapter、fail-closed 与 nonce 防重放测试。"""

import json
import os
import tempfile
import time
import unittest
from pathlib import Path

from zhjj_agent.adapters.agibot_a3 import AgibotA3Adapter
from zhjj_agent.adapters.agibot_x2 import AgibotX2Adapter
from zhjj_agent.adapters.engineai_t800 import EngineAIT800Adapter
from zhjj_agent.adapters.registry import create_adapter
from zhjj_agent.adapters.unitree_g1 import UnitreeG1Adapter
from zhjj_agent.adapters.unitree_r1 import UnitreeR1Adapter
from zhjj_agent.storage import Storage


class SyntheticStopObserverForContractTests:
    """Test-only observer. Never evidence of physical hardware stop."""

    def observe_execution(self, execution_id):
        from zhjj_agent.stop_observation import synthetic_observation
        return {
            "is_stopped": True,
            "status": "SUCCEEDED",
            "error_msg": None,
            "observation": synthetic_observation(execution_id),
        }


class TestFiveModelAdapters(unittest.TestCase):
    def test_registry_returns_independent_adapters_and_rejects_a2(self):
        with tempfile.TemporaryDirectory() as tmp:
            expected = {
                'unitree_r1': UnitreeR1Adapter,
                'agibot_x2': AgibotX2Adapter,
                'agibot_a3': AgibotA3Adapter,
                'engineai_t800': EngineAIT800Adapter,
            }
            for model_code, adapter_type in expected.items():
                adapter = create_adapter(model_code, install_dir=tmp)
                self.assertIsInstance(adapter, adapter_type)
                self.assertEqual(adapter.model_code, model_code)
                self.assertFalse(adapter.is_hardware_verified)

            with self.assertRaises(ValueError):
                create_adapter('agibot_a2', install_dir=tmp)

    def _install_fixture(self, adapter, tmp, skill_id='skill-1'):
        root = Path(tmp) / 'skills' / skill_id / 'v1.0.0'
        (root / 'bindings').mkdir(parents=True)
        digest = 'sha256:' + 'a' * 64
        manifest = {
            'target_model_code': adapter.model_code,
            'entrypoint': 'action.py',
            'runtime': {
                'target_model': adapter.model_code,
                'target_profile_digest': digest,
            },
        }
        binding = {
            'target_model_code': adapter.model_code,
            'target_profile_digest': digest,
            'hardware_verification_status': 'HARDWARE_UNVERIFIED',
        }
        (root / 'manifest.json').write_text(json.dumps(manifest), encoding='utf-8')
        (root / 'bindings' / 'target_binding.json').write_text(json.dumps(binding), encoding='utf-8')
        (root / 'action.py').write_text(
            'def run(context=None):\n'
            '    return {"status": "SUCCEEDED", "stopped": True}\n',
            encoding='utf-8',
        )
        return root, manifest

    def test_four_adapters_install_observe_and_execute_separately(self):
        adapters = [
            UnitreeR1Adapter,
            AgibotX2Adapter,
            AgibotA3Adapter,
            EngineAIT800Adapter,
        ]
        for index, adapter_type in enumerate(adapters):
            with self.subTest(adapter=adapter_type.__name__), tempfile.TemporaryDirectory() as tmp:
                adapter = adapter_type(app_dir=tmp, sdk_stub=object(), stop_observer=SyntheticStopObserverForContractTests())
                skill_id = f'skill-{index}'
                root, manifest = self._install_fixture(adapter, tmp, skill_id)
                self.assertTrue(adapter.install(skill_id, '1.0.0', str(root), manifest))
                observed = adapter.observe(skill_id)
                self.assertTrue(observed['installed'])
                self.assertTrue(observed['enabled'])

                execution_id = f'exec-{index}'
                self.assertTrue(adapter.execute_skill(execution_id, skill_id, 'plan-digest', {}))
                deadline = time.time() + 2
                while time.time() < deadline:
                    result = adapter.observe_execution(execution_id)
                    if result['is_stopped']:
                        break
                    time.sleep(0.01)
                self.assertEqual(result['status'], 'SUCCEEDED')
                self.assertTrue(result['is_stopped'])

    def test_success_without_stop_evidence_is_unknown(self):
        with tempfile.TemporaryDirectory() as tmp:
            adapter = AgibotX2Adapter(app_dir=tmp, sdk_stub=object())
            root, manifest = self._install_fixture(adapter, tmp)
            (root / 'action.py').write_text(
                'def run(context=None):\n'
                '    return {"status": "SUCCEEDED", "stopped": False}\n',
                encoding='utf-8',
            )
            self.assertTrue(adapter.install('skill-1', '1.0.0', str(root), manifest))
            self.assertTrue(adapter.execute_skill('exec-no-stop', 'skill-1', None, {}))
            deadline = time.time() + 2
            while time.time() < deadline:
                observed = adapter.observe_execution('exec-no-stop')
                if observed['status'] != 'RUNNING':
                    break
                time.sleep(0.01)
            self.assertEqual(observed['status'], 'UNKNOWN')
            self.assertFalse(observed['is_stopped'])
    def test_g1_script_success_is_not_stop_evidence(self):
        with tempfile.TemporaryDirectory() as tmp:
            adapter = UnitreeG1Adapter(app_dir=tmp, sdk_stub=object())
            root, manifest = self._install_fixture(adapter, tmp)
            self.assertTrue(adapter.install('skill-1', '1.0.0', str(root), manifest))
            self.assertTrue(adapter.execute_skill('exec-g1-no-observer', 'skill-1', None, {}))
            deadline = time.time() + 2
            while time.time() < deadline:
                observed = adapter.observe_execution('exec-g1-no-observer')
                if observed['status'] != 'RUNNING':
                    break
                time.sleep(0.01)
            self.assertEqual(observed['status'], 'UNKNOWN')
            self.assertFalse(observed['is_stopped'])

    def test_g1_print_only_action_is_not_success(self):
        with tempfile.TemporaryDirectory() as tmp:
            adapter = UnitreeG1Adapter(app_dir=tmp, sdk_stub=object())
            root, manifest = self._install_fixture(adapter, tmp)
            (root / 'action.py').write_text("print('pretend success')\n", encoding='utf-8')
            self.assertTrue(adapter.install('skill-1', '1.0.0', str(root), manifest))
            self.assertTrue(adapter.execute_skill('exec-g1-print-only', 'skill-1', None, {}))
            deadline = time.time() + 2
            while time.time() < deadline:
                observed = adapter.observe_execution('exec-g1-print-only')
                if observed['status'] != 'RUNNING':
                    break
                time.sleep(0.01)
            self.assertEqual(observed['status'], 'UNKNOWN')
            self.assertFalse(observed['is_stopped'])
    def test_cross_model_manifest_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            adapter = AgibotX2Adapter(app_dir=tmp, sdk_stub=object())
            root, manifest = self._install_fixture(adapter, tmp)
            manifest['target_model_code'] = 'agibot_a3'
            self.assertFalse(adapter.install('skill-1', '1.0.0', str(root), manifest))

    def test_cancel_requires_observed_stop(self):
        with tempfile.TemporaryDirectory() as tmp:
            adapter = EngineAIT800Adapter(app_dir=tmp, sdk_stub=object(), stop_observer=SyntheticStopObserverForContractTests())
            root, manifest = self._install_fixture(adapter, tmp)
            self.assertTrue(adapter.install('skill-1', '1.0.0', str(root), manifest))
            (root / 'action.py').write_text(
                'import time\n'
                'def run(context=None):\n'
                '    while context is not None and not context.is_canceled():\n'
                '        time.sleep(0.01)\n'
                '    return {"status": "CANCELED", "stopped": True}\n',
                encoding='utf-8',
            )
            self.assertTrue(adapter.execute_skill('exec-long', 'skill-1', None, {}))
            self.assertTrue(adapter.cancel_execution('exec-long'))
            observed = adapter.observe_execution('exec-long')
            self.assertTrue(observed['is_stopped'])
            self.assertEqual(observed['status'], 'CANCELED')

    def test_uninstall_refuses_without_observed_stop(self):
        class NeverStoppedObserver:
            def observe_execution(self, execution_id):
                return {"is_stopped": False, "status": "RUNNING", "error_msg": "not observed"}

        with tempfile.TemporaryDirectory() as tmp:
            adapter = AgibotX2Adapter(app_dir=tmp, sdk_stub=object(), stop_observer=NeverStoppedObserver())
            root, manifest = self._install_fixture(adapter, tmp)
            self.assertTrue(adapter.install('skill-1', '1.0.0', str(root), manifest))
            self.assertTrue(adapter.execute_skill('exec-live', 'skill-1', None, {}))
            self.assertFalse(adapter.uninstall('skill-1', str(root)))
            self.assertTrue(adapter.observe('skill-1')['installed'])

    def test_g1_uninstall_refuses_without_observed_stop(self):
        class NeverStoppedObserver:
            def observe_execution(self, execution_id):
                return {"is_stopped": False, "status": "RUNNING", "error_msg": "not observed"}

        with tempfile.TemporaryDirectory() as tmp:
            adapter = UnitreeG1Adapter(app_dir=tmp, sdk_stub=object(), stop_observer=NeverStoppedObserver())
            root, manifest = self._install_fixture(adapter, tmp)
            self.assertTrue(adapter.install('skill-1', '1.0.0', str(root), manifest))
            self.assertTrue(adapter.execute_skill('exec-live', 'skill-1', None, {}))
            self.assertFalse(adapter.uninstall('skill-1', str(root)))
            self.assertTrue(adapter.observe('skill-1')['installed'])
    def test_nonce_replay_is_scoped_across_tasks(self):
        with tempfile.TemporaryDirectory() as tmp:
            storage = Storage(tmp)
            self.assertEqual(storage.claim_command_nonce('nonce-1', 'task-1'), 'new')
            self.assertEqual(storage.claim_command_nonce('nonce-1', 'task-1'), 'same')
            self.assertEqual(storage.claim_command_nonce('nonce-1', 'task-2'), 'replay')


if __name__ == '__main__':
    unittest.main()