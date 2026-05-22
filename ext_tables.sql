CREATE TABLE tx_maitranslate_log (
    record_table varchar(255) DEFAULT '' NOT NULL,
    record_uid int(11) unsigned DEFAULT '0' NOT NULL,
    field varchar(255) DEFAULT '' NOT NULL,
    source_language varchar(10) DEFAULT '' NOT NULL,
    target_language varchar(10) DEFAULT '' NOT NULL,
    provider varchar(20) DEFAULT '' NOT NULL,
    status varchar(20) DEFAULT 'success' NOT NULL
);
