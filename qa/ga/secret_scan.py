#!/usr/bin/env python3
"""High-signal secret scan. Supplements, but does not replace, gitleaks/Trivy."""
from __future__ import annotations
import json, pathlib, re, subprocess

ROOT = pathlib.Path(__file__).resolve().parents[2]
SKIP = {'.git', 'vendor', 'node_modules', 'storage', 'dist', 'unpackage'}
EXTS = {'.php','.py','.js','.mjs','.ts','.json','.yaml','.yml','.md','.env','.sh','.ps1','.bat','.xml','.toml','.ini','.conf','.pem','.key'}
PATTERNS = [
    ('private_key_block', re.compile(r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----')),
    ('aws_access_key', re.compile(r'\bAKIA[0-9A-Z]{16}\b')),
    ('github_token', re.compile(r'\bgh[pousr]_[A-Za-z0-9]{30,}\b')),
    ('openai_key', re.compile(r'\bsk-[A-Za-z0-9_-]{32,}\b')),
    ('stripe_live_key', re.compile(r'\bsk_live_[A-Za-z0-9]{16,}\b')),
    ('slack_token', re.compile(r'\bxox[baprs]-[A-Za-z0-9-]{10,}\b')),
    ('google_api_key', re.compile(r'\bAIza[0-9A-Za-z_-]{35}\b')),
    ('assigned_secret', re.compile(r'(?i)\b(?:password|passwd|secret|api[_-]?key|access[_-]?token)\b\s*[:=]\s*["\']?([A-Za-z0-9+/=_.:@-]{16,})')),
]
PLACEHOLDER = re.compile(r'(?i)(example|dummy|fake|test|change[-_]?me|your[-_]|placeholder|redacted|<[^>]+>|\$\{|env\()')


def tracked_and_untracked():
    commands = [
        ['git', '-C', str(ROOT), 'ls-files', '-z'],
        ['git', '-C', str(ROOT), 'ls-files', '-z', '--others', '--exclude-standard'],
    ]
    seen = set()
    for cmd in commands:
        raw = subprocess.check_output(cmd)
        for item in raw.split(b'\0'):
            if not item:
                continue
            rel = item.decode('utf-8', errors='replace')
            path = ROOT / rel
            if path in seen or not path.is_file():
                continue
            if any(part in SKIP for part in path.relative_to(ROOT).parts):
                continue
            if path.suffix.lower() not in EXTS and path.name not in {'.env', '.env.example'}:
                continue
            seen.add(path)
            yield path


def main():
    findings = []
    files_scanned = 0
    for path in tracked_and_untracked():
        files_scanned += 1
        try:
            text = path.read_text(encoding='utf-8', errors='replace')
        except OSError:
            continue
        for line_no, line in enumerate(text.splitlines(), 1):
            for name, regex in PATTERNS:
                match = regex.search(line)
                if not match:
                    continue
                captured = match.group(1) if match.lastindex else match.group(0)
                if name != 'private_key_block' and PLACEHOLDER.search(captured):
                    continue
                findings.append({
                    'pattern': name,
                    'path': str(path.relative_to(ROOT)).replace('\\', '/'),
                    'line': line_no,
                    'redacted': '<redacted>',
                })
    result = {
        'evidence_level': 'HIGH_SIGNAL_REGEX_SCAN_ONLY',
        'tool_availability': {'gitleaks': False, 'trivy': False, 'trufflehog': False},
        'files_scanned': files_scanned,
        'finding_count': len(findings),
        'findings': findings,
        'not_proven': 'A clean regex scan cannot prove absence of secrets; production secret scanning and history scan were not available.',
    }
    out = ROOT / 'qa' / 'ga' / 'secret_scan_result.json'
    out.write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 1 if findings else 0


if __name__ == '__main__':
    raise SystemExit(main())