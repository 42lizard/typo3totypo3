CREATE TABLE tx_typo3totypo3_identity (
    page_uid int unsigned NOT NULL,
    uuid varchar(36) NOT NULL,
    PRIMARY KEY (page_uid),
    UNIQUE KEY uuid (uuid)
);
