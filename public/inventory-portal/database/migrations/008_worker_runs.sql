CREATE TABLE IF NOT EXISTS inventory_kot_worker_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_count INT UNSIGNED NOT NULL DEFAULT 0,
    review_count INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY ix_inventory_worker_run_completed (completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
