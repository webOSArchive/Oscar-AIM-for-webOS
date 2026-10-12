-- webOS Chat on the App Museum's accounts. The column itself is webos-catalog-service's
-- migration sql/migrations/0012_chat_key.sql: `accounts.chat_key`, each account's webOS Chat
-- pass phrase (three short words), stored as is. AIM's sign-in only needs MD5(phrase), so a
-- hash would protect nothing, and the Museum shows the phrase again on My Account.
--
-- After applying that migration, the server's read-only database user (config.ini: db_user,
-- db_pass), allowed only what a sign-in reads. Use a real password and the database's name:

-- CREATE USER 'oscar'@'localhost' IDENTIFIED BY '...';
-- GRANT SELECT (username, chat_key, status) ON museum.accounts TO 'oscar'@'localhost';
