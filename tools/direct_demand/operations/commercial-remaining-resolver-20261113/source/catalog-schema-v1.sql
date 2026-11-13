-- AnyTour catalog / price / SEO data foundation v1
-- Target: MySQL 8+ / MariaDB 10.5+ compatible subset
-- Apply only after creating a dedicated database and backup policy.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS catalog_departures (
    id INT UNSIGNED NOT NULL,
    name VARCHAR(180) NOT NULL,
    name_genitive VARCHAR(180) DEFAULT NULL,
    slug VARCHAR(200) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    synced_at DATETIME NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_catalog_departures_slug (slug),
    KEY idx_catalog_departures_active_name (is_active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalog_countries (
    id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    slug VARCHAR(180) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    synced_at DATETIME NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_catalog_countries_slug (slug),
    KEY idx_catalog_countries_active_name (is_active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalog_regions (
    id INT UNSIGNED NOT NULL,
    country_id INT UNSIGNED NOT NULL,
    name VARCHAR(180) NOT NULL,
    slug VARCHAR(200) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    synced_at DATETIME NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_catalog_regions_country (country_id, is_active, name),
    UNIQUE KEY uq_catalog_regions_country_slug (country_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalog_subregions (
    id INT UNSIGNED NOT NULL,
    region_id INT UNSIGNED NOT NULL,
    name VARCHAR(180) NOT NULL,
    slug VARCHAR(200) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    synced_at DATETIME NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_catalog_subregions_region (region_id, is_active, name),
    UNIQUE KEY uq_catalog_subregions_region_slug (region_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalog_hotels (
    id INT UNSIGNED NOT NULL,
    country_id INT UNSIGNED NOT NULL,
    country_name VARCHAR(160) NOT NULL,
    region_id INT UNSIGNED DEFAULT NULL,
    region_name VARCHAR(180) DEFAULT NULL,
    subregion_id INT UNSIGNED DEFAULT NULL,
    subregion_name VARCHAR(180) DEFAULT NULL,
    name VARCHAR(255) NOT NULL,
    normalized_name VARCHAR(255) NOT NULL,
    search_key VARCHAR(512) NOT NULL,
    slug VARCHAR(280) DEFAULT NULL,
    category TINYINT UNSIGNED DEFAULT NULL,
    rating DECIMAL(3,2) DEFAULT NULL,
    hotel_type INT UNSIGNED DEFAULT NULL,
    latitude DECIMAL(10,7) DEFAULT NULL,
    longitude DECIMAL(10,7) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    synced_at DATETIME NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_catalog_hotels_slug (slug),
    KEY idx_catalog_hotels_country_region (country_id, region_id, is_active),
    KEY idx_catalog_hotels_country_name (country_id, normalized_name),
    KEY idx_catalog_hotels_region_name (region_id, normalized_name),
    KEY idx_catalog_hotels_active_seen (is_active, last_seen_at),
    FULLTEXT KEY ft_catalog_hotels_search (name, normalized_name, search_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hotel_aliases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    hotel_id INT UNSIGNED NOT NULL,
    alias VARCHAR(255) NOT NULL,
    normalized_alias VARCHAR(255) NOT NULL,
    source ENUM('manual','generated','imported') NOT NULL DEFAULT 'manual',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hotel_alias (hotel_id, normalized_alias),
    KEY idx_hotel_alias_lookup (normalized_alias, hotel_id),
    FULLTEXT KEY ft_hotel_aliases_search (alias, normalized_alias)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalog_sync_state (
    sync_key VARCHAR(120) NOT NULL,
    status ENUM('idle','running','success','failure') NOT NULL DEFAULT 'idle',
    started_at DATETIME DEFAULT NULL,
    finished_at DATETIME DEFAULT NULL,
    rows_seen INT UNSIGNED NOT NULL DEFAULT 0,
    rows_changed INT UNSIGNED NOT NULL DEFAULT 0,
    cursor_value VARCHAR(255) DEFAULT NULL,
    last_error VARCHAR(1000) DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (sync_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tour_price_observations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fingerprint CHAR(64) NOT NULL,
    observed_at DATETIME NOT NULL,
    source ENUM('user_search','scheduled_monitor','hot_tours') NOT NULL,
    search_id BIGINT UNSIGNED DEFAULT NULL,
    departure_id INT UNSIGNED NOT NULL,
    country_id INT UNSIGNED NOT NULL,
    region_id INT UNSIGNED DEFAULT NULL,
    subregion_id INT UNSIGNED DEFAULT NULL,
    hotel_id INT UNSIGNED NOT NULL,
    tour_id VARCHAR(220) DEFAULT NULL,
    departure_date DATE NOT NULL,
    departure_year SMALLINT UNSIGNED NOT NULL,
    departure_month TINYINT UNSIGNED NOT NULL,
    nights TINYINT UNSIGNED NOT NULL,
    adults TINYINT UNSIGNED NOT NULL,
    children_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    child_ages_signature VARCHAR(40) NOT NULL DEFAULT '',
    meal_id INT UNSIGNED DEFAULT NULL,
    room_id INT UNSIGNED DEFAULT NULL,
    room_type VARCHAR(255) DEFAULT NULL,
    operator_id INT UNSIGNED DEFAULT NULL,
    price DECIMAL(12,2) NOT NULL,
    fuel_charge DECIMAL(12,2) DEFAULT NULL,
    currency CHAR(8) NOT NULL DEFAULT 'RUB',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_price_observation_fingerprint (fingerprint),
    KEY idx_price_hotel_date (hotel_id, departure_date, nights, observed_at),
    KEY idx_price_destination_month (departure_id, country_id, departure_year, departure_month, observed_at),
    KEY idx_price_segment (departure_id, hotel_id, nights, adults, children_count, meal_id, observed_at),
    KEY idx_price_observed_at (observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tour_price_daily (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    price_date DATE NOT NULL,
    departure_id INT UNSIGNED NOT NULL,
    country_id INT UNSIGNED NOT NULL,
    region_id INT UNSIGNED DEFAULT NULL,
    hotel_id INT UNSIGNED NOT NULL,
    departure_year SMALLINT UNSIGNED NOT NULL,
    departure_month TINYINT UNSIGNED NOT NULL,
    nights TINYINT UNSIGNED NOT NULL,
    adults TINYINT UNSIGNED NOT NULL,
    children_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    child_ages_signature VARCHAR(40) NOT NULL DEFAULT '',
    meal_id INT UNSIGNED DEFAULT NULL,
    currency CHAR(8) NOT NULL DEFAULT 'RUB',
    min_price DECIMAL(12,2) NOT NULL,
    median_price DECIMAL(12,2) NOT NULL,
    max_price DECIMAL(12,2) NOT NULL,
    observation_count INT UNSIGNED NOT NULL,
    calculated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_price_daily_segment (
        price_date, departure_id, hotel_id, departure_year, departure_month,
        nights, adults, children_count, child_ages_signature, meal_id, currency
    ),
    KEY idx_price_daily_hotel (hotel_id, price_date),
    KEY idx_price_daily_destination_month (country_id, region_id, departure_year, departure_month, price_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tour_price_daily_exact (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    segment_fingerprint CHAR(64) NOT NULL,
    price_date DATE NOT NULL,
    departure_id INT UNSIGNED NOT NULL,
    country_id INT UNSIGNED NOT NULL,
    region_id INT UNSIGNED DEFAULT NULL,
    subregion_id INT UNSIGNED DEFAULT NULL,
    hotel_id INT UNSIGNED NOT NULL,
    departure_date DATE NOT NULL,
    nights TINYINT UNSIGNED NOT NULL,
    adults TINYINT UNSIGNED NOT NULL,
    children_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    child_ages_signature VARCHAR(40) NOT NULL DEFAULT '',
    meal_id INT UNSIGNED NOT NULL DEFAULT 0,
    room_id INT UNSIGNED NOT NULL DEFAULT 0,
    room_type VARCHAR(255) NOT NULL DEFAULT '',
    operator_id INT UNSIGNED NOT NULL DEFAULT 0,
    currency CHAR(8) NOT NULL DEFAULT 'RUB',
    min_price DECIMAL(12,2) NOT NULL,
    median_price DECIMAL(12,2) NOT NULL,
    max_price DECIMAL(12,2) NOT NULL,
    observation_count INT UNSIGNED NOT NULL,
    independent_search_count INT UNSIGNED NOT NULL DEFAULT 0,
    calculated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_price_daily_exact_segment (price_date, segment_fingerprint),
    KEY idx_price_daily_exact_segment_time (segment_fingerprint, price_date),
    KEY idx_price_daily_exact_hotel_departure (hotel_id, departure_date, price_date),
    KEY idx_price_daily_exact_destination (country_id, region_id, departure_date, price_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hot_tours_current (
    snapshot_key CHAR(64) NOT NULL,
    tour_id VARCHAR(220) NOT NULL,
    departure_id INT UNSIGNED NOT NULL,
    departure_name VARCHAR(180) NOT NULL,
    country_id INT UNSIGNED NOT NULL,
    country_name VARCHAR(160) NOT NULL,
    region_id INT UNSIGNED DEFAULT NULL,
    region_name VARCHAR(180) DEFAULT NULL,
    subregion_id INT UNSIGNED DEFAULT NULL,
    subregion_name VARCHAR(180) DEFAULT NULL,
    hotel_id INT UNSIGNED NOT NULL,
    hotel_name VARCHAR(255) NOT NULL,
    hotel_category TINYINT UNSIGNED DEFAULT NULL,
    hotel_rating DECIMAL(3,2) DEFAULT NULL,
    picture_url VARCHAR(1000) DEFAULT NULL,
    departure_date DATE NOT NULL,
    nights TINYINT UNSIGNED NOT NULL,
    meal_id INT UNSIGNED DEFAULT NULL,
    meal_name VARCHAR(180) DEFAULT NULL,
    operator_id INT UNSIGNED DEFAULT NULL,
    operator_name VARCHAR(180) DEFAULT NULL,
    price DECIMAL(12,2) NOT NULL,
    old_price DECIMAL(12,2) DEFAULT NULL,
    currency CHAR(8) NOT NULL DEFAULT 'RUB',
    fetched_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (snapshot_key),
    KEY idx_hot_departure_country_price (departure_id, country_id, price),
    KEY idx_hot_country_date_price (country_id, departure_date, price),
    KEY idx_hot_hotel (hotel_id, fetched_at),
    KEY idx_hot_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seo_offer_snapshots (
    page_key VARCHAR(255) NOT NULL,
    page_type ENUM('country','resort','month','resort_month','intent','hotel') NOT NULL,
    country_id INT UNSIGNED DEFAULT NULL,
    region_id INT UNSIGNED DEFAULT NULL,
    hotel_id INT UNSIGNED DEFAULT NULL,
    departure_id INT UNSIGNED DEFAULT NULL,
    departure_year SMALLINT UNSIGNED DEFAULT NULL,
    departure_month TINYINT UNSIGNED DEFAULT NULL,
    month_start DATE DEFAULT NULL,
    dimensions_json JSON DEFAULT NULL,
    offers_json JSON NOT NULL,
    min_price DECIMAL(12,2) DEFAULT NULL,
    currency CHAR(8) DEFAULT 'RUB',
    offer_count INT UNSIGNED NOT NULL DEFAULT 0,
    observed_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (page_key),
    KEY idx_seo_snapshot_country_month (country_id, region_id, departure_year, departure_month),
    KEY idx_seo_snapshot_type_freshness (page_type, expires_at),
    KEY idx_seo_snapshot_hotel (hotel_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
