# Manage environment-bound connections in the TYPO3 backend

Administrators now manage peer connections in TYPO3 instead of editing JSON,
superseding the file-managed connection configuration in the original design.
Store the configuration using authenticated encryption derived from TYPO3's
existing `SYS.encryptionKey` and application context; a database-only clone with
a different key or context cannot activate the copied credentials. Outgoing
pairing tokens are shown only on generation, incoming tokens are stored as hashes,
and explicit legacy import preserves identities; full clones containing the same
key and context require operational isolation before startup.
