<?php
// Közös indítás: beállítások, adatbázis, munkamenet, segédfüggvények.
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('APP_DIR', __DIR__);
define('DATA_DIR', ROOT . '/data');
define('UPLOAD_DIR', ROOT . '/feltoltesek');
define('CONTENT_DIR', ROOT . '/tartalom');
define('WRITINGS_DIR', CONTENT_DIR . '/irasok');
define('IMAGES_DIR', CONTENT_DIR . '/kepek');

$GLOBALS['CONFIG'] = require APP_DIR . '/config.php';
function cfg(string $key) { return $GLOBALS['CONFIG'][$key] ?? null; }

const CATEGORIES = ['proza' => 'Próza', 'vers' => 'Versek'];

date_default_timezone_set('Europe/Budapest');
mb_internal_encoding('UTF-8');

require APP_DIR . '/text.php';
require APP_DIR . '/docx.php';
require APP_DIR . '/db.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/import.php';
require APP_DIR . '/views.php';

/* ---------- URL-ek ---------- */

/** Az oldal gyökere a domainen belül (ha almappába kerül, az is működik). */
function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    }
    return $base;
}

/** Belső link. Ha nincs URL-átírás (mod_rewrite), index.php/... formát ad. */
function url(string $path = '/'): string
{
    $path = '/' . ltrim($path, '/');
    if (!cfg('szep_url')) return base_path() . '/index.php' . ($path === '/' ? '' : $path);
    return base_path() . $path;
}

/** Statikus fájl (CSS, kép) linkje. */
function asset(string $path): string
{
    return base_path() . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
}

/** Kép linkje az adatbázisban tárolt útvonalból ("kepek/x.jpg" vagy "feltoltesek/y.jpg"). */
function img_url(string $p): string
{
    return str_starts_with($p, 'kepek/') ? asset('tartalom/' . $p) : asset($p);
}

function redirect(string $to, int $code = 302): never
{
    header('Location: ' . $to, true, $code);
    exit;
}

/** Csak a saját oldalunkon belüli visszairányítás engedett. */
function safe_back(?string $u): string
{
    $u = (string)$u;
    return ($u !== '' && $u[0] === '/' && !str_starts_with($u, '//')) ? $u : url('/');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function set_flash(string $type, string $text): void { $_SESSION['flash'] = ['type' => $type, 'text' => $text]; }
function take_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }

function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

function wants_json(): bool { return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json'); }

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
