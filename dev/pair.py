#!/usr/bin/env python3
"""Explicitly pair the two local development instances; never overwrite existing grants."""

import hashlib
import json
import os
from pathlib import Path
import secrets
import uuid


def main():
    root = Path(__file__).resolve().parent
    versions = (13, 14)
    paths = {v: root / f"typo3-v{v}" / ".peer-config.json" for v in versions}
    if any(path.exists() for path in paths.values()):
        raise SystemExit("Peer configuration already exists. Edit existing grants explicitly; nothing changed.")
    identities = {v: str(uuid.uuid4()) for v in versions}
    tokens = {v: secrets.token_hex(32) for v in versions}
    for version in versions:
        other = 14 if version == 13 else 13
        origin = f"https://t3exchange-v{other}.ddev.site"
        config = {
            "enabled": True,
            "instance": identities[version],
            "incoming": {
                identities[other]: {
                    "enabled": True,
                    "tokenHash": hashlib.sha256(tokens[other].encode()).hexdigest(),
                    "sites": ["main"],
                    "requestsPerMinute": 120,
                }
            },
            "outgoing": {
                f"v{other}": {
                    "enabled": True,
                    "instance": identities[other],
                    "endpoint": origin + "/typo3-exchange/v1/resolve",
                    "origins": [origin, f"http://t3exchange-v{other}.ddev.site"],
                    "token": tokens[version],
                }
            },
        }
        descriptor = os.open(paths[version], os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(descriptor, "w") as stream:
            json.dump(config, stream, indent=2)
            stream.write("\n")
    print("Paired TYPO3 13 and 14 for local public-page resolution. Secrets were not printed.")


if __name__ == "__main__":
    main()
