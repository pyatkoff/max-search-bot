-- Derived modeled-value audit only. Original forecasts/conversations and provider receipts stay immutable.
CREATE TABLE IF NOT EXISTS modeled_conversion_price_audit (
 outbox_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 action VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 original_value_rub INT UNSIGNED NOT NULL,
 applied_value_rub INT UNSIGNED NOT NULL,
 prior_reserved_rub INT UNSIGNED NOT NULL,
 assessed_at_unix BIGINT UNSIGNED NOT NULL,
 basis_json TEXT NOT NULL,
 basis_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
