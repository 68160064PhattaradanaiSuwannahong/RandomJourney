-- =====================================================================
-- Random Journey: migration for an existing database (November 2026)
-- Adds photos, the trip planner and extra place details.
-- Safe to run more than once. Nothing is deleted.
-- Import in phpMyAdmin, then import seed_destinations.sql and seed_photos.sql.
-- =====================================================================
SET NAMES utf8mb4;

-- Extra place details (added only if they're missing)
SET @need := (SELECT COUNT(*) = 0 FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'rj_places' AND column_name = 'best_time');
SET @sql := IF(@need,
    'ALTER TABLE rj_places ADD COLUMN best_time VARCHAR(300) NULL AFTER dress_code, ADD COLUMN tips TEXT NULL AFTER best_time, ADD COLUMN wiki_url VARCHAR(500) NULL AFTER website_url',
    'SELECT ''rj_places already has the new columns''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Photos of places (from Wikimedia Commons, with credits). City and country covers use their places' photos.
CREATE TABLE IF NOT EXISTS rj_photos (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    place_id    INT UNSIGNED NOT NULL,
    url         VARCHAR(1000) NOT NULL,            -- large image (about 1280 px wide)
    thumb_url   VARCHAR(1000) NOT NULL,            -- small image (about 500 px wide)
    width       SMALLINT UNSIGNED NULL,
    height      SMALLINT UNSIGNED NULL,
    caption     VARCHAR(300) NULL,
    author      VARCHAR(300) NULL,
    license     VARCHAR(100) NULL,
    license_url VARCHAR(300) NULL,
    source_url  VARCHAR(1000) NULL,                -- file page on Wikimedia Commons
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_photo_place FOREIGN KEY (place_id) REFERENCES rj_places(id) ON DELETE CASCADE,
    UNIQUE KEY uq_photo (place_id, url(255)),
    INDEX idx_photo_place (place_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Trip planner: a user's trips, each a list of saved places arranged by day
CREATE TABLE IF NOT EXISTS rj_trips (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    title       VARCHAR(120) NOT NULL,
    start_date  DATE         NULL,
    days        TINYINT UNSIGNED NOT NULL DEFAULT 3,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_trip_user FOREIGN KEY (user_id) REFERENCES rj_users(id) ON DELETE CASCADE,
    CONSTRAINT chk_trip_days CHECK (days BETWEEN 1 AND 30),
    INDEX idx_trip_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS rj_trip_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trip_id     INT UNSIGNED NOT NULL,
    place_id    INT UNSIGNED NOT NULL,
    day_no      TINYINT UNSIGNED NOT NULL DEFAULT 1,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    note        VARCHAR(300) NULL,
    CONSTRAINT fk_ti_trip  FOREIGN KEY (trip_id)  REFERENCES rj_trips(id)  ON DELETE CASCADE,
    CONSTRAINT fk_ti_place FOREIGN KEY (place_id) REFERENCES rj_places(id) ON DELETE CASCADE,
    UNIQUE KEY uq_trip_place (trip_id, place_id),
    INDEX idx_ti_trip (trip_id, day_no, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
