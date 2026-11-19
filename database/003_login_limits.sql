CREATE TABLE IF NOT EXISTS login_attempts (
 bucket CHAR(64) NOT NULL PRIMARY KEY,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 window_started DATETIME NOT NULL,
 INDEX(window_started)
) ENGINE=InnoDB;
