#!/usr/bin/env python3
"""Prepare Testing app directories inside the existing DDEV projects; no development data is copied."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import uuid

root = Path(__file__).resolve().parents[2]
versions = (13, 14)
paths = {}
for version, app in ((13, 'exchange-testing'), (14, 'exchange-testing'), (14, 'exchange-testing-c')):
    project = root / 'dev' / f'typo3-v{version}'
    target = project / 'var' / app
    target.mkdir(parents=True, exist_ok=True)
    for name in ('vendor', 'composer.json', 'composer.lock', 'public'):
        link = target / name
        if not link.exists():
            link.symlink_to(Path('../..') / name)
    system = target / 'config' / 'system'
    system.mkdir(parents=True, exist_ok=True)
    (system / 'additional.php').write_text(
        "<?php\nrequire '/var/www/html/config/system/additional.php';\n")
    site = target / 'config' / 'sites' / 'main' / 'config.yaml'
    site.parent.mkdir(parents=True, exist_ok=True)
    if not site.exists():
        site.write_text((project / 'config' / 'sites' / 'main' / 'config.yaml').read_text())
    if app == 'exchange-testing':
        paths[version] = target / '.peer-config.json'
if not any(p.exists() for p in paths.values()):
    identities = {v: str(uuid.uuid4()) for v in versions}
    tokens = {v: secrets.token_hex(32) for v in versions}
    for version in versions:
        other = 14 if version == 13 else 13
        origin = f'https://t3exchange-v{other}-testing.ddev.site'
        data = {'enabled': True, 'instance': identities[version],
            'incoming': {identities[other]: {'enabled': True, 'tokenHash': hashlib.sha256(tokens[other].encode()).hexdigest(), 'sites': ['main'], 'requestsPerMinute': 120}},
            'outgoing': {f'v{other}': {'enabled': True, 'instance': identities[other], 'endpoint': origin + '/typo3-exchange/v1/resolve',
                'origins': [origin, f'http://t3exchange-v{other}-testing.ddev.site'], 'token': tokens[version]}},
        }
        with os.fdopen(os.open(paths[version], os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), 'w') as stream:
            json.dump(data, stream, indent=2)
elif not all(p.exists() for p in paths.values()):
    raise SystemExit('Testing-context pairing is incomplete. Repair the test peer files explicitly.')
print('Prepared separate Testing configuration and peer identities inside the existing projects.')
