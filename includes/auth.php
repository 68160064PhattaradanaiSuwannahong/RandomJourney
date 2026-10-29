<?php
/**
 * Registration, login, logout and access control.
 */
declare(strict_types=1);

if (!defined('RJ_BOOT')) { http_response_code(403); exit; }

// Argon2id when the server supports it (Angsila does), bcrypt otherwise
define('RJ_PASSWORD_ALGO', defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);

// Verified against when the username doesn't exist, so a wrong username
// takes as long as a wrong password and can't be detected by timing.
const RJ_DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$cTJWdlpTVGRXU0FReTNPTw$xv6HTVy5O3RKETNi2R+cZ6OQd1bOMr6Cfne3PAnx8HE';

const RJ_PASSWORD_MIN = 8;
const RJ_PASSWORD_MAX = 128;

// ---------------------------------------------------------------------
// Current user
// ---------------------------------------------------------------------

/** The logged-in user's row, read from the DB once per request (pass true to re-read). */
function current_user(bool $refresh = false): ?array
{
    static $loaded = false;
    static $user = null;

    if ($loaded && !$refresh) {
        return $user;
    }
    $loaded = true;
    $user = null;

    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id)) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT u.id, u.username, u.email, u.role, u.display_name, u.residence_country_id,
                u.nationality_country_id, u.preferred_currency, u.bio, u.is_active,
                u.created_at, u.last_login_at
         FROM rj_users u WHERE u.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    // Account deleted or suspended since login: end the session now
    if (!$row || !(int) $row['is_active']) {
        logout_user();
        return null;
    }

    $user = $row;
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? null) === 'admin';
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        flash('info', 'Log in to see that page.');
        redirect('login.php?next=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '')));
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>No access</title>'
           . '<body style="font-family:sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem">'
           . '<h1>Admins only</h1><p>Your account does not have access to this page.</p></body>';
        exit;
    }
    return $user;
}

// ---------------------------------------------------------------------
// Session login / logout
// ---------------------------------------------------------------------

function login_user(int $userId): void
{
    // New session ID on login prevents session fixation
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    unset($_SESSION['csrf_token']);

    db()->prepare('UPDATE rj_users SET last_login_at = NOW() WHERE id = ?')->execute([$userId]);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $p['path'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);
    }
    session_destroy();
    // Fresh empty session so flash messages still work on the next page
    session_start();
    session_regenerate_id(true);
}

// ---------------------------------------------------------------------
// Login with brute-force protection
// ---------------------------------------------------------------------

/**
 * @return array{ok: bool, error: ?string}
 */
function attempt_login(string $identifier, string $password): array
{
    $identifier = mb_strtolower(trim($identifier));
    $minutes = (int) config('security.lockout_minutes', 15);

    if ($identifier === '' || $password === '') {
        return ['ok' => false, 'error' => 'Enter your username or email and your password.'];
    }

    if (is_login_locked($identifier, $minutes)) {
        return [
            'ok'    => false,
            'error' => "Too many failed attempts. Wait {$minutes} minutes, then try again.",
        ];
    }

    $stmt = db()->prepare(
        'SELECT id, password_hash, is_active FROM rj_users WHERE username = ? OR email = ? LIMIT 1'
    );
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    $valid = password_verify($password, $user['password_hash'] ?? RJ_DUMMY_HASH);

    if (!$user || !$valid) {
        record_login_attempt($identifier, false);
        // Same message for both cases: don't reveal which usernames exist
        return ['ok' => false, 'error' => 'Username/email or password is incorrect.'];
    }

    if (!(int) $user['is_active']) {
        record_login_attempt($identifier, false);
        return ['ok' => false, 'error' => 'This account has been suspended. Contact the site admin.'];
    }

    // Upgrade old hashes automatically (e.g. if PHP's default cost changes)
    if (password_needs_rehash($user['password_hash'], RJ_PASSWORD_ALGO)) {
        db()->prepare('UPDATE rj_users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, RJ_PASSWORD_ALGO), $user['id']]);
    }

    record_login_attempt($identifier, true);
    login_user((int) $user['id']);
    return ['ok' => true, 'error' => null];
}

function is_login_locked(string $identifier, int $minutes): bool
{
    // Failures for this username since its last successful login, inside the window.
    // Compared by id, not time: several attempts can share the same second.
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM rj_login_attempts
         WHERE username = :u AND success = 0
           AND attempted_at > NOW() - INTERVAL :m MINUTE
           AND id > COALESCE(
                 (SELECT MAX(id) FROM rj_login_attempts WHERE username = :u2 AND success = 1), 0)'
    );
    $stmt->execute(['u' => $identifier, 'u2' => $identifier, 'm' => $minutes]);
    if ((int) $stmt->fetchColumn() >= (int) config('security.max_failed_per_user', 5)) {
        return true;
    }

    // Failures from this IP across all usernames
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM rj_login_attempts
         WHERE ip_address = ? AND success = 0 AND attempted_at > NOW() - INTERVAL ? MINUTE'
    );
    $stmt->execute([client_ip(), $minutes]);
    return (int) $stmt->fetchColumn() >= (int) config('security.max_failed_per_ip', 20);
}

