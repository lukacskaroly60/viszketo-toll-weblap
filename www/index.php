<?php
// Viszkető Toll – a weboldal belépési pontja. Minden kérés ide fut be (.htaccess).
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('A weboldalhoz PHP 8.1 vagy újabb kell. A tárhely vezérlőpultjában (cPanel → PHP verzió) állítható.');
}
foreach (['pdo_sqlite', 'zip', 'dom', 'mbstring'] as $ext) {
    if (!extension_loaded($ext)) {
        http_response_code(500);
        exit("Hiányzik a(z) $ext PHP-bővítmény. A tárhely vezérlőpultjában (cPanel → PHP verzió → Bővítmények) bekapcsolható.");
    }
}

require __DIR__ . '/app/bootstrap.php';

start_session();
visitor_id();

/* ---------- útvonal ---------- */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);
$base = base_path();
if ($base !== '' && str_starts_with($path, $base)) $path = substr($path, strlen($base));
if (str_starts_with($path, '/index.php')) $path = substr($path, strlen('/index.php'));
$path = '/' . trim($path, '/');

if ($method === 'POST') check_post();

$routes = [
    ['GET', '#^/$#', 'home'],
    ['GET', '#^/iras/([a-z0-9-]+)$#', 'show_writing'],
    ['POST', '#^/iras/([a-z0-9-]+)/lajk$#', 'like'],
    ['POST', '#^/iras/([a-z0-9-]+)/hozzaszolas$#', 'comment'],
    ['GET', '#^/regisztracio$#', 'register_form'],
    ['POST', '#^/regisztracio$#', 'register'],
    ['GET', '#^/belepes$#', 'login_form'],
    ['POST', '#^/belepes$#', 'login'],
    ['POST', '#^/kilepes$#', 'logout'],
    ['GET', '#^/telepites$#', 'setup_form'],
    ['POST', '#^/telepites$#', 'setup'],
    ['GET', '#^/admin$#', 'admin_comments'],
    ['POST', '#^/admin/hozzaszolas/(\d+)/(jovahagy|elutasit|torol)$#', 'admin_comment_action'],
    ['GET', '#^/admin/irasok$#', 'admin_writings'],
    ['GET', '#^/admin/iras/uj$#', 'admin_new_form'],
    ['POST', '#^/admin/iras/uj$#', 'admin_new'],
    ['GET', '#^/admin/iras/(\d+)$#', 'admin_edit_form'],
    ['POST', '#^/admin/iras/(\d+)$#', 'admin_edit'],
    ['POST', '#^/admin/iras/(\d+)/lathatosag$#', 'admin_toggle'],
    ['POST', '#^/admin/iras/(\d+)/torol$#', 'admin_delete'],
    ['GET', '#^/admin/felhasznalok$#', 'admin_users'],
    ['POST', '#^/admin/felhasznalo/(\d+)/torol$#', 'admin_user_delete'],
    ['GET', '#^/admin/import$#', 'admin_import_form'],
    ['POST', '#^/admin/import$#', 'admin_import'],
];

try {
    foreach ($routes as [$m, $re, $handler]) {
        if ($m === $method && preg_match($re, $path, $args)) {
            array_shift($args);
            if (str_starts_with($handler, 'admin_')) require_admin();
            $handler(...$args);
            exit;
        }
    }
    page(message_view('Nem található', 'Ez az oldal nem létezik.'), 'Nem található', 404);
} catch (Throwable $e) {
    error_log('[viszketo-toll] ' . $e);
    page(message_view('Hoppá', 'Váratlan hiba történt. Kérlek, próbáld újra később.'), 'Hiba', 500);
}

/* ---------- lekérdezések ---------- */

