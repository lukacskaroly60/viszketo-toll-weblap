<?php
// Munkamenet, bejelentkezés, CSRF-védelem, anonim látogató-azonosító, sebességkorlát.

function start_session(): void
{
    session_name('vtsid');
    session_set_cookie_params([
        'lifetime' => 60 * 24 * 3600,
        'path' => base_path() . '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string)(60 * 24 * 3600));
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $id = $_SESSION['uid'] ?? null;
        $user = $id ? one('SELECT id, email, name, role FROM users WHERE id = ?', [$id]) : null;
    }
    return $user;
}

function is_admin(): bool { return (current_user()['role'] ?? '') === 'admin'; }

function log_in(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;
}

function log_out(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

function normalize_email(?string $e): string { return strtolower(trim((string)$e)); }

function valid_email(string $e): bool
{
    return strlen($e) <= 200 && (bool)preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $e);
}

/** Anonim látogató-azonosító a lájkokhoz (bejelentkezés nélkül is). */
function visitor_id(): string
{
    static $vid = null;
    if ($vid) return $vid;
    $vid = $_COOKIE['vid'] ?? '';
    if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $vid)) {
        $vid = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        setcookie('vid', $vid, [
            'expires' => time() + 10 * 365 * 86400, 'path' => base_path() . '/',
            'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    return $vid;
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_t" value="' . esc(csrf_token()) . '">';
}

/** Minden POST-nál: a kérés a saját oldalunkról jön-e, és stimmel-e a token. */
function check_post(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    if ($origin !== '') {
        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        $selfHost = $_SERVER['HTTP_HOST'] ?? '';
        if ($host . ($port ? ":$port" : '') !== $selfHost && $host !== preg_replace('/:\d+$/', '', $selfHost)) {
            fail(403, 'Érvénytelen kérés.');
        }
    }
    $token = $_POST['_t'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        fail(403, 'A lap elavult. Kérlek, töltsd újra, és próbáld meg még egyszer.');
    }
}

/* ---------- sebességkorlát ---------- */

/** true, ha még belefér; false, ha túl sok próbálkozás volt az időablakban. */
function rate_ok(string $bucket, int $max, int $windowSec): bool
{
    $now = time();
    if (random_int(1, 50) === 1) q('DELETE FROM attempts WHERE at < ?', [$now - 86400]);
    $n = (int)one('SELECT COUNT(*) AS n FROM attempts WHERE bucket = ? AND at > ?', [$bucket, $now - $windowSec])['n'];
    if ($n >= $max) return false;
    q('INSERT INTO attempts (bucket, at) VALUES (?, ?)', [$bucket, $now]);
    return true;
}

function require_admin(): void
{
    if (is_admin()) return;
    if (!current_user()) redirect(url('/belepes') . '?vissza=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/'));
    fail(403, 'Ehhez az oldalhoz nincs jogosultságod.');
}
