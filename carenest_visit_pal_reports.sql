-- Optional manual migration (the app also auto-creates this table on first use).
CREATE TABLE IF NOT EXISTS visit_pal_reports (
    report_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visit_ID INT UNSIGNED NOT NULL,
    pal_ID INT UNSIGNED NOT NULL,
    phase ENUM('During','After') NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_visit_pal (visit_ID, pal_ID),
    KEY idx_visit (visit_ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
