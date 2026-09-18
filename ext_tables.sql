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

CREATE TABLE tx_typo3totypo3_connections (
    environment_id varchar(64) NOT NULL,
    payload mediumtext NOT NULL,
    revision varchar(32) NOT NULL,
    PRIMARY KEY (environment_id)
);

CREATE TABLE tx_typo3totypo3_notification (
    receipt_key varchar(64) NOT NULL,
    revision int unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (receipt_key)
);

CREATE TABLE tx_typo3totypo3_usage (
    scope_key varchar(64) NOT NULL,
    page_uuid varchar(36) NOT NULL,
    language_id int unsigned NOT NULL DEFAULT 0,
    site_identifier varchar(100) NOT NULL DEFAULT '',
    revision int unsigned NOT NULL DEFAULT 0,
    present smallint unsigned NOT NULL DEFAULT 0,
    reported_at int unsigned NOT NULL DEFAULT 0,
    registered_revision int unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (scope_key,page_uuid,language_id)
);

CREATE TABLE tx_typo3totypo3_usage_pair (
    scope_key varchar(64) NOT NULL,
    peer_name varchar(64) NOT NULL DEFAULT '',
    instance_uuid varchar(36) NOT NULL DEFAULT '',
    environment_uuid varchar(36) NOT NULL DEFAULT '',
    floor_revision int unsigned NOT NULL DEFAULT 0,
    snapshot_uuid varchar(36) NOT NULL DEFAULT '',
    snapshot_revision int unsigned NOT NULL DEFAULT 0,
    snapshot_updated int unsigned NOT NULL DEFAULT 0,
    snapshot_grant varchar(64) NOT NULL DEFAULT '',
    PRIMARY KEY (scope_key)
);

CREATE TABLE tx_typo3totypo3_worker (
    uid int unsigned NOT NULL,
    lease_token varchar(32) NOT NULL DEFAULT '',
    lease_until int unsigned NOT NULL DEFAULT 0,
    peer_cursor int unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (uid)
);

CREATE TABLE tx_typo3totypo3_usage_stage (
    scope_key varchar(64) NOT NULL,
    page_uuid varchar(36) NOT NULL,
    language_id int unsigned NOT NULL DEFAULT 0,
    site_identifier varchar(100) NOT NULL DEFAULT '',
    PRIMARY KEY (scope_key,page_uuid,language_id)
);

CREATE TABLE tx_typo3totypo3_source_usage (
    source_key varchar(64) NOT NULL,
    reference_key varchar(64) NOT NULL,
    instance_uuid varchar(36) NOT NULL,
    page_uuid varchar(36) NOT NULL,
    language_id int unsigned NOT NULL DEFAULT 0,
    epoch varchar(32) NOT NULL DEFAULT '',
    PRIMARY KEY (source_key,reference_key),
    KEY peer_reference (instance_uuid,page_uuid,language_id)
);
CREATE TABLE tx_typo3totypo3_source_dirty (
    source_key varchar(64) NOT NULL,
    table_name varchar(255) NOT NULL,
    record_uid int unsigned NOT NULL DEFAULT 0,
    generation varchar(32) NOT NULL,
    PRIMARY KEY (source_key)
);
CREATE TABLE tx_typo3totypo3_source_scan (
    uid int unsigned NOT NULL,
    epoch varchar(32) NOT NULL DEFAULT '',
    tables_json text NOT NULL DEFAULT '',
    request_revision int unsigned NOT NULL DEFAULT 0,
    scan_request int unsigned NOT NULL DEFAULT 0,
    table_index int unsigned NOT NULL DEFAULT 0,
    last_uid int unsigned NOT NULL DEFAULT 0,
    completed_at int unsigned NOT NULL DEFAULT 0,
    indexed_at int unsigned NOT NULL DEFAULT 0,
    started_at int unsigned NOT NULL DEFAULT 0,
    lease_token varchar(32) NOT NULL DEFAULT '',
    lease_until int unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (uid)
);