function list_columns(): string
{
    return "w.id, w.slug, w.title, w.body, w.published_at, w.hidden, w.category, w.source_file,
      (SELECT COUNT(*) FROM likes l WHERE l.writing_id = w.id) AS like_count,
      (SELECT COUNT(*) FROM comments c WHERE c.writing_id = w.id AND c.status = 'approved') AS comment_count,
      (SELECT path FROM images i WHERE i.writing_id = w.id ORDER BY position, id LIMIT 1) AS image,
      EXISTS (SELECT 1 FROM likes l WHERE l.writing_id = w.id AND l.visitor_id = :vid) AS liked";
}

function find_writing(string $slug, bool $allowHidden = false): ?array
{
    $w = one('SELECT ' . list_columns() . ' FROM writings w WHERE w.slug = :slug', ['slug' => $slug, 'vid' => visitor_id()]);
    if (!$w || ($w['hidden'] && !($allowHidden && is_admin()))) return null;
    return $w;
}

function images_of(int $id): array
{
    return all('SELECT id, path FROM images WHERE writing_id = ? ORDER BY position, id', [$id]);
}

/* ---------- nyilvános oldalak ---------- */

function home(): void
{
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    $kat = isset(CATEGORIES[$_GET['kat'] ?? '']) ? $_GET['kat'] : '';
    $page = max(1, (int)($_GET['oldal'] ?? 1));
    $size = (int)cfg('oldalankent');

    $where = 'w.hidden = 0';
    $params = [];
    if ($q !== '') { $where .= ' AND (w.title LIKE :like OR w.body LIKE :like)'; $params['like'] = '%' . $q . '%'; }
    if ($kat !== '') { $where .= ' AND w.category = :kat'; $params['kat'] = $kat; }

    $total = (int)one("SELECT COUNT(*) AS n FROM writings w WHERE $where", $params)['n'];
    $pages = max(1, (int)ceil($total / $size));
    $offset = ($page - 1) * $size;
    $writings = all('SELECT ' . list_columns() . " FROM writings w WHERE $where
        ORDER BY w.published_at DESC, w.id DESC LIMIT $size OFFSET $offset", $params + ['vid' => visitor_id()]);
    page(home_view($writings, $page, $pages, $q, $kat, $total));
}

function show_writing(string $slug, ?string $commentError = null, string $draft = '', int $status = 200): void
{
    $w = find_writing($slug, true);
    if (!$w) page(message_view('Nem található', 'Ilyen írás nincs (vagy már nincs) az oldalon.'), 'Nem található', 404);
    $user = current_user();
    $comments = all("SELECT c.body, c.created_at, u.name FROM comments c JOIN users u ON u.id = c.user_id
        WHERE c.writing_id = ? AND c.status = 'approved' ORDER BY c.created_at", [$w['id']]);
    $mine = $user ? all("SELECT body FROM comments WHERE writing_id = ? AND user_id = ? AND status = 'pending' ORDER BY created_at", [$w['id'], $user['id']]) : [];
    $p = ['d' => $w['published_at'], 'id' => $w['id']];
    $prev = one('SELECT slug, title FROM writings WHERE hidden = 0 AND (published_at < :d OR (published_at = :d AND id < :id)) ORDER BY published_at DESC, id DESC LIMIT 1', $p);
    $next = one('SELECT slug, title FROM writings WHERE hidden = 0 AND (published_at > :d OR (published_at = :d AND id > :id)) ORDER BY published_at ASC, id ASC LIMIT 1', $p);
    page(writing_view($w, images_of((int)$w['id']), $comments, $mine, $prev, $next, $commentError, $draft), $w['title'], $status, excerpt($w['body'], 160));
}

function like(string $slug): void
{
    if (!rate_ok('like|' . client_ip(), 60, 60)) fail(429, 'Túl sok kattintás, kérlek, várj egy kicsit.');
    $w = one('SELECT id FROM writings WHERE slug = ? AND hidden = 0', [$slug]);
    if (!$w) fail(404, 'Nem található.');
    $vid = visitor_id();
    $liked = !one('SELECT 1 AS x FROM likes WHERE writing_id = ? AND visitor_id = ?', [$w['id'], $vid]);
    if ($liked) q('INSERT OR IGNORE INTO likes (writing_id, visitor_id) VALUES (?, ?)', [$w['id'], $vid]);
    else q('DELETE FROM likes WHERE writing_id = ? AND visitor_id = ?', [$w['id'], $vid]);
    $count = (int)one('SELECT COUNT(*) AS n FROM likes WHERE writing_id = ?', [$w['id']])['n'];
    if (wants_json()) json_out(['liked' => $liked, 'count' => $count]);
    redirect(url('/iras/' . $slug));
}

function comment(string $slug): void
{
    $user = current_user();
    if (!$user) redirect(url('/belepes') . '?vissza=' . rawurlencode(url('/iras/' . $slug) . '#hozzaszolasok'));
    $w = one('SELECT id FROM writings WHERE slug = ? AND hidden = 0', [$slug]);
    if (!$w) redirect(url('/'));
    $body = trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST['body'] ?? '')));
    $max = (int)cfg('hozzaszolas_max');
    if (mb_strlen($body) < 2 || mb_strlen($body) > $max) {
        show_writing($slug, "A hozzászólás 2 és $max karakter között lehet.", $body, 400);
    }
    if (!rate_ok('comment|' . $user['id'], 10, 600)) {
        show_writing($slug, 'Túl sok hozzászólás rövid idő alatt, kérlek, várj egy kicsit.', $body, 429);
    }
    $status = $user['role'] === 'admin' ? 'approved' : 'pending'; // Judit saját hozzászólásai azonnal megjelennek
    q('INSERT INTO comments (writing_id, user_id, body, status) VALUES (?, ?, ?, ?)', [$w['id'], $user['id'], $body, $status]);
    set_flash('ok', $status === 'approved' ? 'A hozzászólásod megjelent.' : 'Köszönöm a hozzászólást! Jóváhagyás után jelenik meg.');
    redirect(url('/iras/' . $slug) . '#hozzaszolasok');
}

