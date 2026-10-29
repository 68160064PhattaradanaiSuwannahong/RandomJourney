-- =====================================================================
-- Random Journey - database schema
-- Target: MySQL 8.0 (Angsila)
-- All tables use the prefix rj_ so they never collide with other
-- projects (e.g. Resume_Website) living in the same database.
-- Re-runnable: drops and recreates every rj_ table (DEV ONLY - wipes data).
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP VIEW  IF EXISTS rj_v_country_risk;
DROP TABLE IF EXISTS rj_api_cache, rj_random_history, rj_favorites,
                     rj_article_places, rj_articles,
                     rj_review_reports, rj_review_helpful, rj_review_images, rj_reviews,
                     rj_login_attempts, rj_users,
                     rj_transport_routes, rj_visa_rules,
                     rj_place_safety, rj_risk_scores,
                     rj_place_tags, rj_tags, rj_places, rj_categories,
                     rj_cities, rj_countries;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Geography
-- ---------------------------------------------------------------------

-- Holds EVERY country (needed for user residence / nationality dropdowns).
-- Only rows with is_destination = 1 carry travel content.
CREATE TABLE rj_countries (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    iso2                CHAR(2)      NOT NULL UNIQUE,
    name_en             VARCHAR(100) NOT NULL,
    name_th             VARCHAR(100) NULL,
    currency_code       CHAR(3)      NULL,
    is_destination      TINYINT(1)   NOT NULL DEFAULT 0,

    -- destination content (NULL for non-destinations)
    capital             VARCHAR(100) NULL,
    languages           VARCHAR(150) NULL,
    timezone            VARCHAR(50)  NULL,
    plug_types          VARCHAR(30)  NULL,
    best_season         VARCHAR(150) NULL,
    summary             TEXT         NULL,
    history             TEXT         NULL,
    culture             TEXT         NULL,
    beliefs             TEXT         NULL,
    etiquette           TEXT         NULL,

    -- cost of living: 1 (very cheap) .. 5 (very expensive), prices in local currency
    cost_index          TINYINT      NULL,
    price_meal          DECIMAL(10,2) NULL,
    price_coffee        DECIMAL(10,2) NULL,
    price_hotel_night   DECIMAL(10,2) NULL,
    price_local_transport DECIMAL(10,2) NULL,

    -- emergency
    emergency_police    VARCHAR(20)  NULL,
    emergency_ambulance VARCHAR(20)  NULL,
    emergency_fire      VARCHAR(20)  NULL,
    tourist_police      VARCHAR(30)  NULL,

    -- official travel advisory
    advisory_level      TINYINT      NULL,      -- e.g. US State Dept level 1..4
    advisory_url        VARCHAR(500) NULL,

    hero_image_url      VARCHAR(500) NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT chk_country_cost     CHECK (cost_index IS NULL OR cost_index BETWEEN 1 AND 5),
    CONSTRAINT chk_country_advisory CHECK (advisory_level IS NULL OR advisory_level BETWEEN 1 AND 4),
    INDEX idx_country_destination (is_destination)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE rj_cities (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    country_id      INT UNSIGNED NOT NULL,
    name_en         VARCHAR(100) NOT NULL,
    name_th         VARCHAR(100) NULL,
    lat             DECIMAL(9,6) NOT NULL,
    lng             DECIMAL(9,6) NOT NULL,
    summary         TEXT         NULL,
    history         TEXT         NULL,
    environment     ENUM('urban','nature','mixed') NOT NULL DEFAULT 'mixed',
    cost_index      TINYINT      NULL,          -- NULL = inherit from country
    best_season     VARCHAR(150) NULL,
    hero_image_url  VARCHAR(500) NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_city_country FOREIGN KEY (country_id) REFERENCES rj_countries(id) ON DELETE CASCADE,
    CONSTRAINT chk_city_cost   CHECK (cost_index IS NULL OR cost_index BETWEEN 1 AND 5),
    UNIQUE KEY uq_city (country_id, name_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- Users (created before places because places.created_by references it)
-- ---------------------------------------------------------------------

CREATE TABLE rj_users (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username            VARCHAR(30)  NOT NULL UNIQUE,
    email               VARCHAR(190) NOT NULL UNIQUE,
    password_hash       VARCHAR(255) NOT NULL,          -- password_hash(..., PASSWORD_ARGON2ID)
    role                ENUM('user','admin') NOT NULL DEFAULT 'user',
    display_name        VARCHAR(60)  NULL,
    residence_country_id   INT UNSIGNED NULL,
    nationality_country_id INT UNSIGNED NULL,
    preferred_currency  CHAR(3)      NULL,
    avatar_url          VARCHAR(500) NULL,
    bio                 VARCHAR(500) NULL,
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at       DATETIME     NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_user_residence   FOREIGN KEY (residence_country_id)   REFERENCES rj_countries(id) ON DELETE SET NULL,
    CONSTRAINT fk_user_nationality FOREIGN KEY (nationality_country_id) REFERENCES rj_countries(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Brute-force protection: count recent failures per IP / username
CREATE TABLE rj_login_attempts (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address    VARCHAR(45)  NOT NULL,
    username      VARCHAR(190) NOT NULL,
    success       TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempt_ip   (ip_address, attempted_at),
    INDEX idx_attempt_user (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- Places
-- ---------------------------------------------------------------------

CREATE TABLE rj_categories (
    id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug     VARCHAR(30)  NOT NULL UNIQUE,
    name_en  VARCHAR(60)  NOT NULL,
    name_th  VARCHAR(60)  NOT NULL,
    icon     VARCHAR(30)  NULL,
    sort_order TINYINT    NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE rj_places (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    city_id         INT UNSIGNED NOT NULL,
    category_id     INT UNSIGNED NOT NULL,
    name_en         VARCHAR(150) NOT NULL,
    name_th         VARCHAR(150) NULL,
    summary         VARCHAR(300) NULL,
    description     TEXT         NULL,
    history         TEXT         NULL,
    culture_beliefs TEXT         NULL,
    how_to_get_there TEXT        NULL,
    address         VARCHAR(300) NULL,
    lat             DECIMAL(9,6) NOT NULL,
    lng             DECIMAL(9,6) NOT NULL,

    -- filters
    price_level     TINYINT      NOT NULL DEFAULT 2,     -- 0 free, 1 cheap .. 4 luxury
    price_min       DECIMAL(10,2) NULL,                  -- local currency
    price_max       DECIMAL(10,2) NULL,
    environment     ENUM('urban','nature','mixed') NOT NULL DEFAULT 'urban',
    terrain         ENUM('city','mountain','sea','island','river','forest','desert','other') NOT NULL DEFAULT 'city',
    is_historical   TINYINT(1)   NOT NULL DEFAULT 0,
    difficulty      TINYINT      NULL,                   -- trails only: 1 easy .. 5 extreme
    duration_hours  DECIMAL(4,1) NULL,                   -- suggested visit / hike time

    -- permits & practical info
    permit_required TINYINT(1)   NOT NULL DEFAULT 0,
    permit_info     TEXT         NULL,
    opening_hours   VARCHAR(200) NULL,
    dress_code      VARCHAR(200) NULL,
    website_url     VARCHAR(500) NULL,
    image_url       VARCHAR(500) NULL,

    -- rating cache (updated whenever a review changes)
    avg_rating      DECIMAL(3,2) NOT NULL DEFAULT 0,
    review_count    INT UNSIGNED NOT NULL DEFAULT 0,

    status          ENUM('published','draft','pending') NOT NULL DEFAULT 'published',
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_place_city     FOREIGN KEY (city_id)     REFERENCES rj_cities(id)     ON DELETE CASCADE,
    CONSTRAINT fk_place_category FOREIGN KEY (category_id) REFERENCES rj_categories(id),
    CONSTRAINT fk_place_creator  FOREIGN KEY (created_by)  REFERENCES rj_users(id)      ON DELETE SET NULL,
    CONSTRAINT chk_place_price      CHECK (price_level BETWEEN 0 AND 4),
    CONSTRAINT chk_place_difficulty CHECK (difficulty IS NULL OR difficulty BETWEEN 1 AND 5),

    INDEX idx_place_city      (city_id),
    INDEX idx_place_category  (category_id),
    INDEX idx_place_filters   (status, environment, terrain, price_level),
    FULLTEXT INDEX ft_place_search (name_en, name_th, summary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Thematic tags (history, ancient ruins, food, adventure...)
CREATE TABLE rj_tags (
    id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug     VARCHAR(30) NOT NULL UNIQUE,
    name_en  VARCHAR(60) NOT NULL,
    name_th  VARCHAR(60) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE rj_place_tags (
    place_id INT UNSIGNED NOT NULL,
    tag_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (place_id, tag_id),
    CONSTRAINT fk_pt_place FOREIGN KEY (place_id) REFERENCES rj_places(id) ON DELETE CASCADE,
    CONSTRAINT fk_pt_tag   FOREIGN KEY (tag_id)   REFERENCES rj_tags(id)   ON DELETE CASCADE,
    INDEX idx_pt_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- Risk & safety
-- ---------------------------------------------------------------------

-- One row per risk dimension for a country (city_id NULL) or a city override
CREATE TABLE rj_risk_scores (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    country_id    INT UNSIGNED NOT NULL,
    city_id       INT UNSIGNED NULL,
    risk_type     ENUM('crime_petty','crime_violent','natural','health','political','traffic') NOT NULL,
    score         TINYINT      NOT NULL,          -- 1 very low .. 5 very high
    description   TEXT         NULL,              -- what the risk is
    how_to_handle TEXT         NULL,              -- prevention / what to do
    source_url    VARCHAR(500) NULL,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_risk_country FOREIGN KEY (country_id) REFERENCES rj_countries(id) ON DELETE CASCADE,
    CONSTRAINT fk_risk_city    FOREIGN KEY (city_id)    REFERENCES rj_cities(id)    ON DELETE CASCADE,
    CONSTRAINT chk_risk_score  CHECK (score BETWEEN 1 AND 5),
    INDEX idx_risk_country (country_id, risk_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Place-specific warnings (scams at a temple, slippery trail, strong currents...)
CREATE TABLE rj_place_safety (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    place_id      INT UNSIGNED NOT NULL,
    risk_type     ENUM('crime_petty','crime_violent','natural','health','political','traffic','other') NOT NULL,
    title         VARCHAR(150) NOT NULL,
    tip           TEXT         NOT NULL,
    CONSTRAINT fk_ps_place FOREIGN KEY (place_id) REFERENCES rj_places(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Overall country risk = weighted average of country-level scores (1..5)
CREATE VIEW rj_v_country_risk AS
SELECT  c.id   AS country_id,
        c.name_en,
        ROUND(
            SUM(r.score * CASE r.risk_type
                    WHEN 'crime_violent' THEN 0.25
                    WHEN 'political'     THEN 0.20
                    WHEN 'crime_petty'   THEN 0.15
                    WHEN 'natural'       THEN 0.15
                    WHEN 'health'        THEN 0.15
                    WHEN 'traffic'       THEN 0.10
                END)
          / SUM(CASE r.risk_type
                    WHEN 'crime_violent' THEN 0.25
                    WHEN 'political'     THEN 0.20
                    WHEN 'crime_petty'   THEN 0.15
                    WHEN 'natural'       THEN 0.15
                    WHEN 'health'        THEN 0.15
                    WHEN 'traffic'       THEN 0.10
                END), 2) AS overall_risk,
        MAX(CASE WHEN r.risk_type = 'crime_petty'   THEN r.score END) AS crime_petty,
        MAX(CASE WHEN r.risk_type = 'crime_violent' THEN r.score END) AS crime_violent,
        MAX(CASE WHEN r.risk_type = 'natural'       THEN r.score END) AS natural_risk,
        MAX(CASE WHEN r.risk_type = 'health'        THEN r.score END) AS health_risk,
        MAX(CASE WHEN r.risk_type = 'political'     THEN r.score END) AS political_risk,
        MAX(CASE WHEN r.risk_type = 'traffic'       THEN r.score END) AS traffic_risk
FROM    rj_countries c
JOIN    rj_risk_scores r ON r.country_id = c.id AND r.city_id IS NULL
GROUP BY c.id, c.name_en;

-- ---------------------------------------------------------------------
-- Visa & transport
-- ---------------------------------------------------------------------

CREATE TABLE rj_visa_rules (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nationality_country_id INT UNSIGNED NOT NULL,
    destination_country_id INT UNSIGNED NOT NULL,
    visa_type      ENUM('visa_free','visa_on_arrival','e_visa','visa_required','not_allowed') NOT NULL,
    max_stay_days  SMALLINT     NULL,
    fee_text       VARCHAR(100) NULL,
    summary        TEXT         NULL,
    how_to_apply   TEXT         NULL,
    official_url   VARCHAR(500) NULL,
    verified_at    DATE         NULL,              -- when this info was last checked

    CONSTRAINT fk_visa_nat  FOREIGN KEY (nationality_country_id) REFERENCES rj_countries(id) ON DELETE CASCADE,
    CONSTRAINT fk_visa_dest FOREIGN KEY (destination_country_id) REFERENCES rj_countries(id) ON DELETE CASCADE,
    UNIQUE KEY uq_visa_pair (nationality_country_id, destination_country_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- City-to-city travel options (distance itself is computed with Haversine)
CREATE TABLE rj_transport_routes (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_city_id     INT UNSIGNED NOT NULL,
    to_city_id       INT UNSIGNED NOT NULL,
    mode             ENUM('flight','train','bus','car','boat','other') NOT NULL,
    duration_min     SMALLINT UNSIGNED NULL,
    price_min        DECIMAL(10,2) NULL,
    price_max        DECIMAL(10,2) NULL,
    price_currency   CHAR(3)      NULL,
    how_to_book      TEXT         NULL,
    booking_url      VARCHAR(500) NULL,

    CONSTRAINT fk_route_from FOREIGN KEY (from_city_id) REFERENCES rj_cities(id) ON DELETE CASCADE,
    CONSTRAINT fk_route_to   FOREIGN KEY (to_city_id)   REFERENCES rj_cities(id) ON DELETE CASCADE,
    INDEX idx_route_pair (from_city_id, to_city_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- Reviews & community
-- ---------------------------------------------------------------------

CREATE TABLE rj_reviews (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    place_id      INT UNSIGNED NOT NULL,
    rating        TINYINT      NOT NULL,
    title         VARCHAR(150) NULL,
    body          TEXT         NOT NULL,
    visited_at    DATE         NULL,
    helpful_count INT UNSIGNED NOT NULL DEFAULT 0,
    status        ENUM('visible','hidden') NOT NULL DEFAULT 'visible',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_review_user  FOREIGN KEY (user_id)  REFERENCES rj_users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_review_place FOREIGN KEY (place_id) REFERENCES rj_places(id) ON DELETE CASCADE,
    CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5),
    UNIQUE KEY uq_review_once (user_id, place_id),        -- one review per user per place
    INDEX idx_review_place (place_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE rj_review_images (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_id  INT UNSIGNED NOT NULL,
    file_path  VARCHAR(300) NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ri_review FOREIGN KEY (review_id) REFERENCES rj_reviews(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE rj_review_helpful (
    user_id   INT UNSIGNED NOT NULL,
    review_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, review_id),
    CONSTRAINT fk_rh_user   FOREIGN KEY (user_id)   REFERENCES rj_users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_rh_review FOREIGN KEY (review_id) REFERENCES rj_reviews(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE rj_review_reports (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_id    INT UNSIGNED NOT NULL,
    reporter_id  INT UNSIGNED NOT NULL,
    reason       ENUM('spam','offensive','false_info','off_topic','other') NOT NULL,
    details      VARCHAR(500) NULL,
    status       ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
    resolved_by  INT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at  DATETIME     NULL,

    CONSTRAINT fk_rr_review   FOREIGN KEY (review_id)   REFERENCES rj_reviews(id) ON DELETE CASCADE,
    CONSTRAINT fk_rr_reporter FOREIGN KEY (reporter_id) REFERENCES rj_users(id)   ON DELETE CASCADE,
    CONSTRAINT fk_rr_resolver FOREIGN KEY (resolved_by) REFERENCES rj_users(id)   ON DELETE SET NULL,
    UNIQUE KEY uq_report_once (review_id, reporter_id),
    INDEX idx_report_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- User-written recommendation articles (feature 8, "write a recommendation")
CREATE TABLE rj_articles (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    country_id  INT UNSIGNED NULL,
    city_id     INT UNSIGNED NULL,
    title       VARCHAR(200) NOT NULL,
    body        MEDIUMTEXT   NOT NULL,
    cover_url   VARCHAR(500) NULL,
    status      ENUM('published','hidden','draft') NOT NULL DEFAULT 'published',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_article_user    FOREIGN KEY (user_id)    REFERENCES rj_users(id)     ON DELETE CASCADE,
    CONSTRAINT fk_article_country FOREIGN KEY (country_id) REFERENCES rj_countries(id) ON DELETE SET NULL,
    CONSTRAINT fk_article_city    FOREIGN KEY (city_id)    REFERENCES rj_cities(id)    ON DELETE SET NULL,
    INDEX idx_article_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE rj_article_places (
    article_id INT UNSIGNED NOT NULL,
    place_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (article_id, place_id),
    CONSTRAINT fk_ap_article FOREIGN KEY (article_id) REFERENCES rj_articles(id) ON DELETE CASCADE,
    CONSTRAINT fk_ap_place   FOREIGN KEY (place_id)   REFERENCES rj_places(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- Personal features
-- ---------------------------------------------------------------------

CREATE TABLE rj_favorites (
    user_id    INT UNSIGNED NOT NULL,
    place_id   INT UNSIGNED NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, place_id),
    CONSTRAINT fk_fav_user  FOREIGN KEY (user_id)  REFERENCES rj_users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_fav_place FOREIGN KEY (place_id) REFERENCES rj_places(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Remembers past random results (avoid repeats, show "your spins")
CREATE TABLE rj_random_history (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,                 -- NULL = guest
    session_id  VARCHAR(64)  NULL,
    place_id    INT UNSIGNED NOT NULL,
    filters     JSON         NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rh2_user  FOREIGN KEY (user_id)  REFERENCES rj_users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_rh2_place FOREIGN KEY (place_id) REFERENCES rj_places(id) ON DELETE CASCADE,
    INDEX idx_rh_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- External API cache (exchange rates, weather)
-- ---------------------------------------------------------------------

CREATE TABLE rj_api_cache (
    cache_key   VARCHAR(191) NOT NULL PRIMARY KEY,   -- e.g. 'fx:USD', 'wx:13.75,100.50'
    payload     JSON         NOT NULL,
    fetched_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME     NOT NULL,
    INDEX idx_cache_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- Seed: categories & tags
-- ---------------------------------------------------------------------

INSERT INTO rj_categories (slug, name_en, name_th, icon, sort_order) VALUES
('attraction', 'Attraction',          'สถานที่ท่องเที่ยว',       'landmark',  1),
('trail',      'Hiking Trail',        'เส้นทางเดินป่า/ขึ้นเขา',  'mountain',  2),
('restaurant', 'Restaurant',          'ร้านอาหาร',              'utensils',  3),
('cafe',       'Cafe',                'คาเฟ่',                  'coffee',    4),
('beverage',   'Drink Shop',          'ร้านเครื่องดื่ม',          'cup-soda',  5),
('bar',        'Bar (sit-down)',      'ร้านนั่งดื่ม',             'wine',      6),
('market',     'Market',              'ตลาด',                   'store',     7),
('mall',       'Shopping Mall',       'ห้างสรรพสินค้า',          'shopping-bag', 8),
('souvenir',   'Souvenir Shop',       'ร้านของฝาก',             'gift',      9),
('hotel',      'Hotel / Stay',        'โรงแรม/ที่พัก',           'bed',      10);

INSERT INTO rj_tags (slug, name_en, name_th) VALUES
('history',      'History',            'ประวัติศาสตร์'),
('ancient',      'Ancient Site',       'โบราณสถาน'),
('unesco',       'UNESCO Heritage',    'มรดกโลก'),
('religious',    'Religious Site',     'ศาสนสถาน'),
('museum',       'Museum',             'พิพิธภัณฑ์'),
('art',          'Art',                'ศิลปะ'),
('culture',      'Local Culture',      'วัฒนธรรมท้องถิ่น'),
('scenic',       'Scenic Nature',      'ธรรมชาติสวยงาม'),
('viewpoint',    'Viewpoint',          'จุดชมวิว'),
('adventure',    'Adventure',          'ผจญภัย'),
('relax',        'Relaxing',           'พักผ่อน'),
('family',       'Family Friendly',    'เหมาะกับครอบครัว'),
('local_food',   'Local Food',         'อาหารท้องถิ่น'),
('shopping',     'Shopping',           'ช้อปปิ้ง'),
('photo_spot',   'Photo Spot',         'จุดถ่ายรูป'),
('night_view',   'Night View',         'วิวกลางคืน');

-- After registering your own account through the website, promote it:
-- UPDATE rj_users SET role = 'admin' WHERE username = 'your_username';
