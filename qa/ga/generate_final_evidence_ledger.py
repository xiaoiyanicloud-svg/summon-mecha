#!/usr/bin/env python3
"""Build the final machine-readable evidence ledger and SHA-256 index."""
from __future__ import annotations
import hashlib, json, pathlib

ROOT = pathlib.Path(__file__).resolve().parents[2]

CLAIMS = [
    {
        'claim_id': 'C-ENV-001',
        'claim': 'Host runs Windows/PHP 8.4.24 with fixed Python 3.12.14; PHP 8.3 is not installed.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'final_host_probe.log + environment-probe.log',
        'command': 'PowerShell host/PHP/Python probes',
        'raw_output_paths': ['qa/ga/final_host_probe.log', 'qa/ga/environment-probe.log'],
        'pass_or_fail': 'PASS_WITH_ENVIRONMENT_DEVIATION',
        'not_proven': 'PHP 8.3 target-runtime compatibility remains unverified.',
    },
    {
        'claim_id': 'C-FIVE-001',
        'claim': 'Exactly five run targets are registered; A2 is excluded; all five remain HARDWARE_UNVERIFIED and Production BLOCKED.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'TargetProfileService + GaSupportMatrixService + profiles',
        'command': 'php artisan tinker -> GaSupportMatrixService::build()',
        'raw_output_paths': ['qa/ga/final_support_matrix.json', 'qa/ga/final_deploy_tests_redteam_v2.log'],
        'pass_or_fail': 'PASS',
        'not_proven': 'A2 historical physical assets are not runtime targets and were not revalidated.',
    },
    {
        'claim_id': 'C-PROFILE-002',
        'claim': 'Unverified profiles do not claim E-Stop support or a vendor-approved physical stop method.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'five skill-target-profile JSON files + GaFiveModelContractTest',
        'command': "php artisan test ...GaFiveModelContractTest.php --filter='E-Stop'",
        'raw_output_paths': ['qa/ga/final_estop_profile_honesty.log', 'qa/ga/final_ga_contract_tests_v2.log'],
        'pass_or_fail': 'PASS',
        'not_proven': 'Actual E-Stop behavior on any robot.',
    },
    {
        'claim_id': 'C-HIL-001',
        'claim': 'HIL validator rejects sdk_return, sdk_true, bt_halt, cancel_requested, cloud_ack, script_assertion, mock, sil and non-independent observation sources.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'qa/ga/hil_evidence_validator.py + test_hil_evidence_validator.py',
        'command': 'python -m unittest -v test_hil_evidence_validator.py',
        'raw_output_paths': ['qa/ga/final_hil_validator_tests.log', 'qa/ga/hil_rejection_cli.log'],
        'pass_or_fail': 'PASS',
        'not_proven': 'Validator proves evidence-shape rejection, not that a physical HIL run occurred.',
    },
    {
        'claim_id': 'C-HIL-002',
        'claim': 'Legacy qa/hil-verification/hil_safety_report.json is Mock/hard-coded evidence and is INVALID_NON_HIL.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'MockHardwareSafetyInterlock + hard-coded decel_time_ms=12.5',
        'command': 'python qa/ga/claim_audit.py',
        'raw_output_paths': ['qa/ga/claim_audit_result.json', 'qa/ga/final_claim_audit.log'],
        'pass_or_fail': 'PASS_REJECTION',
        'not_proven': 'No real hardware E-Stop evidence exists.',
    },
    {
        'claim_id': 'C-EVT-001',
        'claim': '1000 duplicate command attempts create one task and 1000 duplicate ACK attempts create one Inbox fact.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'deploy_task idempotency + deploy_inbox_event unique(did,event_id)',
        'command': 'php qa/ga/benchmark_p0.php',
        'raw_output_paths': ['qa/ga/redteam_benchmark_p0.log'],
        'pass_or_fail': 'PASS',
        'not_proven': 'Production MySQL contention and multi-host behavior.',
    },
    {
        'claim_id': 'C-EVT-002',
        'claim': 'Same (did,event_id) with same payload is idempotent; same key with different payload returns 409 CONFLICT.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'DeployInboxService + AgentV2ExecutionTest',
        'command': 'php artisan test packages/Webkul/Shop/tests/Feature/API/AgentV2ExecutionTest.php',
        'raw_output_paths': ['qa/ga/final_agent_api_tests.log'],
        'pass_or_fail': 'PASS',
        'not_proven': 'Real device event-signature independence.',
    },
    {
        'claim_id': 'C-EVT-003',
        'claim': 'A lower event_seq cannot overwrite the current execution fact cursor.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'DeployTaskService::persistExecutionFact + stale event_seq test',
        'command': "php artisan test ...AgentV2ExecutionTest.php --filter='旧 event_seq'",
        'raw_output_paths': ['qa/ga/final_stale_event_seq_test.log', 'qa/ga/mutation_event_seq_check.log'],
        'pass_or_fail': 'PASS',
        'not_proven': 'Clock rollback and device journal erasure are not covered.',
    },
    {
        'claim_id': 'C-EXEC-001',
        'claim': 'Script-returned success and print-only actions do not become terminal success; absent independent stop evidence yields UNKNOWN and blocks uninstall.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'UnitreeG1Adapter + VendorPlanAdapter + test_five_model_adapters.py',
        'command': 'python -m unittest discover -s agent/tests',
        'raw_output_paths': ['qa/ga/final_agent_tests_redteam_v2.log', 'qa/ga/final_install_uninstall_mutex_tests.log'],
        'pass_or_fail': 'PASS',
        'not_proven': 'The explicit test observer is synthetic and is not physical HIL evidence.',
    },
    {
        'claim_id': 'C-MUTEX-001',
        'claim': 'Two real PHP workers competing for one outbox task produce exactly one claim and delivery_attempt=1.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'claimNextCommand lockForUpdate + OutboxLease',
        'command': 'php qa/ga/benchmark_claim_concurrency.php',
        'raw_output_paths': ['qa/ga/redteam_claim_concurrency.log'],
        'pass_or_fail': 'PASS',
        'not_proven': 'Multi-host DB/network partition behavior.',
    },
    {
        'claim_id': 'C-STREAM-001',
        'claim': 'A 500MiB package streams in 64KiB chunks with bounded memory growth.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'PackageManager.download_package',
        'command': 'python qa/ga/benchmark_stream_500mb.py',
        'raw_output_paths': ['qa/ga/redteam_stream_500mb.log'],
        'pass_or_fail': 'PASS',
        'not_proven': 'Production object storage/CDN and disk-full behavior.',
    },
    {
        'claim_id': 'C-SOAK-001',
        'claim': 'A 1800s software soak completed with 899 install/execute/uninstall cycles and no recorded errors or resource-threshold breach.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'qa/ga/soak_agent.py with MockAdapter and local HTTP',
        'command': 'python qa/ga/soak_agent.py --duration-seconds 1800 ...',
        'raw_output_paths': ['qa/ga/redteam_soak_30m_result.json', 'qa/ga/redteam_soak_30m_timeline.jsonl', 'qa/ga/redteam_soak_30m_console.log'],
        'pass_or_fail': 'PASS',
        'not_proven': 'MockAdapter and local HTTP are not SIL/HIL; soak p99 was not captured by this tool.',
    },
    {
        'claim_id': 'C-PERF-001',
        'claim': 'The local control-plane baseline exceeds the 300ms p95 gate and saturates at concurrency 2.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'benchmark_control_plane_saturation.py + PHP built-in server',
        'command': 'python qa/ga/benchmark_control_plane_saturation.py --main-requests 100 --level-requests 20 --levels 1,2,4,8',
        'raw_output_paths': ['qa/ga/redteam_control_plane_saturation_result.json', 'qa/ga/redteam_control_plane_saturation.log'],
        'pass_or_fail': 'FAIL',
        'not_proven': 'Production FPM/MySQL/OPcache/network SLO.',
    },
    {
        'claim_id': 'C-BUILD-001',
        'claim': 'Two fixed-input builds produced byte-identical artifacts.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'PortableSkillBuilder deterministic ZIP logic',
        'command': "php artisan test ...PortableSkillCompilerAcceptanceTest.php --filter='C-T17'",
        'raw_output_paths': ['qa/ga/ct17_deterministic_build_result.json', 'qa/ga/final_portable_compiler_tests_py312.log'],
        'pass_or_fail': 'PASS',
        'not_proven': 'Same-host determinism only; independent build farms and toolchain image digests remain unverified.',
    },
    {
        'claim_id': 'C-SUPPLY-001',
        'claim': 'Composer lockfile contains 76 unique known advisories: 3 critical, 24 high, 38 medium, 8 low, 3 unknown.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'composer.lock',
        'command': 'composer audit --locked --no-interaction --format=json',
        'raw_output_paths': ['qa/ga/composer_audit.log', 'qa/ga/composer_audit_summary.json'],
        'pass_or_fail': 'FAIL',
        'not_proven': 'Runtime reachability/exploitability in deployment; no dependency upgrade was performed.',
    },
    {
        'claim_id': 'C-SECRET-001',
        'claim': 'High-signal regex scan found 6 review items; no full-history secret scanner is available.',
        'evidence_level': 'EXPLORATORY_HYPOTHESIS',
        'code_or_source': 'qa/ga/secret_scan.py',
        'command': 'python qa/ga/secret_scan.py',
        'raw_output_paths': ['qa/ga/secret_scan_result.json', 'qa/ga/secret_scan.log'],
        'pass_or_fail': 'REVIEW_REQUIRED',
        'not_proven': 'No gitleaks/trivy/trufflehog history scan; a clean regex scan cannot prove secret absence.',
    },
    {
        'claim_id': 'C-SIL-001',
        'claim': 'G1 SIL artifacts are internally consistent, but the MuJoCo replay is NOT_RUN in the fixed Python 3.12 runtime.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT for artifact arithmetic; UNKNOWN for current replay',
        'code_or_source': 'qa/simulation/sil_simulation_report.json + mujoco_telemetry.csv',
        'command': 'python artifact cross-check; python run_mujoco_sil.py',
        'raw_output_paths': ['qa/ga/sil_csv_crosscheck_result.json', 'qa/ga/redteam_mujoco_sil.log', 'qa/simulation/sil_simulation_report.json'],
        'pass_or_fail': 'ARTIFACT_CROSSCHECK_PASS; SIL_REPLAY_NOT_RUN',
        'not_proven': 'No available MuJoCo package; no independent engine attestation or hardware evidence.',
    },
    {
        'claim_id': 'C-DYN-001',
        'claim': 'Legacy dynamics report is not Pinocchio/RNEA; it is a reduced-model arithmetic check with hard-coded clearance/inertia.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'verify_kinematics_dynamics.py',
        'command': 'python qa/ga/claim_audit.py',
        'raw_output_paths': ['qa/ga/claim_audit_result.json', 'qa/ga/redteam_kinematics_dynamics.log'],
        'pass_or_fail': 'DEMOTED',
        'not_proven': 'Full multibody inverse dynamics, collision mesh clearance, and true SIL.',
    },
    {
        'claim_id': 'C-DDS-001',
        'claim': 'Legacy DDS verifier is local struct/JCS-style layout arithmetic, not live DDS interoperability.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'verify_idl_layout.py',
        'command': 'python qa/ga/claim_audit.py',
        'raw_output_paths': ['qa/ga/claim_audit_result.json', 'qa/ga/redteam_dds_layout.log'],
        'pass_or_fail': 'DEMOTED',
        'not_proven': 'CycloneDDS participant, vendor IDL compiler, real QoS, DDS Security.',
    },
    {
        'claim_id': 'C-MUT-001',
        'claim': 'Three critical safety/security mutations were killed: physical-stop requirement, stale event_seq rejection, forbidden HIL source rejection.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'mutation runs with backup/restore',
        'command': 'PowerShell mutate -> targeted test -> restore + SHA check',
        'raw_output_paths': ['qa/ga/mutation_execution_fact_stop_check.log', 'qa/ga/mutation_event_seq_check.log', 'qa/ga/mutation_hil_forbidden_source_check.log'],
        'pass_or_fail': 'PASS',
        'not_proven': 'Mutation coverage is not exhaustive; no mutation framework ran over the full codebase.',
    },
    {
        'claim_id': 'C-SUPPLY-002',
        'claim': 'A deterministic CycloneDX inventory was generated for locked Composer packages and the fixed Python runtime.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'composer.lock + Python importlib.metadata',
        'command': 'python qa/ga/generate_sbom.py',
        'raw_output_paths': ['qa/ga/sbom.cdx.json', 'qa/ga/sbom_generation.log'],
        'pass_or_fail': 'PASS',
        'not_proven': 'Inventory presence does not remediate vulnerabilities or prove licenses/export compliance.',
    },
    {
        'claim_id': 'C-BLOCK-001',
        'claim': 'Ten external or environmental blockers are explicitly recorded and remain BLOCKED/NOT_RUN.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': 'qa/ga/blocked_items.json',
        'command': 'python JSON parse',
        'raw_output_paths': ['qa/ga/blocked_items.json', 'qa/hil-verification/EVIDENCE_STATUS.json', 'qa/ga/npm_audit.log'],
        'pass_or_fail': 'BLOCKED',
        'not_proven': 'All listed external prerequisites remain unavailable.',
    },
    {
        'claim_id': 'C-VERIFY-001',
        'claim': 'Current PHP lint, routes, migrations, contract-check and data-preflight all passed on the isolated test database.',
        'evidence_level': 'HIGH_CONFIDENCE_FACT',
        'code_or_source': '42 changed/new PHP files + Laravel artisan gates',
        'command': 'php -l; php artisan route:list; migrate:status; ecosystem:contract-check; ecosystem:data-preflight',
        'raw_output_paths': ['qa/ga/final_gate_checks_redteam.log', 'qa/ga/final_php_lint_v2.log'],
        'pass_or_fail': 'PASS_WITH_PHP_8_4_DEVIATION',
        'not_proven': 'PHP 8.3 target runtime and production FPM remain unverified.',
    },
]


def sha256(path: pathlib.Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main() -> int:
    ledger = {'schema': 'summon-mecha/evidence-ledger/v1', 'generated_on': '2026-11-05', 'claims': []}
    missing = []
    for claim in CLAIMS:
        record = {k: v for k, v in claim.items() if k != 'raw_output_paths'}
        record['evidence'] = []
        for rel in claim['raw_output_paths']:
            path = ROOT / rel
            if not path.is_file():
                missing.append(rel)
                record['evidence'].append({'path': rel, 'sha256': None, 'missing': True})
            else:
                record['evidence'].append({'path': rel, 'sha256': sha256(path), 'missing': False})
        ledger['claims'].append(record)
    ledger['missing_evidence'] = missing
    out = ROOT / 'qa' / 'ga' / 'final_evidence_ledger.json'
    out.write_text(json.dumps(ledger, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print(json.dumps({'claims': len(ledger['claims']), 'missing_evidence': missing, 'ledger_sha256': sha256(out)}, ensure_ascii=False, indent=2))
    return 1 if missing else 0


if __name__ == '__main__':
    raise SystemExit(main())