function record_login_attempt(string $identifier, bool $success): void
{
    db()->prepare('INSERT INTO rj_login_attempts (ip_address, username, success) VALUES (?, ?, ?)')
        ->execute([client_ip(), mb_substr($identifier, 0, 190), $success ? 1 : 0]);

    // Occasionally purge old rows so the table stays small
    if (random_int(1, 50) === 1) {
        db()->exec('DELETE FROM rj_login_attempts WHERE attempted_at < NOW() - INTERVAL 7 DAY');
    }
}

// ---------------------------------------------------------------------
// Registration
// ---------------------------------------------------------------------

/** Field errors for a password, or [] if it's acceptable. */
function validate_new_password(string $password, string $confirm, string $field = 'password'): array
{
    $errors = [];
    $length = mb_strlen($password);
    if ($length < RJ_PASSWORD_MIN) {
        $errors[$field] = 'Use at least ' . RJ_PASSWORD_MIN . ' characters.';
    } elseif ($length > RJ_PASSWORD_MAX) {
        $errors[$field] = 'Use at most ' . RJ_PASSWORD_MAX . ' characters.';
    } elseif ($password !== $confirm) {
        $errors[$field . '_confirm'] = 'The two passwords don\'t match.';
    }
    return $errors;
}

/**
 * Validate and create an account.
 *
 * @return array{errors: array<string,string>, user_id: ?int}
 */
function register_user(array $in): array
{
    $errors = [];

    $username = mb_strtolower($in['username']);
    if (!preg_match('/^[a-z0-9_]{3,30}$/', $username)) {
        $errors['username'] = 'Use 3–30 letters, numbers or underscores.';
    }

    $email = mb_strtolower($in['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $errors['email'] = 'Enter a valid email address.';
    }

    $displayName = $in['display_name'];
    if (mb_strlen($displayName) > 60) {
        $errors['display_name'] = 'Use at most 60 characters.';
    }

    $errors += validate_new_password($in['password'], $in['password_confirm']);

    if (!country_exists($in['residence_country_id'])) {
        $errors['residence_country_id'] = 'Choose the country you live in.';
    }
    if (!country_exists($in['nationality_country_id'])) {
        $errors['nationality_country_id'] = 'Choose your nationality.';
    }

    if (!isset($errors['username']) || !isset($errors['email'])) {
        $stmt = db()->prepare('SELECT username, email FROM rj_users WHERE username = ? OR email = ?');
        $stmt->execute([$username, $email]);
        foreach ($stmt->fetchAll() as $row) {
            if ($row['username'] === $username) {
                $errors['username'] = 'That username is taken. Try another one.';
            }
            if ($row['email'] === $email) {
                $errors['email'] = 'An account with this email already exists. Log in instead.';
            }
        }
    }

    if ($errors) {
        return ['errors' => $errors, 'user_id' => null];
    }

    // Default display currency = currency of the country the user lives in
    $stmt = db()->prepare('SELECT currency_code FROM rj_countries WHERE id = ?');
    $stmt->execute([$in['residence_country_id']]);
    $currency = $stmt->fetchColumn() ?: null;

    try {
        db()->prepare(
            'INSERT INTO rj_users
                (username, email, password_hash, display_name,
                 residence_country_id, nationality_country_id, preferred_currency)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $username,
            $email,
            password_hash($in['password'], RJ_PASSWORD_ALGO),
            $displayName !== '' ? $displayName : null,
            $in['residence_country_id'],
            $in['nationality_country_id'],
            $currency,
        ]);
    } catch (PDOException $e) {
        // Someone took the name between our check and the insert
        if (is_duplicate_key($e)) {
            return ['errors' => ['username' => 'That username or email was just taken. Try another one.'], 'user_id' => null];
        }
        throw $e;
    }

    return ['errors' => [], 'user_id' => (int) db()->lastInsertId()];
}
