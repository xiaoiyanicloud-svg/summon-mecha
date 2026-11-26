#!/usr/bin/env python3
"""Generate a deterministic CycloneDX inventory from locked/runtime manifests."""
from __future__ import annotations
import hashlib, importlib.metadata, json, pathlib, re, sys, uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
LOCK = ROOT / 'my-bagisto-store' / 'composer.lock'


def digest(path: pathlib.Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main() -> int:
    lock_bytes = LOCK.read_bytes()
    lock = json.loads(lock_bytes)
    components = []
    for group, dev in (('packages', False), ('packages-dev', True)):
        for pkg in lock.get(group, []):
            name = pkg.get('name', '')
            version = pkg.get('version', '')
            ref = 'pkg:composer/' + name.replace('/', '/') + '@' + version
            components.append({
                'type': 'library',
                'bom-ref': ref,
                'group': name.split('/')[0] if '/' in name else '',
                'name': name.split('/')[-1],
                'version': version,
                'scope': 'optional' if dev else 'required',
                'purl': ref,
                'hashes': ([{'alg': 'SHA-1', 'content': pkg['dist']['shasum']}]
                           if isinstance(pkg.get('dist'), dict) and pkg['dist'].get('shasum') else []),
                'licenses': [{'license': {'name': lic}} for lic in (pkg.get('license') or []) if isinstance(lic, str)],
                'properties': [{'name': 'composer.name', 'value': name}],
            })

    python_version = sys.version.split()[0]
    for dist in importlib.metadata.distributions():
        name = dist.metadata.get('Name')
        version = dist.version
        if not name or not version:
            continue
        ref = f'pkg:pypi/{re.sub(r"[-_.]+", "-", name).lower()}@{version}'
        components.append({
            'type': 'library',
            'bom-ref': ref,
            'name': name,
            'version': version,
            'scope': 'required',
            'purl': ref,
            'properties': [{'name': 'python.runtime', 'value': python_version}],
        })

    components.sort(key=lambda item: item['bom-ref'])
    serial = uuid.uuid5(uuid.NAMESPACE_URL, 'summon-mecha/ga/sbom/' + digest(LOCK)).urn
    sbom = {
        'bomFormat': 'CycloneDX',
        'specVersion': '1.5',
        'serialNumber': serial,
        'version': 1,
        'metadata': {
            'tools': [{'vendor': 'Summon Mecha', 'name': 'qa/ga/generate_sbom.py', 'version': '1.0'}],
            'component': {
                'type': 'application',
                'bom-ref': 'application:summon-mecha',
                'name': 'summon-mecha',
                'version': 'working-tree-2026-11-04',
            },
            'properties': [
                {'name': 'composer.lock.sha256', 'value': digest(LOCK)},
                {'name': 'php.version', 'value': '8.4.24'},
                {'name': 'python.version', 'value': python_version},
            ],
        },
        'components': components,
    }
    out = ROOT / 'qa' / 'ga' / 'sbom.cdx.json'
    out.write_text(json.dumps(sbom, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    summary = {
        'components': len(components),
        'composer_required': sum(1 for c in components if c['bom-ref'].startswith('pkg:composer/') and c['scope'] == 'required'),
        'composer_optional_dev': sum(1 for c in components if c['bom-ref'].startswith('pkg:composer/') and c['scope'] == 'optional'),
        'python_packages': sum(1 for c in components if c['bom-ref'].startswith('pkg:pypi/')),
        'sbom_sha256': digest(out),
        'composer_lock_sha256': digest(LOCK),
    }
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())