/* ---------- regisztráció, belépés ---------- */

function register_form(): void
{
    if (current_user()) redirect(safe_back($_GET['vissza'] ?? null));
    page(auth_view('register', null, [], safe_back($_GET['vissza'] ?? null)), 'Regisztráció');
}

function register(): void
{
    $name = trim(preg_replace('/\s+/u', ' ', (string)($_POST['name'] ?? '')));
    $email = normalize_email($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $back = safe_back($_POST['vissza'] ?? null);
    $failWith = fn($err) => page(auth_view('register', $err, ['name' => $name, 'email' => $email], $back), 'Regisztráció', 400);

    if (!rate_ok('register|' . client_ip(), 10, 3600)) $failWith('Túl sok regisztráció innen, kérlek, próbáld később.');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 60) $failWith('Kérlek, add meg a neved (2–60 karakter).');
    if (!valid_email($email)) $failWith('Ez nem tűnik érvényes e-mail-címnek.');
    if (strlen($password) < 6) $failWith('A jelszó legalább 6 karakter legyen.');
    if (one('SELECT id FROM users WHERE email = ?', [$email])) $failWith('Ezzel az e-mail-címmel már regisztráltak. Próbálj belépni.');

    q('INSERT INTO users (email, name, password_hash) VALUES (?, ?, ?)', [$email, $name, password_hash($password, PASSWORD_DEFAULT)]);
    log_in((int)db()->lastInsertId());
    set_flash('ok', "Üdv, $name! Most már hozzászólhatsz az írásokhoz.");
    redirect($back);
}

function login_form(): void
{
    if (current_user()) redirect(safe_back($_GET['vissza'] ?? null));
    page(auth_view('login', null, [], safe_back($_GET['vissza'] ?? null)), 'Belépés');
}