CREATE TABLE tx_typo3totypo3_delivery_pair (
    scope_key varchar(64) NOT NULL,
    sequence_number int unsigned NOT NULL DEFAULT 0,
    lease_token varchar(32) NOT NULL DEFAULT '',
    lease_until int unsigned NOT NULL DEFAULT 0,
    paused smallint unsigned NOT NULL DEFAULT 0,
    attempts int unsigned NOT NULL DEFAULT 0,
    next_attempt int unsigned NOT NULL DEFAULT 0,
    first_failure int unsigned NOT NULL DEFAULT 0,
    accepted_at int unsigned NOT NULL DEFAULT 0,
    last_error varchar(32) NOT NULL DEFAULT '',
    PRIMARY KEY (scope_key)
);
CREATE TABLE tx_typo3totypo3_delivery (
    scope_key varchar(64) NOT NULL,
    message_key varchar(64) NOT NULL,
    snapshot_uuid varchar(36) NOT NULL DEFAULT '',
    completion smallint unsigned NOT NULL DEFAULT 0,
    not_before int unsigned NOT NULL DEFAULT 0,
    incomplete smallint unsigned NOT NULL DEFAULT 0,
    sequence_number int unsigned NOT NULL DEFAULT 0,
    payload text NOT NULL,
    created_at int unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (scope_key,message_key),
    KEY delivery_order (scope_key,sequence_number)
);

CREATE TABLE tx_typo3totypo3_report_state (
    scope_key varchar(64) NOT NULL,
    snapshot_at int unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (scope_key)
);
CREATE TABLE tx_typo3totypo3_report_reference (
    scope_key varchar(64) NOT NULL,
    page_uuid varchar(36) NOT NULL,
    language_id int unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (scope_key,page_uuid,language_id)
);

CREATE TABLE tx_typo3totypo3_capability (
    scope_key varchar(64) NOT NULL,
    configuration_hash varchar(64) NOT NULL DEFAULT '',
    status varchar(32) NOT NULL DEFAULT 'unknown',
    checked_at int unsigned NOT NULL DEFAULT 0,
    attempts int unsigned NOT NULL DEFAULT 0,
    next_check int unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (scope_key)
);

CREATE TABLE tx_typo3totypo3_observation (
    usage_scope varchar(64) NOT NULL,
    notification_scope varchar(64) NOT NULL,
    page_uuid varchar(36) NOT NULL,
    language_id int unsigned NOT NULL DEFAULT 0,
    checked_at int unsigned NOT NULL DEFAULT 0,
    state_hash varchar(64) NOT NULL DEFAULT '',
    priority smallint unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (usage_scope,notification_scope,page_uuid,language_id),
    KEY due_observation (notification_scope,checked_at),
    KEY changed_page (page_uuid)
);

CREATE TABLE tx_typo3totypo3_recheck (
    uid int unsigned NOT NULL,
    requested int unsigned NOT NULL DEFAULT 0,
    processed int unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (uid)
);

CREATE TABLE tx_typo3totypo3_usage_audit (
    event_key varchar(32) NOT NULL,
    scope_key varchar(64) NOT NULL,
    page_uuid varchar(36) NOT NULL,
    language_id int unsigned NOT NULL DEFAULT 0,
    actor_uid int unsigned NOT NULL DEFAULT 0,
    created_at int unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (event_key)
);

CREATE TABLE tx_typo3totypo3_delivery_history (
    event_key varchar(32) NOT NULL,
    scope_key varchar(64) NOT NULL,
    accepted_at int unsigned NOT NULL DEFAULT 0,
    item_count int unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (event_key),
    KEY history_scope (scope_key,accepted_at)
);

CREATE TABLE tx_typo3totypo3_change_hint (
    page_uid int unsigned NOT NULL,
    generation varchar(32) NOT NULL,
    child_cursor int unsigned NOT NULL DEFAULT 0,
    self_checked smallint unsigned NOT NULL DEFAULT 0,
    depth int unsigned NOT NULL DEFAULT 0,
    queued_at int unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (page_uid),
    KEY queued_hint (queued_at,page_uid)
);
