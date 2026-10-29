<?php
/**
 * External data: exchange rates, weather, distances.
 *
 * API responses are cached in rj_api_cache so pages stay fast and we stay
 * well inside the free APIs' limits. If an API is down, functions return null
 * and pages simply hide that panel.
 */
declare(strict_types=1);

if (!defined('RJ_BOOT')) { http_response_code(403); exit; }

const RJ_FX_TTL_HOURS = 12;
const RJ_WEATHER_TTL_MINUTES = 60;

// ---------------------------------------------------------------------
// HTTP + cache
// ---------------------------------------------------------------------

/** GET a JSON URL; returns the decoded array or null on any failure. */
function http_get_json(string $url, int $timeout = 5): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'RandomJourney/1.0 (BUU student project)',
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($body) || $status !== 200) {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/** Seconds to wait before retrying an API that just failed. */
const RJ_API_RETRY_SECONDS = 600;

/**
 * Return cached data for $key, or call $fetch and cache its result.
 *
 * - Results are remembered for the rest of the request (pages ask several times).
 * - If a refresh fails, the old data is reused (marked _stale) and the API is
 *   not tried again for 10 minutes, so an outage never slows every page down.
 */
function cached(string $key, int $ttlSeconds, callable $fetch): ?array
{
    static $memo = [];
    if (array_key_exists($key, $memo)) {
        return $memo[$key];
    }

    $stmt = db()->prepare('SELECT payload, expires_at > NOW() AS fresh, fetched_at FROM rj_api_cache WHERE cache_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    $old = $row ? json_decode($row['payload'], true) : null;
    $oldUsable = is_array($old) && empty($old['_failed']);

    if ($row && (int) $row['fresh']) {
        return $memo[$key] = $oldUsable ? $old + ['_fetched_at' => $row['fetched_at']] : null;
    }

    $fresh = $fetch();
    if ($fresh !== null) {
        db()->prepare(
            'INSERT INTO rj_api_cache (cache_key, payload, fetched_at, expires_at)
             VALUES (?, ?, NOW(), NOW() + INTERVAL ? SECOND)
             AS new ON DUPLICATE KEY UPDATE payload = new.payload, fetched_at = new.fetched_at, expires_at = new.expires_at'
        )->execute([$key, json_encode($fresh, JSON_UNESCAPED_UNICODE), $ttlSeconds]);
        return $memo[$key] = $fresh + ['_fetched_at' => date('Y-m-d H:i:s')];
    }

    // Failed: keep serving old data (or remember the failure) and back off
    if ($row) {
        db()->prepare('UPDATE rj_api_cache SET expires_at = NOW() + INTERVAL ? SECOND WHERE cache_key = ?')
            ->execute([RJ_API_RETRY_SECONDS, $key]);
    } else {
        db()->prepare('INSERT INTO rj_api_cache (cache_key, payload, fetched_at, expires_at) VALUES (?, ?, NOW(), NOW() + INTERVAL ? SECOND)')
            ->execute([$key, json_encode(['_failed' => true]), RJ_API_RETRY_SECONDS]);
    }
    return $memo[$key] = $oldUsable ? $old + ['_fetched_at' => $row['fetched_at'], '_stale' => true] : null;
}

// ---------------------------------------------------------------------
// Exchange rates
// ---------------------------------------------------------------------

/**
 * Rates for 1 USD in every currency, e.g. ['THB' => 33.1, 'JPY' => 152.4, ...].
 * Source: open.er-api.com (covers EGP and PEN); Frankfurter (ECB) as fallback.
 */
function fx_rates(): ?array
{
    $data = cached('fx:USD', RJ_FX_TTL_HOURS * 3600, function (): ?array {
        $r = http_get_json('https://open.er-api.com/v6/latest/USD');
        if (($r['result'] ?? null) === 'success' && !empty($r['rates'])) {
            return ['rates' => $r['rates'], 'source' => 'open.er-api.com', 'date' => $r['time_last_update_utc'] ?? null];
        }
        $r = http_get_json('https://api.frankfurter.app/latest?from=USD');
        if (!empty($r['rates'])) {
            return ['rates' => ['USD' => 1] + $r['rates'], 'source' => 'frankfurter.app (ECB)', 'date' => $r['date'] ?? null];
        }
        return null;
    });
    return $data;
}

/** Convert an amount between currencies, or null if a rate is missing. */
function fx_convert(float $amount, string $from, string $to): ?float
{
    if ($from === $to) {
        return $amount;
    }
    $rates = fx_rates()['rates'] ?? null;
    if (!isset($rates[$from], $rates[$to]) || (float) $rates[$from] <= 0) {
        return null;
    }
    return $amount / (float) $rates[$from] * (float) $rates[$to];
}

/** Currency the current visitor wants prices shown in. */
function display_currency(): string
{
    return current_user()['preferred_currency'] ?? (string) config('app.default_currency', 'THB');
}

/** Number formatted for a currency: no decimals for big amounts or JPY-like currencies. */
function money_number(float $amount, string $currency): string
{
    $decimals = $amount >= 10 || in_array($currency, ['JPY', 'KRW', 'VND', 'IDR'], true) ? 0 : 2;
    $formatted = number_format($amount, $decimals);
    return $decimals === 2 ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
}

/** "1,500 THB" */
function money(float $amount, string $currency): string
{
    return money_number($amount, $currency) . ' ' . $currency;
}

/**
 * Local price plus the visitor's currency, e.g. "1,000 JPY ≈ 236 THB".
 * Shows only the local price if conversion isn't possible.
 */
function price_pair(?float $amount, string $localCurrency): string
{
    if ($amount === null) {
        return '';
    }
    $local = money($amount, $localCurrency);
    $target = display_currency();
    if ($target === $localCurrency) {
        return $local;
    }
    $converted = fx_convert($amount, $localCurrency, $target);
    return $converted === null ? $local : $local . ' ≈ ' . money($converted, $target);
}

/** "500–1,000 THB ≈ 14–28 USD" */
function price_range(?float $min, ?float $max, string $currency): string
{
    if ($min === null && $max === null) {
        return '';
    }
    if ($min === null || $max === null || $min == $max) {
        return price_pair($min ?? $max, $currency);
    }
    $text = money_number($min, $currency) . '–' . money($max, $currency);
    $target = display_currency();
    if ($target !== $currency) {
        $a = fx_convert($min, $currency, $target);
        $b = fx_convert($max, $currency, $target);
        if ($a !== null && $b !== null) {
            $text .= ' ≈ ' . money_number($a, $target) . '–' . money($b, $target);
        }
    }
    return $text;
}

// ---------------------------------------------------------------------
// Weather (Open-Meteo, no API key)
// ---------------------------------------------------------------------

function weather(float $lat, float $lng): ?array
{
    $lat = round($lat, 2);
    $lng = round($lng, 2);
    return cached("wx:{$lat},{$lng}", RJ_WEATHER_TTL_MINUTES * 60, function () use ($lat, $lng): ?array {
        $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude'      => $lat,
            'longitude'     => $lng,
            'current'       => 'temperature_2m,apparent_temperature,relative_humidity_2m,weather_code,wind_speed_10m',
            'daily'         => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,uv_index_max',
            'timezone'      => 'auto',
            'forecast_days' => 4,
        ]);
        $r = http_get_json($url);
        return isset($r['current'], $r['daily']) ? ['current' => $r['current'], 'daily' => $r['daily'], 'timezone' => $r['timezone'] ?? null] : null;
    });
}

