<div align="center">

# 🧭 Random Journey

### Travel Destination Recommender & Random Trip Generator

[![Live Demo](https://img.shields.io/badge/🌐_Live_Demo-Angsila_Server-63b3ed?style=for-the-badge)](https://angsila.informatics.buu.ac.th/~68160064/RandomJourney/)
[![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Leaflet](https://img.shields.io/badge/Leaflet-1.9.4-199900?style=for-the-badge&logo=leaflet&logoColor=white)](https://leafletjs.com/)
[![No Framework](https://img.shields.io/badge/Framework-None_(Vanilla_PHP)-555555?style=for-the-badge)](#-design-decisions)

> *Tell it your budget, how much risk you can take and whether you want mountains, sea or old cities —*
> *get ranked recommendations with reasons, or roll a twenty-sided die and draw your destination's tarot card.*

| 🌍 Destinations | 🏙️ Cities | 📍 Places | 📷 Photos | 🗄️ Tables | 🧪 Pages crawled |
|:---:|:---:|:---:|:---:|:---:|:---:|
| **20** | 26 | **287** | **1,213** | 24 + 1 view | **900**, 0 errors |

</div>

---

## 📋 Table of Contents

- [✨ Overview](#-overview)
- [🚀 Live Demo](#-live-demo)
- [🧩 Features](#-features)
- [🎯 Recommend vs Random](#-recommend-vs-random)
- [🗄️ Database](#️-database)
- [🔐 Security](#-security)
- [🌐 External Data](#-external-data)
- [📁 Project Structure](#-project-structure)
- [⚙️ How to Run](#️-how-to-run)
- [🛠️ Tech Stack](#️-tech-stack)
- [🧠 Design Decisions](#-design-decisions)
- [🔮 Future Work](#-future-work)
- [👥 Team](#-team)
- [📖 Credits & Data Sources](#-credits--data-sources)

---

## ✨ Overview

**Random Journey** is a travel website built as coursework at **Burapha University**, running on the faculty's **Angsila** server with its MySQL database. It covers twenty destinations — one for each face of the d20 — chosen to spread across every filter: popular, historic and natural, cheap and expensive, low and higher risk, visa-free and visa-required for Thai passports:

| Region | Countries (cities) |
|---|---|
| Southeast Asia | 🇹🇭 Thailand (Bangkok, Chiang Mai, Krabi) · 🇻🇳 Vietnam (Hanoi) · 🇰🇭 Cambodia (Siem Reap) · 🇸🇬 Singapore · 🇮🇩 Indonesia (Bali) |
| East & South Asia | 🇯🇵 Japan (Tokyo, Kyoto) · 🇰🇷 South Korea (Seoul) · 🇨🇳 China (Beijing) · 🇮🇳 India (Agra) |
| Europe | 🇮🇹 Italy (Rome, Florence) · 🇫🇷 France (Paris) · 🇬🇧 United Kingdom (London) · 🇬🇷 Greece (Athens) · 🇨🇭 Switzerland (Interlaken) · 🇮🇸 Iceland (Reykjavik) · 🇹🇷 Türkiye (Istanbul) |
| Africa & Americas & Oceania | 🇪🇬 Egypt (Cairo, Luxor) · 🇵🇪 Peru (Cusco, Lima) · 🇺🇸 United States (New York) · 🇦🇺 Australia (Sydney) |

### What makes this project stand out

- 🎯 **Two discovery modes with a real difference** — *Recommend* ranks places by a weighted match score and explains every result; *Random* treats filters as hard limits and adds chance, locks and a whole-day trip.
- 🎲 **A real 3D d20** — each face is a destination's ISO code; the dice (three.js, self-hosted) lands on the server's pick and a tarot card turns over with the country's numeral, outline, flag and own-language name. *Roll with advantage* throws two dice and lets you keep one card.
- 🃏 **A deck to collect** — every country you draw turns its card face up in your deck; country pages show the card, face down until you've drawn it. Any card can be saved as a PNG.
- 📷 **1,213 photos** from Wikimedia Commons, up to five per place, each credited to its photographer with its licence.
- 🗓️ **Trip planner** — arrange saved places by day, see them on a map joined in visiting order, and get an estimated budget in your own currency.
- 🌙 **Night mode, page turns and sound** — a dark "candles out" theme, page-turn transitions and optional dice and card sounds.
- 📍 **Near me, one tap** — share your location from the header pin, pick a radius (10 km – 5,000 km) and see distances on every result. It's rounded to ~1 km, sent only by POST and never saved.
- 🗺️ **Pick several countries and cities** — or tick *Leave out the country I live in* (from your profile, your location, or a dropdown for guests).
- 🛂 **Personalised by passport** — the visa panel, the "easy visa" filter and price conversion all use the nationality and currency in the user's profile.
- 🛡️ **Risk split into 6 dimensions** — petty crime, violent crime, natural hazards, health, unrest and road safety, each with *how to handle it*, plus place-specific safety tips.
- 🌦️ **Live data that degrades gracefully** — weather and exchange rates are cached in MySQL; if an API is down, pages fall back to stale data and never slow down.
- 🧰 **One admin engine for every content type** — cities, places, risks, visas and tips are each described once and get list, search, filter, create, edit and delete for free.
- 🔐 **Security by default** — Argon2id passwords, prepared statements everywhere, CSRF on every form, login rate limiting and a strict Content Security Policy.

---

## 🚀 Live Demo

### 👇 Try it now

**https://angsila.informatics.buu.ac.th/~68160064/RandomJourney/**

> ⚠️ **Note:** Angsila is a university server. If the link doesn't load from outside campus, connect through the BUU network or VPN.

| Page | What to try |
|---|---|
| **Destinations** | Open a country to see visa rules, the 6-part risk meter, cost of living and emergency numbers |
| **Recommend** | Pick *History* + *Ancient Site*, set risk to *Low*, mark Themes as *Must have* |
| **Spin** | Choose *A whole day*, roll the dice, then press *Keep this city, roll again*. Try *Roll with advantage* |
| **Deck** | See which of the 20 cards you've collected; open a country to see its card |
| **Trips** | Save a few places, create a trip, add them to days and watch the budget |
| **Near me** | Turn on the 📍 pin in the header, choose *Within 1,000 km* and press *Show recommendations* |
| **A place page** | Map, distance from the city centre, nearby places, weather, reviews, ♡ Save |

---

## 🧩 Features

| # | Requirement | How it's implemented |
|:-:|---|---|
| 1 | Login with hashed passwords | `password_hash()` with **Argon2id**, automatic rehash, lockout after 5 failed logins |
| 2 | Admin panel | Dashboard with content-gap checks and API status · CRUD for destinations, cities, places, safety tips, risks, visas · user roles & suspension · review moderation |
| 3 | User profile with country & nationality | Residence, nationality and display currency drive visa info and price conversion |
| 4 | Database | 24 tables + 1 view on MySQL 8.0, foreign keys, CHECK constraints, cached ratings |
| 5 | Travel information | Places in 10 categories (attractions, trails, restaurants, cafés, sit-down bars, markets, malls, souvenirs, hotels) with history, culture & beliefs, prices, opening hours, dress code, permits, map, distance, weather, exchange rates, cost of living, risks & safety tips, visa rules |
| 6 | Recommendation system | Weighted score from 7 criteria with user-chosen importance, plus a list of reasons per result |
| 6–7 | Shared filters | Several countries and cities at once, *leave out my home country*, distance from the user's location with a chosen radius |
| 7 | Random system | Hard filters, single place or whole-day trip, 3D d20 + tarot card reveal, roll with advantage, lock country/city, avoids recent repeats, spin history |
| 8 | User reviews | 1–5 stars, edit/delete own review, *Helpful* votes, reports, admin hide/restore |
| 9 | Extras | Photo galleries, collectible deck, trip planner with budget, night mode, ♡ saved places, weather-based packing tips, emergency numbers, "which filter is blocking you" hint |

---

## 🎯 Recommend vs Random

|  | 🎯 **Recommend** | 🎰 **Random** |
|---|---|---|
| Goal | Find the **best fit** | Find something **unexpected** or break a tie |
| Filters | Set a value **and** how much it matters | Every filter is a **hard limit** |
| Output | Ranked list with **% match** and reasons | One place, or a full day: *See · Eat · Shop · Sleep* |
| Extras | Map of the top 10, distance from you | d20 roll and tarot card, lock country/city, no recent repeats |

### Match score

Each criterion $i$ the user sets gets an importance $w_i \in \{0, 1, 2, 3\}$ (*Doesn't matter*, *Nice to have*, *Important*, *Must have*) and a match $m_i \in [0, 1]$:

$$
\text{score} = 100 \times \frac{\sum_i w_i \, m_i}{\sum_i w_i} \;+\; \underbrace{2.5\,(\bar{r} - 3)}_{\text{rating nudge, }\pm 5}
$$

| Criterion | Match $m_i$ |
|---|---|
| Budget | 1 if within the price level, −0.35 per level over |
| Safety | 1 if the country's overall risk ≤ limit, −0.4 per point over |
| Cost of living | 1 if at or below the chosen level, −0.35 per level over |
| Themes | share of chosen tags the place has |
| Landscape | 1 on match, 0.7 for sea ↔ island |
| City or nature | 1 on match, 0.6 for *mixed* places |
| Easy visa | 1 if home country, visa-free, on arrival or e-Visa |

*Must have* criteria are applied as filters before scoring. The overall country risk is a weighted average computed by the view `rj_v_country_risk` (violent crime 25%, unrest 20%, petty crime / natural / health 15% each, traffic 10%).

---

## 🗄️ Database

All tables use the `rj_` prefix so the project can share a database with other coursework.

```mermaid
erDiagram
    rj_countries ||--o{ rj_cities : has
    rj_cities ||--o{ rj_places : has
    rj_categories ||--o{ rj_places : groups
    rj_places ||--o{ rj_place_tags : tagged
    rj_tags ||--o{ rj_place_tags : tags
    rj_countries ||--o{ rj_risk_scores : rated
    rj_places ||--o{ rj_place_safety : warns
    rj_countries ||--o{ rj_visa_rules : "passport / destination"
    rj_users ||--o{ rj_reviews : writes
    rj_places ||--o{ rj_reviews : receives
    rj_reviews ||--o{ rj_review_helpful : voted
    rj_reviews ||--o{ rj_review_reports : reported
    rj_users ||--o{ rj_favorites : saves
    rj_places ||--o{ rj_favorites : saved
    rj_users ||--o{ rj_random_history : spins
    rj_countries ||--o{ rj_users : "lives in / nationality"
```

| Group | Tables |
|---|---|
| Geography | `rj_countries` (all 249 countries, 20 flagged as destinations), `rj_cities` |
| Places | `rj_places`, `rj_categories`, `rj_tags`, `rj_place_tags`, `rj_photos` |
| Safety & entry | `rj_risk_scores`, `rj_place_safety`, `rj_visa_rules`, view `rj_v_country_risk` |
| Users | `rj_users`, `rj_login_attempts`, `rj_trips`, `rj_trip_items` |
| Community | `rj_reviews`, `rj_review_helpful`, `rj_review_reports`, `rj_favorites`, `rj_articles`, `rj_article_places` |
| System | `rj_random_history`, `rj_api_cache`, `rj_transport_routes` |

---

## 🔐 Security

| Threat | Protection |
|---|---|
| Stolen password database | Argon2id hashes, rehashed automatically when parameters change |
| Brute-force login | 5 failures per username or 20 per IP in 15 minutes → temporary lock |
| Username enumeration | Same error message for every failure, and a dummy hash check so timing is equal |
| SQL injection | PDO prepared statements with emulation off; table/column names only come from code |
| XSS | Every output passes through `e()`; strict Content-Security-Policy with no inline scripts |
| CSRF | Per-session token on every POST form, rotated after login; logout is POST-only |
| Session hijacking | HttpOnly + SameSite cookie scoped to the app path, ID regenerated on login, 2 h idle timeout |
| Location privacy | Rounded to 2 decimals (~1 km) in the browser, sent by POST (never in a URL or access log), removed before a spin is saved |
| Open redirect | `?next=` accepted only for paths inside the site |
| Suspended accounts | Checked on every request, session ended immediately |
| Leaked config | `private/config.php` and every include file answer **403** when opened directly |

---

## 🌐 External Data

| Data | Source | Cache |
|---|---|---|
| Exchange rates | [ExchangeRate-API (open access)](https://www.exchangerate-api.com/docs/free), fallback [Frankfurter](https://www.frankfurter.app/) | 12 hours |
| Weather & 4-day forecast | [Open-Meteo](https://open-meteo.com/) | 1 hour per location |
| Map tiles | [OpenStreetMap](https://www.openstreetmap.org/) via self-hosted Leaflet | browser |
| Photos | [Wikimedia Commons](https://commons.wikimedia.org/) (links and credits stored in `rj_photos`) | browser |
| Flags | [flagcdn.com](https://flagcdn.com/) | browser |
| Country outlines & borders | [Natural Earth](https://www.naturalearthdata.com/) via [world-atlas](https://github.com/topojson/world-atlas), built once with `tools/build_shapes.mjs` | static JSON |
| Distance | Haversine formula, computed in PHP | — |

When an API fails, the last good response is reused and the API isn't called again for 10 minutes, so an outage never adds a timeout to every page view. The admin dashboard shows whether each source is reachable.

---

## 📁 Project Structure

```
RandomJourney/
├── index.php              # Home: destinations list
├── country.php            # Country: visa for your passport, risks, costs, emergency numbers
├── city.php               # City: map of all places, weather
├── place.php              # Place: prices, distance, safety tips, nearby, reviews
├── recommend.php          # Weighted recommendation system
├── random.php             # Random spin (one place or whole day)
├── review.php             # Review actions: save, delete, helpful, report (POST)
├── favorite.php           # Save / unsave a place (POST)
├── register.php · login.php · logout.php · profile.php
├── admin/
│   ├── index.php          # Overview, content gaps, API status
│   ├── list.php           # Generic table view  (?e=country|city|place|safety|risk|visa)
│   ├── edit.php           # Generic create / edit form
│   ├── delete.php         # Generic delete (POST + CSRF)
│   ├── users.php          # Roles and suspensions
│   └── reports.php        # Review moderation
├── includes/
│   ├── bootstrap.php      # Loads config, DB, session, security — required by every page
│   ├── auth.php           # Register, login, rate limiting, access control
│   ├── security.php       # Headers, CSP, session, CSRF
│   ├── admin.php          # Entity definitions that drive the whole admin panel
│   ├── discover.php       # Filters + scoring shared by Recommend and Random
│   ├── services.php       # Exchange rates, weather, distance, API cache
│   ├── catalog.php        # Queries for country / city / place pages
│   ├── reviews.php        # Reviews, ratings, reports, saved places
│   ├── geo.php            # ISO alpha-3 codes, country outlines, point-in-country test
│   ├── tarot.php          # Deck order, collected cards, tarot card HTML
│   ├── trips.php          # Trip planner queries and budget
│   ├── not_found.php      # "Lost in the archives" 404 page
│   ├── data/              # shapes.json (tarot outlines), borders.json (home-country check)
│   └── ...                # helpers, db, form, header, footer, panels
├── assets/
│   ├── css/style.css      # "Wanderer's Library" theme
│   ├── css/fonts.css      # Cinzel, IM Fell English, Taviraj (self-hosted)
│   ├── js/app.js          # Location toggle, pickers, dust motes, confirm, autosubmit
│   ├── js/dice.js         # The 3D d20 (ES module, loaded only with WebGL)
│   ├── js/theme.js        # Applies night mode before the page paints
│   ├── js/map.js          # Leaflet maps built from JSON (no inline JS)
│   ├── fonts/             # woff2 files + OFL licence
│   ├── img/card-back.svg  # Back of the tarot cards: a gilt globe (Natural Earth land)
│   └── vendor/            # Leaflet 1.9.4 and three.js r169, self-hosted
├── private/
│   └── config.example.php # Copy to config.php (git-ignored)
├── database/
│   ├── schema.sql              # 24 tables + 1 view (drops rj_* tables first!)
│   ├── seed_countries.sql      # 249 countries with Thai names and currencies
│   ├── seed_destinations.sql   # 20 countries, 26 cities, 287 places, risks, visas, tips
│   ├── seed_photos.sql         # 1,213 photo links with credits
│   └── migrate_2026_11.sql     # Upgrade an existing database (adds photos and trips)
├── tools/                 # seed data, SQL generators, outline builder, photo collector
└── docs/DEPLOY.th.md      # Step-by-step deployment guide in Thai
```

---

## ⚙️ How to Run

### Requirements

- PHP **8.1+** with `pdo_mysql`, `curl`, `mbstring` (Argon2id support recommended)
- MySQL **8.0.19+** (uses `CHECK` constraints and `INSERT … AS new` upserts)

### Option A — Angsila / any shared Apache host

```bash
git clone https://github.com/68160064PhattaradanaiSuwannahong/RandomJourney.git
cd RandomJourney
cp private/config.example.php private/config.php   # then fill in the DB password
```

1. In phpMyAdmin, import in this order: `schema.sql` → `seed_countries.sql` → `seed_destinations.sql` → `seed_photos.sql`
   (upgrading an existing install: `migrate_2026_11.sql` → `seed_destinations.sql` → `seed_photos.sql`; don't re-run `schema.sql`)
2. Upload the folder to `public_html/RandomJourney/` (you can skip `database/`, `tools/`, `docs/`)
3. Register an account on the site, then make it an admin:
   ```sql
   UPDATE rj_users SET role = 'admin' WHERE username = 'your_username';
   ```

> ⚠️ **Don't add `.htaccess` files** — Angsila doesn't allow the directives and returns *500* for the whole site. Protection is done in PHP instead.

### Option B — Local with PHP's built-in server

```bash
mysql -u root -p -e "CREATE DATABASE randomjourney CHARACTER SET utf8mb4"
mysql -u root -p randomjourney < database/schema.sql
mysql -u root -p randomjourney < database/seed_countries.sql
mysql -u root -p randomjourney < database/seed_destinations.sql
mysql -u root -p randomjourney < database/seed_photos.sql
cp private/config.example.php private/config.php
# edit config.php: db name/user/pass, 'base_url' => '', 'debug' => true
php -S localhost:8000
```

Open http://localhost:8000.

### Regenerating the destination data

```bash
python3 tools/gen_seed_destinations.py database/seed_destinations.sql
```

The SQL is idempotent: re-importing adds new places without duplicating existing ones or deleting reviews.

---

## 🛠️ Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.3, no framework, PDO |
| Database | MySQL 8.0 |
| Frontend | Hand-written HTML/CSS, vanilla JavaScript (progressive enhancement — every page works without JS) |
| Maps | Leaflet 1.9.4 + OpenStreetMap tiles |
| 3D | three.js r169 (the d20), WebGL with a no-motion / no-WebGL fallback |
| Fonts | Cinzel (display), IM Fell English (leads), Taviraj (body, Thai + Latin), all self-hosted |
| Hosting | Apache on BUU Angsila (user-dir, `~68160064`) |
| Testing | End-to-end checks with Python `requests` and Playwright against a local Apache set up like Angsila (test scripts not included) |

---

## 🧠 Design Decisions

#### 1. No framework
The course server is shared hosting with plain Apache + PHP. Vanilla PHP keeps deployment to "upload the folder" and makes every part of the system visible for grading.

#### 2. One description per content type
`includes/admin.php` describes each entity (table, list query, form fields, validation). The admin pages are generic, so adding a field is a one-line change and table names never come from user input.

#### 3. Recommend and Random share filters but not semantics
Same form, two meanings: weights for ranking vs hard limits for chance. This makes the difference between the two features concrete instead of cosmetic.

#### 4. Explainable results
A score alone isn't convincing, so every recommendation lists *why* it matched, and a failed spin says which single filter is blocking results.

#### 5. Risk as six numbers, not one
"Is it safe?" depends on who's asking. Splitting risk lets a user accept natural hazards but avoid crime — and lets each risk carry its own advice.

#### 6. Protection without `.htaccess`
Angsila rejects `.htaccess` directives, so secrets are guarded in PHP: config and include files refuse to run unless loaded by `bootstrap.php`.

#### 7. Self-hosted Leaflet, cached APIs
Fewer third-party requests, a tighter Content Security Policy, and pages that stay fast when a free API has an outage.

---

## 🔮 Future Work

- [ ] Trip articles: user-written guides linking several places (tables `rj_articles` already exist)
- [ ] Photo uploads for reviews (the server lacks `gd`, so resizing would happen in the browser)
- [ ] Thai / English interface switch
- [x] Trip planner with day-by-day routes and total budget
- [ ] Travel-time estimates from a routing API instead of straight-line distance

---

## 👥 Team

<div align="center">

<table>
  <tr>
    <td align="center" width="240">
      <a href="https://github.com/68160064PhattaradanaiSuwannahong">
        <img src="https://github.com/68160064PhattaradanaiSuwannahong.png?size=120" width="100" height="100" alt="68160064PhattaradanaiSuwannahong"><br>
        <b>Phattaradanai Suwannahong</b>
      </a><br>
      <sub>🍔 · 68160064 · Owner</sub>
    </td>
    <td align="center" width="240">
      <a href="https://github.com/68160186Chayangkun">
        <img src="https://github.com/68160186Chayangkun.png?size=120" width="100" height="100" alt="68160186Chayangkun"><br>
        <b>Chayangkun Peanhasilp</b>
      </a><br>
      <sub>🤒 · 68160186 · Contributor</sub>
    </td>
    <td align="center" width="240">
      <a href="https://github.com/68160201Phornchai">
        <img src="https://github.com/68160201Phornchai.png?size=120" width="100" height="100" alt="68160201Phornchai"><br>
        <b>Phornchai Phueakladprow</b>
      </a><br>
      <sub>🐡 · 68160201 · Contributor</sub>
    </td>
  </tr>
</table>

[![Contributors](https://img.shields.io/github/contributors/68160064PhattaradanaiSuwannahong/RandomJourney?style=for-the-badge&color=24479e)](https://github.com/68160064PhattaradanaiSuwannahong/RandomJourney/graphs/contributors)

</div>

---

## 📖 Credits & Data Sources

1. Travel advisory levels — [U.S. Department of State Travel Advisories](https://travel.state.gov/traveladvisories)
2. Visa rules for Thai passports — embassies of Japan, Italy, Egypt and Peru (see `official_url` per rule)
3. Weather — [Open-Meteo](https://open-meteo.com/) (CC BY 4.0)
4. Exchange rates — [ExchangeRate-API](https://www.exchangerate-api.com/) and [Frankfurter](https://www.frankfurter.app/) (ECB data)
5. Maps — © [OpenStreetMap](https://www.openstreetmap.org/copyright) contributors, rendered with [Leaflet](https://leafletjs.com/) (BSD-2-Clause)
6. Flags — [flagcdn.com](https://flagcdn.com/)
7. Country names and currencies — ISO 3166 / CLDR via `pycountry` and `babel`

> ⚠️ Prices, opening hours and visa rules were compiled in **October 2026** and are approximate. Always confirm with official sources before travelling.

---

<div align="center">

Made with ❤️ and a lot of passport stamps by [**Phattaradanai**](https://github.com/68160064PhattaradanaiSuwannahong), [**Chayangkun**](https://github.com/68160186Chayangkun) and [**Phornchai**](https://github.com/68160201Phornchai)

*Burapha University · PHP & MySQL · Recommend & Random Travel*

</div>
