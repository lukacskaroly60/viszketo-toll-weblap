<?php
// Írások és képek importálása a tartalom/ mappából.
//
//   tartalom/irasok/proza/   – prózai írások (.docx, .txt, .md)
//   tartalom/irasok/versek/  – versek
//   tartalom/irasok/         – (közvetlenül ide tett fájl prózának számít)
//   tartalom/kepek/          – illusztrációk (.jpg, .png, .webp, .gif)
//   tartalom/tartalom.json   – (nem kötelező) pontos lista: cím, dátum, kategória,
//                              látható-e, mely képek tartoznak hozzá
//
// Ha nincs tartalom.json, a fájlnevekből dolgozik: a cím a fájl neve, a kép pedig
// a fájlneve alapján kerül az írás mellé (lásd match_image).
//
// Indítás: a szerkesztőfelületen az „Importálás” gombbal.

const TEXT_EXT = ['docx', 'txt', 'md'];
const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
const CATEGORY_DIRS = ['proza' => 'proza', 'próza' => 'proza', 'versek' => 'vers', 'vers' => 'vers'];

function list_files(string $dir, ?array $exts = null): array
{
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (scandir($dir) as $f) {
        if (!is_file("$dir/$f") || preg_match('/^(\.|~\$|OLVASS_EL\.|README\.)/i', $f)) continue;
        if ($exts && !in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $exts, true)) continue;
        $out[] = $f;
    }
    $coll = class_exists('Collator') ? new Collator('hu_HU') : null;
    $coll ? $coll->sort($out) : sort($out);
    return $out;
}

/** A képtár (tartalom/kepek) összes képe. */
function library_images(): array
{
    return list_files(IMAGES_DIR, IMAGE_EXT);
}

function scan_writings(): array
{
    $out = [];
    foreach (list_files(WRITINGS_DIR, TEXT_EXT) as $f) $out[] = ['file' => $f, 'category' => 'proza'];
    foreach (is_dir(WRITINGS_DIR) ? scandir(WRITINGS_DIR) : [] as $d) {
        $cat = CATEGORY_DIRS[mb_strtolower($d)] ?? null;
        if (!$cat || !is_dir(WRITINGS_DIR . "/$d")) continue;
        foreach (list_files(WRITINGS_DIR . "/$d", TEXT_EXT) as $f) $out[] = ['file' => "$d/$f", 'category' => $cat];
    }
    return $out;
}

function read_writing(array $e): array
{
    $full = WRITINGS_DIR . '/' . $e['file'];
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $cat = $e['category'] ?? 'proza';
    ['title' => $title, 'date' => $date] = parse_file_name(basename($e['file']));

    if ($ext === 'docx') {
        $body = $cat === 'vers' ? docx_poem($full) : docx_prose($full);
    } else {
        $body = trim(str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($full))));
        if ($ext === 'md' && preg_match('/^#\s+(.+)\n+/', $body, $m)) {
            $title = trim($m[1]);
            $body = substr($body, strlen($m[0]));
        }
    }
    if (!empty($e['title'])) $title = $e['title'];
    if (!empty($e['date'])) $date = $e['date'];

    // Ha a szöveg első bekezdése (versnél első sora) maga a cím, ne jelenjen meg kétszer.
    $sep = $cat === 'vers' ? "\n" : "\n\n";
    $parts = $cat === 'vers' ? explode("\n", $body) : preg_split('/\n\s*\n/', $body);
    $first = $parts[0] ?? '';
    $drop = $e['dropFirst'] ?? (slugify(str_replace('*', '', $first)) === slugify($title));
    if ($drop) $body = trim(implode($sep, array_slice($parts, 1)));

    if (!$date) $date = date('Y-m-d', filemtime($full));
    return ['title' => $title, 'date' => $date, 'body' => $body, 'category' => $cat, 'hidden' => !empty($e['hidden']) ? 1 : 0];
}

/**
 * Melyik íráshoz tartozik a kép? A leghosszabb olyan írás-kulcs nyer,
 * amellyel a kép neve megegyezik vagy azzal kezdődik (pl. "oszi-seta-2").
 */
function match_image(string $imageFile, array $writingKeys): ?string
{
    $key = match_key($imageFile);
    $ks = array_filter($writingKeys);
    $pick = function (array $arr, bool $longest) {
        if (!$arr) return null;
        usort($arr, fn($a, $b) => $longest ? strlen($b) <=> strlen($a) : strlen($a) <=> strlen($b));
        return $arr[0];
    };
    // 1. pontos egyezés vagy "cím-2", "cím-illusztracio" stb.
    if ($r = $pick(array_filter($ks, fn($wk) => $key === $wk || str_starts_with($key, "$wk-")), true)) return $r;
    // 2. az írás címe szerepel a kép nevében
    if ($r = $pick(array_filter($ks, fn($wk) => strlen($wk) >= 4 && str_contains($key, $wk)), true)) return $r;
    // 3. a kép neve az írás címének eleje
    return $pick(array_filter($ks, fn($wk) => strlen($key) >= 4 && str_starts_with($wk, "$key-")), false);
}

