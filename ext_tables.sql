CREATE TABLE tx_typo3totypo3_identity (
    page_uid int unsigned NOT NULL,
    uuid varchar(36) NOT NULL,
    PRIMARY KEY (page_uid),
    UNIQUE KEY uuid (uuid)
);

CREATE TABLE tx_typo3totypo3_destination (
    reference_key varchar(64) NOT NULL,
    instance_uuid varchar(36) NOT NULL,
    page_uuid varchar(36) NOT NULL,
    language_id int unsigned NOT NULL DEFAULT 0,
    status varchar(16) NOT NULL,
    url text NOT NULL,
    checked_at int unsigned NOT NULL DEFAULT 0,
    generation varchar(32) NOT NULL DEFAULT '',
    lease_token varchar(32) NOT NULL DEFAULT '',
    lease_until int unsigned NOT NULL DEFAULT 0,
    next_refresh int unsigned NOT NULL DEFAULT 0,
    attempts int unsigned NOT NULL DEFAULT 0,
    refresh_paused smallint unsigned NOT NULL DEFAULT 0,
    peer_hash varchar(64) NOT NULL DEFAULT '',
    refresh_error varchar(32) NOT NULL DEFAULT '',
    KEY due_refresh (instance_uuid, refresh_paused, next_refresh),
    PRIMARY KEY (reference_key)
);

CREATE TABLE tx_typo3totypo3_link_outcome (
    source_key varchar(64) NOT NULL,
    table_name varchar(255) NOT NULL,
    record_uid int unsigned NOT NULL,
    field_name varchar(255) NOT NULL,
    workspace_id int unsigned NOT NULL DEFAULT 0,
    value_hash varchar(64) NOT NULL,
    status varchar(16) NOT NULL,
    reference_key varchar(64) NOT NULL DEFAULT '',
    checked_at int unsigned NOT NULL DEFAULT 0,
    record_hash varchar(64) NOT NULL DEFAULT '',
    live_uid int unsigned NOT NULL DEFAULT 0,
    generation varchar(32) NOT NULL DEFAULT '',
    job_status varchar(16) NOT NULL DEFAULT '',
    created_at int unsigned NOT NULL DEFAULT 0,
    next_attempt int unsigned NOT NULL DEFAULT 0,
    attempts int unsigned NOT NULL DEFAULT 0,
    lease_token varchar(32) NOT NULL DEFAULT '',
    lease_until int unsigned NOT NULL DEFAULT 0,
    KEY due_jobs (job_status, next_attempt, lease_until),
    PRIMARY KEY (source_key)
);
