<?php
// Szövegkezelő segédfüggvények: escapelés, slug, fájlnév-értelmezés, egyszerű formázás.

function esc($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function strip_accents(string $s): string
{
    static $map = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o', 'ú' => 'u', 'ü' => 'u', 'ű' => 'u',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ö' => 'O', 'Ő' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ű' => 'U',
        'ä' => 'a', 'ë' => 'e', 'è' => 'e', 'à' => 'a', 'ç' => 'c', 'ñ' => 'n', 'ô' => 'o', 'õ' => 'o', 'û' => 'u',
        'Ä' => 'A', 'È' => 'E', 'À' => 'A', 'Ç' => 'C', 'Ñ' => 'N', 'Ô' => 'O', 'Õ' => 'O', 'Û' => 'U',
    ];
    $s = strtr($s, $map);
    if (class_exists('Normalizer')) {
        $s = preg_replace('/\p{Mn}+/u', '', Normalizer::normalize($s, Normalizer::FORM_D));
    }
    return $s;
}

/** URL-barát azonosító: "Őszi séta a parkban" -> "oszi-seta-a-parkban". */
function slugify(string $s): string
{
    $s = strtolower(strip_accents($s));
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    $s = rtrim(substr($s, 0, 80), '-');
    return $s !== '' ? $s : 'iras';
}

/**
 * Fájlnévből cím és (opcionális) dátum.
 * "2019-05-03 Őszi séta.docx" -> ['title' => 'Őszi séta', 'date' => '2019-05-03']
 */
function parse_file_name(string $fileName): array
{
    $base = preg_replace('/\.[^.]+$/', '', $fileName);
    $date = null;
    if (preg_match('/^\s*(\d{4})[-._ ](\d{1,2})[-._ ](\d{1,2})\.?[\s_-]*/', $base, $m)) {
        $date = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        $base = substr($base, strlen($m[0]));
    }
    $title = trim(preg_replace('/\s+/', ' ', str_replace('_', ' ', $base)));
    return ['title' => $title !== '' ? $title : $fileName, 'date' => $date];
}

/** Összehasonlításhoz használt kulcs (dátum-előtag, ékezet, írásjelek nélkül). */
function match_key(string $fileName): string
{
    return slugify(parse_file_name($fileName)['title']);
}

/**
 * Az írások szövege egyszerű jelöléssel tárolódik:
 *  - üres sor = új bekezdés, sima sortörés = sortörés
 *  - **félkövér**, *dőlt*
 * Ez HTML-lé alakítja, biztonságosan (minden más escapelve).
 */
function render_body(string $text): string
{
    $paras = preg_split('/\n\s*\n/', str_replace(["\r\n", "\r"], "\n", $text));
    $out = [];
    foreach ($paras as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $h = esc($p);
        $h = preg_replace('/\*\*([^*\n]+?)\*\*/u', '<strong>$1</strong>', $h);
        $h = preg_replace('/(^|[^*])\*([^*\n]+?)\*(?!\*)/u', '$1<em>$2</em>', $h);
        $h = str_replace("\n", "<br>\n", $h);
        $out[] = "<p>$h</p>";
    }
    return implode("\n", $out);
}

/** Rövid kivonat a listaoldalakhoz. */
function excerpt(string $text, int $len = 220): string
{
    $plain = trim(preg_replace('/\s+/u', ' ', str_replace('*', '', $text)));
    if (mb_strlen($plain) <= $len) return $plain;
    $cut = mb_substr($plain, 0, $len);
    $sp = mb_strrpos($cut, ' ');
    return ($sp ? mb_substr($cut, 0, $sp) : $cut) . '…';
}

function hu_date(?string $iso): string
{
    static $months = ['január', 'február', 'március', 'április', 'május', 'június', 'július',
        'augusztus', 'szeptember', 'október', 'november', 'december'];
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string)$iso, $m)) return '';
    return $m[1] . '. ' . $months[(int)$m[2] - 1] . ' ' . (int)$m[3] . '.';
}

/** Az adatbázisban UTC-ben tárolt időpont magyar formában. */
function hu_datetime(?string $sql): string
{
    if (!$sql) return '';
    try {
        $d = new DateTime($sql, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone('Europe/Budapest'));
        return hu_date($d->format('Y-m-d')) . ' ' . $d->format('H:i');
    } catch (Exception $e) {
        return '';
    }
}