function login(): void
{
    $email = normalize_email($_POST['email'] ?? '');
    $back = safe_back($_POST['vissza'] ?? null);
    if (!rate_ok('login|' . client_ip(), 20, 900)) {
        page(auth_view('login', 'Túl sok próbálkozás, kérlek, várj 15 percet.', ['email' => $email], $back), 'Belépés', 429);
    }
    $user = one('SELECT * FROM users WHERE email = ?', [$email]);
    if (!$user || !password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
        page(auth_view('login', 'Hibás e-mail-cím vagy jelszó.', ['email' => $email], $back), 'Belépés', 401);
    }
    log_in((int)$user['id']);
    redirect($back);
}

function logout(): void
{
    log_out();
    redirect(url('/'));
}

/* ---------- első beállítás: Judit szerkesztői fiókja ---------- */

function has_admin(): bool
{
    return (bool)one("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
}

function setup_form(): void
{
    if (has_admin()) redirect(url('/belepes'));
    page(setup_view(), 'Első beállítás');
}

function setup(): void
{
    if (has_admin()) redirect(url('/belepes'));
    $name = trim((string)($_POST['name'] ?? ''));
    $email = normalize_email($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $err = null;
    if (mb_strlen($name) < 2) $err = 'Add meg a nevet.';
    elseif (!valid_email($email)) $err = 'Érvénytelen e-mail-cím.';
    elseif (strlen($password) < 8) $err = 'A jelszó legalább 8 karakter legyen.';
    if ($err) page(setup_view($err, ['name' => $name, 'email' => $email]), 'Első beállítás', 400);

    $existing = one('SELECT id FROM users WHERE email = ?', [$email]);
    if ($existing) {
        q("UPDATE users SET role = 'admin', name = ?, password_hash = ? WHERE id = ?", [$name, password_hash($password, PASSWORD_DEFAULT), $existing['id']]);
        $id = (int)$existing['id'];
    } else {
        q("INSERT INTO users (email, name, password_hash, role) VALUES (?, ?, ?, 'admin')", [$email, $name, password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int)db()->lastInsertId();
    }
    log_in($id);
    $count = (int)one('SELECT COUNT(*) AS n FROM writings')['n'];
    if ($count === 0 && (is_dir(WRITINGS_DIR) || is_file(CONTENT_DIR . '/tartalom.json'))) {
        $r = import_content();
        set_flash('ok', 'A szerkesztői fiók elkészült. ' . $r['log'][0]);
    } else {
        set_flash('ok', 'A szerkesztői fiók elkészült.');
    }
    redirect(url('/admin/irasok'));
}

/* ---------- szerkesztői felület ---------- */

function admin_comments(): void
{
    $base = 'SELECT c.*, u.name, u.email, w.title, w.slug FROM comments c JOIN users u ON u.id = c.user_id JOIN writings w ON w.id = c.writing_id';
    $pending = all("$base WHERE c.status = 'pending' ORDER BY c.created_at");
    $recent = all("$base WHERE c.status != 'pending' ORDER BY c.reviewed_at DESC LIMIT 30");
    page(admin_comments_view($pending, $recent), 'Hozzászólások');
}

function admin_comment_action(string $id, string $action): void
{
    match ($action) {
        'jovahagy' => q("UPDATE comments SET status = 'approved', reviewed_at = datetime('now') WHERE id = ?", [(int)$id]),
        'elutasit' => q("UPDATE comments SET status = 'rejected', reviewed_at = datetime('now') WHERE id = ?", [(int)$id]),
        'torol' => q('DELETE FROM comments WHERE id = ?', [(int)$id]),
    };
    redirect(url('/admin'));
}

function admin_writings(): void
{
    $show = in_array($_GET['mutat'] ?? '', ['lathato', 'rejtett'], true) ? $_GET['mutat'] : '';
    $where = $show === 'lathato' ? 'WHERE w.hidden = 0' : ($show === 'rejtett' ? 'WHERE w.hidden = 1' : '');
    $writings = all('SELECT ' . list_columns() . ", (SELECT COUNT(*) FROM images i WHERE i.writing_id = w.id) AS image_count
        FROM writings w $where ORDER BY w.hidden, w.published_at DESC, w.id DESC", ['vid' => visitor_id()]);
    $counts = one('SELECT COUNT(*) AS osszes, COALESCE(SUM(hidden = 0), 0) AS lathato, COALESCE(SUM(hidden = 1), 0) AS rejtett FROM writings');
    page(admin_writings_view($writings, $show, $counts), 'Írások');
}

function writing_form_data(): array
{
    $date = (string)($_POST['published_at'] ?? '');
    return [
        'title' => trim((string)($_POST['title'] ?? '')),
        'body' => trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST['body'] ?? ''))),
        'published_at' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d'),
        'category' => isset(CATEGORIES[$_POST['category'] ?? '']) ? $_POST['category'] : 'proza',
        'hidden' => !empty($_POST['hidden']) ? 1 : 0,
    ];
}

/** A képtár képei az adott íráshoz (a már hozzárendeltek nélkül). */
function library_for(?int $writingId): array
{
    $here = $writingId ? array_column(images_of($writingId), 'path') : [];
    $elsewhere = [];
    foreach (all("SELECT i.path, w.title FROM images i JOIN writings w ON w.id = i.writing_id WHERE i.path LIKE 'kepek/%'") as $r) {
        $elsewhere[$r['path']] = $r['title'];
    }
    $out = [];
    foreach (library_images() as $name) {
        $p = "kepek/$name";
        if (in_array($p, $here, true)) continue;
        $out[] = ['name' => $name, 'path' => $p, 'usedBy' => $elsewhere[$p] ?? null];
    }
    // a még sehol nem használt képek előre
    usort($out, fn($a, $b) => ($a['usedBy'] !== null) <=> ($b['usedBy'] !== null));
    return $out;
}

function add_image(int $writingId, string $path): void
{
    q('INSERT OR IGNORE INTO images (writing_id, path, position)
       VALUES (?, ?, (SELECT COALESCE(MAX(position), -1) + 1 FROM images WHERE writing_id = ?))', [$writingId, $path, $writingId]);
}

/** Feltöltött képek mentése; hibánál üzenetet ad vissza. */
function save_uploads(int $writingId): ?string
{
    $files = $_FILES['images'] ?? null;
    if (!$files || !is_array($files['name'])) return null;
    $max = (int)cfg('kep_max_mb') * 1024 * 1024;
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $errors = [];
    foreach ($files['name'] as $i => $name) {
        if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        if ($files['error'][$i] !== UPLOAD_ERR_OK || $files['size'][$i] > $max) { $errors[] = "$name: túl nagy vagy hibás"; continue; }
        $info = @getimagesize($files['tmp_name'][$i]);
        $ext = $info ? ($types[$info[2]] ?? null) : null;
        if (!$ext) { $errors[] = "$name: nem kép (jpg, png, webp, gif lehet)"; continue; }
        $target = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . ".$ext";
        if (move_uploaded_file($files['tmp_name'][$i], UPLOAD_DIR . "/$target")) add_image($writingId, "feltoltesek/$target");
        else $errors[] = "$name: nem sikerült menteni";
    }
    return $errors ? 'Néhány kép nem került fel: ' . implode('; ', $errors) : null;
}

function save_library_picks(int $writingId): void
{
    $lib = array_flip(library_images());
    foreach ((array)($_POST['library'] ?? []) as $name) {
        if (is_string($name) && isset($lib[$name])) add_image($writingId, "kepek/$name");
    }
}

function remove_uploaded_file(string $p): void
{
    if (str_starts_with($p, 'feltoltesek/')) @unlink(UPLOAD_DIR . '/' . basename($p));
}

function admin_new_form(): void
{
    page(admin_edit_view(['category' => 'proza'], [], library_for(null)), 'Új írás');
}

function admin_new(): void
{
    $f = writing_form_data();
    if ($f['title'] === '' || $f['body'] === '') {
        page(admin_edit_view($f, [], library_for(null), 'A cím és a szöveg kötelező.'), 'Új írás', 400);
    }
    $slug = unique_slug(slugify($f['title']));
    $id = tx(function () use ($f, $slug) {
        q('INSERT INTO writings (slug, title, body, published_at, category, hidden, edited) VALUES (?, ?, ?, ?, ?, ?, 1)',
            [$slug, $f['title'], $f['body'], $f['published_at'], $f['category'], $f['hidden']]);
        $id = (int)db()->lastInsertId();
        save_library_picks($id);
        return $id;
    });
    $err = save_uploads($id);
    set_flash($err ? 'err' : 'ok', $err ?: ($f['hidden'] ? 'Elmentve piszkozatként.' : 'Az írás megjelent.'));
    redirect(url('/iras/' . $slug));
}

function admin_edit_form(string $id): void
{
    $w = one('SELECT * FROM writings WHERE id = ?', [(int)$id]);
    if (!$w) redirect(url('/admin/irasok'));
    page(admin_edit_view($w, images_of((int)$w['id']), library_for((int)$w['id'])), $w['title']);
}

function admin_edit(string $id): void
{
    $w = one('SELECT * FROM writings WHERE id = ?', [(int)$id]);
    if (!$w) redirect(url('/admin/irasok'));
    $f = writing_form_data();
    if ($f['title'] === '' || $f['body'] === '') {
        page(admin_edit_view($f + $w, images_of((int)$w['id']), library_for((int)$w['id']), 'A cím és a szöveg kötelező.'), $w['title'], 400);
    }
    $removeIds = array_map('intval', (array)($_POST['remove_image'] ?? []));
    tx(function () use ($w, $f, $removeIds) {
        q('UPDATE writings SET title = ?, body = ?, published_at = ?, category = ?, hidden = ?, edited = 1 WHERE id = ?',
            [$f['title'], $f['body'], $f['published_at'], $f['category'], $f['hidden'], $w['id']]);
        foreach ($removeIds as $imgId) {
            $img = one('SELECT path FROM images WHERE id = ? AND writing_id = ?', [$imgId, $w['id']]);
            if (!$img) continue;
            q('DELETE FROM images WHERE id = ?', [$imgId]);
            remove_uploaded_file($img['path']); // a képtárból (tartalom/kepek) nem törlünk fájlt
        }
        save_library_picks((int)$w['id']);
    });
    $err = save_uploads((int)$w['id']);
    set_flash($err ? 'err' : 'ok', $err ?: 'Mentve.');
    redirect(url('/admin/iras/' . $w['id']));
}

function admin_toggle(string $id): void
{
    q('UPDATE writings SET hidden = 1 - hidden, edited = 1 WHERE id = ?', [(int)$id]);
    redirect(safe_back($_POST['vissza'] ?? url('/admin/irasok')));
}

function admin_delete(string $id): void
{
    $w = one('SELECT * FROM writings WHERE id = ?', [(int)$id]);
    if ($w) {
        foreach (images_of((int)$w['id']) as $i) remove_uploaded_file($i['path']);
        q('DELETE FROM writings WHERE id = ?', [$w['id']]);
        set_flash('ok', "„{$w['title']}” törölve.");
    }
    redirect(url('/admin/irasok'));
}

function admin_users(): void
{
    $users = all('SELECT u.*, (SELECT COUNT(*) FROM comments c WHERE c.user_id = u.id) AS comment_count FROM users u ORDER BY u.created_at DESC');
    page(admin_users_view($users), 'Olvasók');
}

function admin_user_delete(string $id): void
{
    q("DELETE FROM users WHERE id = ? AND role != 'admin'", [(int)$id]);
    redirect(url('/admin/felhasznalok'));
}

function admin_import_form(): void
{
    page(admin_import_view(null), 'Importálás');
}

function admin_import(): void
{
    try {
        $r = import_content();
    } catch (Throwable $e) {
        $r = ['log' => ['Hiba: ' . $e->getMessage()]];
    }
    page(admin_import_view($r), 'Importálás');
}
