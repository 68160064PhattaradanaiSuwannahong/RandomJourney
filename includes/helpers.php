<?php
/**
 * Small helpers used everywhere.
 */
declare(strict_types=1);

if (!defined('RJ_BOOT')) { http_response_code(403); exit; }

/** Read a config value with dot notation, e.g. config('db.host'). */
function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['rj_config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/** Escape text for HTML output. Use on EVERY value printed into a page. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build a site URL: url('login.php') -> /~68160064/RandomJourney/login.php */
function url(string $path = ''): string
{
    return rtrim((string) config('app.base_url', ''), '/') . '/' . ltrim($path, '/');
}

/** URL of a static asset with a cache-busting version. */
function asset(string $path): string
{
    $file = RJ_WWW . '/assets/' . ltrim($path, '/');
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $version;
}

function redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, '/') ? $path : url($path)));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Trimmed string from POST data. */
function post_str(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

/** Positive integer from POST data, or null. */
function post_int(string $key): ?int
{
    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $value === false ? null : $value;
}

/**
 * Remote IP. REMOTE_ADDR only: X-Forwarded-For is set by the client
 * and could be faked to dodge the login rate limit.
 */
function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

// ---------------------------------------------------------------------
// Flash messages (shown once on the next page)
// ---------------------------------------------------------------------

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/**
 * Accept a "next" redirect target only if it points inside this site,
 * so login.php?next=https://evil.example can't send users elsewhere.
 */
function safe_next(?string $next, string $fallback = ''): string
{
    $base = rtrim((string) config('app.base_url', ''), '/') . '/';
    if (
        is_string($next)
        && str_starts_with($next, $base)
        && !str_contains($next, '//')
        && !str_contains($next, '\\')
        && !preg_match('/[\r\n]/', $next)
    ) {
        return $next;
    }
    return url($fallback);
}

// ---------------------------------------------------------------------
// Lookup data
// ---------------------------------------------------------------------

/** All countries for dropdowns, sorted by English name. */
function countries_for_select(): array
{
    static $rows = null;
    if ($rows === null) {
        $rows = db()->query(
            'SELECT id, iso2, name_en, name_th, currency_code FROM rj_countries ORDER BY name_en'
        )->fetchAll();
    }
    return $rows;
}

function country_exists(?int $id): bool
{
    if ($id === null) {
        return false;
    }
    $stmt = db()->prepare('SELECT 1 FROM rj_countries WHERE id = ?');
    $stmt->execute([$id]);
    return (bool) $stmt->fetchColumn();
}

/** Distinct currency codes in use by countries. */
function currencies_for_select(): array
{
    return db()->query(
        'SELECT DISTINCT currency_code FROM rj_countries WHERE currency_code IS NOT NULL ORDER BY currency_code'
    )->fetchAll(PDO::FETCH_COLUMN);
}

/** Flag emoji from an ISO 3166-1 alpha-2 code. */
function flag(string $iso2): string
{
    $iso2 = strtoupper($iso2);
    if (!preg_match('/^[A-Z]{2}$/', $iso2)) {
        return '';
    }
    return mb_chr(0x1F1E6 + ord($iso2[0]) - 65) . mb_chr(0x1F1E6 + ord($iso2[1]) - 65);
}

/** Render <option> tags for the country list. */
function country_options(?int $selectedId): string
{
    $html = '';
    foreach (countries_for_select() as $c) {
        $label = $c['name_en'] . ($c['name_th'] ? ' (' . $c['name_th'] . ')' : '');
        $html .= sprintf(
            '<option value="%d" data-currency="%s"%s>%s</option>',
            $c['id'],
            e($c['currency_code'] ?? ''),
            (int) $c['id'] === $selectedId ? ' selected' : '',
            e($label)
        );
    }
    return $html;
}
