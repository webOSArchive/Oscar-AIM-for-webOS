-- webOS Chat: each App Museum account's chat key (the AIM password), stored as the hex MD5 of
-- the key, which is all the BUCP login needs. Separate from the account password, which stock
-- devices would otherwise send over plain TCP. Apply to the Museum's database
-- (webos-catalog-service), as a migration beside its own in sql/migrations.
ALTER TABLE accounts
    ADD COLUMN chat_key_md5 CHAR(32) NULL DEFAULT NULL AFTER username;

-- The server's read-only account (config.ini: db_user / db_pass).
-- CREATE USER 'oscar'@'localhost' IDENTIFIED BY '...';
-- GRANT SELECT (username, chat_key_md5, status) ON museum.accounts TO 'oscar'@'localhost';