function unique_slug(string $base, ?int $exceptId = null): string
{
    $slug = $base;
    $i = 2;
    while (one('SELECT id FROM writings WHERE slug = ? AND id IS NOT ?', [$slug, $exceptId])) $slug = $base . '-' . $i++;
    return $slug;
}

/** Az import futtatása. Visszaadja a naplót (sorok) és a statisztikát. */
function import_content(): array
{
    @set_time_limit(600);
    $log = [];
    $manifestPath = CONTENT_DIR . '/tartalom.json';
    $useManifest = is_file($manifestPath);
    if ($useManifest) {
        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!is_array($manifest['irasok'] ?? null)) throw new RuntimeException('A tartalom.json hibás.');
        $entries = $manifest['irasok'];
    } else {
        $entries = scan_writings();
    }
    $imageFiles = library_images();
    $imageSet = array_flip($imageFiles);

    $parsed = [];
    foreach ($entries as $e) {
        if (!is_file(WRITINGS_DIR . '/' . $e['file'])) { $log[] = "Hiányzó fájl: {$e['file']}"; continue; }
        try {
            $w = read_writing($e);
            $parsed[] = $w + $e + ['key' => match_key($w['title']), 'imgs' => []];
        } catch (Throwable $ex) {
            $log[] = "Nem sikerült beolvasni: {$e['file']} ({$ex->getMessage()})";
        }
    }

    $used = [];
    foreach ($parsed as &$p) {
        foreach ($p['images'] ?? [] as $img) {
            if (isset($imageSet[$img])) { $p['imgs'][] = $img; $used[$img] = true; }
            else $log[] = "Hiányzó kép: $img ({$p['file']})";
        }
    }
    unset($p);
    if (!$useManifest) {
        $keys = array_column($parsed, 'key');
        foreach ($imageFiles as $img) {
            $k = match_image($img, $keys);
            if ($k === null) continue;
            $i = array_search($k, $keys, true);
            $parsed[$i]['imgs'][] = $img;
            $used[$img] = true;
        }
    }

    $stats = ['uj' => 0, 'frissitett' => 0, 'szerkesztett' => 0, 'lathato' => 0, 'kep' => 0];
    tx(function () use ($parsed, &$stats) {
        foreach ($parsed as $p) {
            $existing = one('SELECT id, edited FROM writings WHERE source_file = ?', [$p['file']]);
            if (!$existing) {
                q('INSERT INTO writings (slug, title, body, published_at, source_file, category, hidden) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [unique_slug(slugify($p['title'])), $p['title'], $p['body'], $p['date'], $p['file'], $p['category'], $p['hidden']]);
                $id = (int)db()->lastInsertId();
                $stats['uj']++;
            } else {
                $id = (int)$existing['id'];
                if ($existing['edited']) { $stats['szerkesztett']++; continue; } // Judit kézzel szerkesztette: nem írjuk felül
                q('UPDATE writings SET title = ?, body = ?, published_at = ?, category = ?, hidden = ? WHERE id = ?',
                    [$p['title'], $p['body'], $p['date'], $p['category'], $p['hidden'], $id]);
                $stats['frissitett']++;
            }
            if (!$p['hidden']) $stats['lathato']++;
            q("DELETE FROM images WHERE writing_id = ? AND path LIKE 'kepek/%'", [$id]);
            foreach (array_values($p['imgs']) as $i => $img) {
                q('INSERT OR IGNORE INTO images (writing_id, path, position) VALUES (?, ?, ?)', [$id, "kepek/$img", $i]);
                $stats['kep']++;
            }
        }
    });

    array_unshift($log, sprintf('Kész: %d új, %d frissített írás%s; ebből %d látható; %d kép párosítva.',
        $stats['uj'], $stats['frissitett'],
        $stats['szerkesztett'] ? ", {$stats['szerkesztett']} kézzel szerkesztett (nem írtam felül)" : '',
        $stats['lathato'], $stats['kep']));
    $unmatched = array_values(array_filter($imageFiles, fn($f) => !isset($used[$f])));
    if ($unmatched) $log[] = count($unmatched) . ' kép nincs íráshoz rendelve – az írás szerkesztésénél a képtárból hozzáadhatók.';
    return ['log' => $log, 'stats' => $stats, 'unmatched' => $unmatched];
}
