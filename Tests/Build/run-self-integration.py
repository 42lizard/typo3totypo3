#!/usr/bin/env python3
"""Exercise the same-version HTTP path using self grants in the existing Testing apps."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess

root = Path(__file__).resolve().parents[2]
for version in (13, 14):
    project = root / 'dev' / f'typo3-v{version}'
    path = project / 'var/exchange-testing/.peer-config.json'
    backup = path.with_name('.peer-config.self-test-backup.json')
    original = path.read_bytes()
    config = json.loads(original)
    if not config['enabled']:
        raise SystemExit('Testing peer configuration must be enabled.')
    token = secrets.token_hex(32)
    origin = f'https://t3exchange-v{version}-testing.ddev.site'
    config['incoming'][config['instance']] = {'enabled': True, 'tokenHash': hashlib.sha256(token.encode()).hexdigest(), 'sites': ['main'], 'requestsPerMinute': 120}
    config['outgoing'] = {'self': {'enabled': True, 'instance': config['instance'], 'token': token,
        'origins': [origin], 'endpoint': origin + '/typo3-exchange/v1/resolve'}}
    # Exclusive creation refuses an interrupted prior run; the backup never contains Development data.
    with os.fdopen(os.open(backup, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), 'wb') as stream:
        stream.write(original)
    try:
        path.write_text(json.dumps(config))
        print(f'Running same-version HTTPS workflows: TYPO3 {version} → {version}', flush=True)
        subprocess.run(['ddev', 'exec', 'env', 'TYPO3_CONTEXT=Testing',
            'TYPO3_PATH_APP=/var/www/html/var/exchange-testing', 'TYPO3_PATH_ROOT=/var/www/html/public',
            'TYPO3_EXCHANGE_CONFIG=/var/www/html/var/exchange-testing/.peer-config.json',
            'EXCHANGE_TEST_ORIGIN=' + origin, 'vendor/bin/phpunit',
            '-c', '/opt/typo3-to-typo3/Tests/Integration/phpunit.xml'],
            cwd=project, check=True)
    finally:
        path.write_bytes(original)
        backup.unlink()
