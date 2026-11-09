<?php
// Csak helyi kipróbáláshoz (a tárhelyre NEM kell feltölteni):
//   php -S localhost:8080 -t www tools/dev-router.php
$file = realpath(__DIR__ . '/../www' . rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)));
$www = realpath(__DIR__ . '/../www');
if ($file && is_file($file) && str_starts_with($file, $www) && !str_ends_with($file, '.php')) {
    // a .htaccess tiltásait itt kézzel utánozzuk
    if (preg_match('#/(app|data|tartalom/irasok)/|tartalom\.json$|/\.#', substr($file, strlen($www)))) { http_response_code(403); exit('Tiltott'); }
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/../www/index.php';