/** WMO weather code -> [label, emoji]. */
function weather_label(int $code): array
{
    return match (true) {
        $code === 0               => ['Clear sky', '☀️'],
        $code <= 2                => ['Partly cloudy', '⛅'],
        $code === 3               => ['Overcast', '☁️'],
        in_array($code, [45, 48]) => ['Fog', '🌫️'],
        $code <= 57               => ['Drizzle', '🌦️'],
        $code <= 67               => ['Rain', '🌧️'],
        $code <= 77               => ['Snow', '🌨️'],
        $code <= 82               => ['Rain showers', '🌦️'],
        $code <= 86               => ['Snow showers', '🌨️'],
        default                   => ['Thunderstorm', '⛈️'],
    };
}

/** Short practical advice from the forecast, used as a packing hint. */
function weather_advice(array $wx): array
{
    $tips = [];
    $max = max($wx['daily']['temperature_2m_max'] ?? [0]);
    $min = min($wx['daily']['temperature_2m_min'] ?? [0]);
    $rain = max($wx['daily']['precipitation_probability_max'] ?? [0]);
    $uv = max($wx['daily']['uv_index_max'] ?? [0]);

    if ($max >= 32) $tips[] = 'Very hot: carry water and plan outdoor sights for early morning.';
    if ($min <= 8)  $tips[] = 'Cold mornings and evenings: pack a warm jacket.';
    if ($rain >= 50) $tips[] = 'Rain likely in the next few days: bring an umbrella or rain jacket.';
    if ($uv >= 8)   $tips[] = 'Very high UV: wear sunscreen, a hat and sunglasses.';
    return $tips;
}

// ---------------------------------------------------------------------
// Distance
// ---------------------------------------------------------------------

/** Great-circle distance in km (Haversine formula). */
function distance_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

function format_distance(float $km): string
{
    return $km < 1 ? round($km * 1000 / 50) * 50 . ' m' : ($km < 10 ? number_format($km, 1) : number_format($km)) . ' km';
}

/** Rough travel time hint for a straight-line distance. */
function travel_hint(float $km): string
{
    return match (true) {
        $km < 1.5  => 'about ' . max(5, (int) round($km / 4.5 * 60 / 5) * 5) . ' min on foot',
        $km < 40   => 'about ' . max(10, (int) round($km * 1.3 / 25 * 60 / 5) * 5) . ' min by car or taxi',
        default    => 'about ' . number_format($km * 1.3 / 60, 1) . ' h by road',
    };
}

// ---------------------------------------------------------------------
// Flags (images, because Windows has no flag emoji)
// ---------------------------------------------------------------------

function flag_img(string $iso2, int $width = 28): string
{
    $iso = strtolower($iso2);
    if (!preg_match('/^[a-z]{2}$/', $iso)) {
        return '';
    }
    return sprintf(
        '<img class="flag" src="https://flagcdn.com/%s.svg" width="%d" height="%d" alt="" loading="lazy">',
        $iso, $width, (int) round($width * 0.75)
    );
}
