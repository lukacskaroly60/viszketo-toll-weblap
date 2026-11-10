<?php
// HTML-sablonok. Minden kiírt adat esc()-pel escapelve.

const QUILL = '<svg class="quill" viewBox="0 0 32 32" aria-hidden="true"><path d="M27 3c-7 1-13 6-16 13l-3 8 2 1 2-4c6 0 11-4 13-10-2 1-4 1-6 1 3-1 6-4 8-9Z" fill="currentColor"/><path d="M5 29c2-4 4-8 7-12" stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="round"/></svg>';
const HEART = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7.5-4.6-10-9.3C.4 8.5 2.3 4.5 6.2 4.5c2.2 0 3.6 1.2 4.4 2.5h.8c.8-1.3 2.2-2.5 4.4-2.5 3.9 0 5.8 4 4.2 7.2C19.5 16.4 12 21 12 21Z"/></svg>';

function page(string $body, ?string $title = null, int $status = 200, ?string $description = null): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $site = cfg('oldal_neve');
    $author = cfg('szerzo');
    $user = current_user();
    $flash = take_flash();
    $pending = is_admin() ? (int)one("SELECT COUNT(*) AS n FROM comments WHERE status = 'pending'")['n'] : 0;
    $pageTitle = $title ? "$title – $site" : "$site – $author írásai";
    $desc = $description ?: "$author novellái, versei, tárcái és gondolatai.";

    $nav = $user
        ? (is_admin() ? '<a href="' . esc(url('/admin')) . '">Szerkesztés' . ($pending ? ' <span class="badge" title="Jóváhagyásra váró hozzászólások">' . $pending . '</span>' : '') . '</a>' : '')
          . '<span class="nav-user">' . esc($user['name']) . '</span>'
          . '<form method="post" action="' . esc(url('/kilepes')) . '" class="inline">' . csrf_field() . '<button class="linkish">Kilépés</button></form>'
        : '<a href="' . esc(url('/belepes')) . '">Belépés</a><a href="' . esc(url('/regisztracio')) . '" class="btn btn-small">Regisztráció</a>';

    $flashHtml = $flash ? '<div class="flash flash-' . esc($flash['type']) . '" role="status">' . esc($flash['text']) . '</div>' : '';
    $year = date('Y');
    $css = esc(asset('static/style.css')) . '?v=' . @filemtime(ROOT . '/static/style.css');
    $js = esc(asset('static/app.js')) . '?v=' . @filemtime(ROOT . '/static/app.js');
    $fav = esc(asset('static/favicon.svg'));
    $home = esc(url('/'));
    $csrf = esc(csrf_token());
    $tTitle = esc($pageTitle); $tDesc = esc($desc); $tSite = esc($site); $tAuthor = esc($author); $quill = QUILL;

    echo <<<HTML
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="$csrf">
<title>$tTitle</title>
<meta name="description" content="$tDesc">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,600;1,400&family=Playfair+Display:ital,wght@0,600;1,500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="$css">
<link rel="icon" href="$fav" type="image/svg+xml">
</head>
<body>
<header class="site-header">
  <div class="wrap header-inner">
    <a class="brand" href="$home">$quill<span><span class="brand-name">$tSite</span><span class="brand-sub">$tAuthor írásai</span></span></a>
    <nav class="nav">$nav</nav>
  </div>
</header>
<main class="wrap">
$flashHtml
$body
</main>
<footer class="site-footer">
  <div class="wrap">© $year $tAuthor · Az írások szerzői jogvédelem alatt állnak.</div>
</footer>
<script src="$js" defer></script>
</body>
</html>
HTML;
    exit;
}

function fail(int $status, string $text): never
{
    if (wants_json()) json_out(['error' => $text], $status);
    page(message_view('Hoppá', $text), 'Hiba', $status);
}

function message_view(string $title, string $text): string
{
    return '<section class="auth"><h1>' . esc($title) . '</h1><p>' . esc($text) . '</p><p><a href="' . esc(url('/')) . '">Vissza a főoldalra</a></p></section>';
}

function like_button(array $w): string
{
    $liked = !empty($w['liked']);
    return '<button class="like' . ($liked ? ' liked' : '') . '" data-like="' . esc(url('/iras/' . $w['slug'] . '/lajk')) . '" data-id="' . (int)$w['id'] . '" aria-pressed="' . ($liked ? 'true' : 'false') . '" title="Tetszik">'
        . HEART . '<span class="like-count">' . (int)$w['like_count'] . '</span><span class="sr-only"> kedvelés</span></button>';
}

function cat_label(array $w): string
{
    return '<a class="cat" href="' . esc(url('/') . '?kat=' . $w['category']) . '">' . esc(CATEGORIES[$w['category']] ?? '') . '</a>';
}

function home_view(array $writings, int $page, int $pages, string $q, string $kat, int $total): string
{
    $author = esc(cfg('szerzo'));
    $cards = '';
    foreach ($writings as $w) {
        $href = esc(url('/iras/' . $w['slug']));
        $img = $w['image']
            ? '<a href="' . $href . '" class="card-img" tabindex="-1"><img src="' . esc(img_url($w['image'])) . '" alt="" loading="lazy"></a>'
            : '<a href="' . $href . '" class="card-img card-img-empty" tabindex="-1">' . QUILL . '</a>';
        $cards .= '<article class="card">' . $img . '<div class="card-body">'
            . '<div class="card-kicker">' . cat_label($w) . ' · <time datetime="' . esc($w['published_at']) . '">' . hu_date($w['published_at']) . '</time></div>'
            . '<h2><a href="' . $href . '">' . esc($w['title']) . '</a></h2>'
            . '<p>' . esc(excerpt($w['body'])) . '</p>'
            . '<div class="card-meta">' . like_button($w)
            . '<a class="comment-count" href="' . $href . '#hozzaszolasok">' . (int)$w['comment_count'] . ' hozzászólás</a></div>'
            . '</div></article>';
    }

    $tabs = '<a href="' . esc(url('/') . ($q !== '' ? '?q=' . rawurlencode($q) : '')) . '" class="' . ($kat === '' ? 'active' : '') . '">Összes</a>';
    foreach (CATEGORIES as $k => $label) {
        $tabs .= '<a href="' . esc(url('/') . '?' . http_build_query(['kat' => $k] + ($q !== '' ? ['q' => $q] : []))) . '" class="' . ($kat === $k ? 'active' : '') . '">' . esc($label) . '</a>';
    }

    $pager = '';
    if ($pages > 1) {
        $href = fn($p) => esc(url('/') . '?' . http_build_query(($kat ? ['kat' => $kat] : []) + ($q !== '' ? ['q' => $q] : []) + ($p > 1 ? ['oldal' => $p] : [])));
        $pager = '<nav class="pager" aria-label="Lapozás">'
            . ($page > 1 ? '<a href="' . $href($page - 1) . '">← Újabbak</a>' : '<span></span>')
            . "<span class=\"pager-info\">$page. oldal / $pages</span>"
            . ($page < $pages ? '<a href="' . $href($page + 1) . '">Régebbiek →</a>' : '<span></span>')
            . '</nav>';
    }

    $search = '<form class="search" method="get" action="' . esc(url('/')) . '" role="search">'
        . ($kat ? '<input type="hidden" name="kat" value="' . esc($kat) . '">' : '')
        . '<input type="search" name="q" value="' . esc($q) . '" placeholder="Keresés az írásokban…" aria-label="Keresés">'
        . '<button class="btn">Keresés</button></form>';
    $portrait = is_file(CONTENT_DIR . '/szerzo.jpg') ? 'tartalom/szerzo.jpg' : null;
    $hero = $portrait
        ? '<section class="hero hero-author"><figure class="portrait"><img src="' . esc(asset($portrait)) . '?v=' . @filemtime(CONTENT_DIR . '/szerzo.jpg') . '" alt="' . $author . '">'
          . '<figcaption>' . $author . '</figcaption></figure>'
          . '<div><h1>' . esc(cfg('oldal_neve')) . '</h1>'
          . "<p>$author történetei, versei és gondolatai.</p>" . $search . '</div></section>'
        : '<section class="hero"><h1>' . esc(cfg('oldal_neve')) . '</h1>'
          . "<p>$author történetei, versei és gondolatai.</p>" . $search . '</section>';

    return $hero
        . '<nav class="tabs" aria-label="Kategóriák">' . $tabs . '</nav>'
        . ($q !== '' ? '<p class="result-info">' . $total . ' találat erre: „' . esc($q) . '” · <a href="' . esc(url('/')) . '">összes írás</a></p>' : '')
        . ($writings ? '<div class="grid">' . $cards . '</div>' : '<p class="empty">' . ($q !== '' ? 'Nincs találat.' : 'Még nincsenek írások.') . '</p>')
        . $pager;
}

function writing_view(array $w, array $images, array $comments, array $myPending, ?array $prev, ?array $next, ?string $commentError = null, string $draft = ''): string
{
    $user = current_user();
    $hero = array_shift($images);
    $alt = 'Illusztráció: ' . $w['title'];
    $max = (int)cfg('hozzaszolas_max');
    $back = url('/iras/' . $w['slug']) . '#hozzaszolasok';

    $h = '<article class="writing"><header class="writing-head">'
        . '<div class="card-kicker">' . cat_label($w) . ' · <time datetime="' . esc($w['published_at']) . '">' . hu_date($w['published_at']) . '</time></div>'
        . '<h1>' . esc($w['title']) . '</h1>'
        . ($w['hidden'] ? '<p><span class="pill">Rejtett – csak te látod</span></p>' : '')
        . '</header>';
    if ($hero) $h .= '<figure class="writing-hero"><img src="' . esc(img_url($hero['path'])) . '" alt="' . esc($alt) . '"></figure>';
    $h .= '<div class="writing-body' . ($w['category'] === 'vers' ? ' poem' : '') . '">' . render_body($w['body']) . '</div>';
    if ($images) {
        $h .= '<div class="gallery">';
        foreach ($images as $i) $h .= '<figure><img src="' . esc(img_url($i['path'])) . '" alt="' . esc($alt) . '" loading="lazy"></figure>';
        $h .= '</div>';
    }
    $h .= '<div class="writing-actions">' . like_button($w)
        . '<span class="muted">Ha tetszett, jelezd egy szívvel – ehhez nem kell bejelentkezni.</span></div>';
    if (is_admin()) $h .= '<p class="admin-link"><a href="' . esc(url('/admin/iras/' . $w['id'])) . '">✎ Írás szerkesztése</a></p>';
    $h .= '</article>';

    $h .= '<nav class="prevnext">'
        . ($next ? '<a href="' . esc(url('/iras/' . $next['slug'])) . '"><small>← Újabb írás</small>' . esc($next['title']) . '</a>' : '<span></span>')
        . ($prev ? '<a href="' . esc(url('/iras/' . $prev['slug'])) . '" class="right"><small>Régebbi írás →</small>' . esc($prev['title']) . '</a>' : '<span></span>')
        . '</nav>';

    $h .= '<section class="comments" id="hozzaszolasok"><h2>Hozzászólások <span class="muted">(' . count($comments) . ')</span></h2>';
    if (!$comments) $h .= '<p class="muted">Még nincs hozzászólás. Légy te az első!</p>';
    foreach ($comments as $c) {
        $h .= '<div class="comment"><div class="comment-head"><strong>' . esc($c['name']) . '</strong> <time>' . hu_datetime($c['created_at']) . '</time></div>'
            . '<p>' . nl2br(esc($c['body'])) . '</p></div>';
    }
    foreach ($myPending as $c) {
        $h .= '<div class="comment comment-pending"><div class="comment-head"><strong>' . esc($user['name']) . '</strong> <span class="pill">Jóváhagyásra vár</span></div>'
            . '<p>' . nl2br(esc($c['body'])) . '</p></div>';
    }
    if ($user) {
        $h .= '<form method="post" action="' . esc(url('/iras/' . $w['slug'] . '/hozzaszolas')) . '" class="comment-form">' . csrf_field()
            . '<label for="comment">Írd meg a véleményed</label>'
            . ($commentError ? '<p class="error">' . esc($commentError) . '</p>' : '')
            . '<textarea id="comment" name="body" rows="4" maxlength="' . $max . '" required data-counter>' . esc($draft) . '</textarea>'
            . '<div class="form-row"><small class="muted"><span data-count>0</span>/' . $max . ' karakter · A hozzászólás Judit jóváhagyása után jelenik meg.</small>'
            . '<button class="btn">Elküldöm</button></div></form>';
    } else {
        $h .= '<p class="login-hint">Hozzászólás írásához <a href="' . esc(url('/belepes') . '?vissza=' . rawurlencode($back)) . '">jelentkezz be</a>, vagy <a href="'
            . esc(url('/regisztracio') . '?vissza=' . rawurlencode($back)) . '">regisztrálj</a> – csak a neved és az e-mail-címed kell hozzá.</p>';
    }
    return $h . '</section>';
}

function auth_view(string $mode, ?string $error = null, array $values = [], string $back = '/'): string
{
    $reg = $mode === 'register';
    $other = $reg
        ? 'Van már fiókod? <a href="' . esc(url('/belepes') . '?vissza=' . rawurlencode($back)) . '">Belépés</a>'
        : 'Még nincs fiókod? <a href="' . esc(url('/regisztracio') . '?vissza=' . rawurlencode($back)) . '">Regisztráció</a>';
    return '<section class="auth"><h1>' . ($reg ? 'Regisztráció' : 'Belépés') . '</h1>'
        . ($reg ? '<p class="muted">Regisztráció után hozzászólhatsz az írásokhoz. Csak a neved és az e-mail-címed kell.</p>' : '')
        . ($error ? '<p class="error">' . esc($error) . '</p>' : '')
        . '<form method="post" action="' . esc(url($reg ? '/regisztracio' : '/belepes')) . '" class="stack">' . csrf_field()
        . '<input type="hidden" name="vissza" value="' . esc($back) . '">'
        . ($reg ? '<label>Név <input name="name" required maxlength="60" value="' . esc($values['name'] ?? '') . '" autocomplete="name"></label>' : '')
        . '<label>E-mail-cím <input type="email" name="email" required maxlength="200" value="' . esc($values['email'] ?? '') . '" autocomplete="email"></label>'
        . '<label>Jelszó <input type="password" name="password" required minlength="' . ($reg ? 6 : 1) . '" autocomplete="' . ($reg ? 'new-password' : 'current-password') . '"></label>'
        . ($reg ? '<small class="muted">Legalább 6 karakter.</small>' : '')
        . '<button class="btn">' . ($reg ? 'Regisztrálok' : 'Belépek') . '</button></form>'
        . '<p class="muted">' . $other . '</p></section>';
}

function setup_view(?string $error = null, array $values = []): string
{
    return '<section class="auth"><h1>Első beállítás</h1>'
        . '<p>Hozd létre Judit szerkesztői fiókját. Ezzel lehet majd jóváhagyni a hozzászólásokat és szerkeszteni az írásokat. Ez az oldal csak addig érhető el, amíg nincs szerkesztő.</p>'
        . ($error ? '<p class="error">' . esc($error) . '</p>' : '')
        . '<form method="post" action="' . esc(url('/telepites')) . '" class="stack">' . csrf_field()
        . '<label>Név <input name="name" required maxlength="60" value="' . esc($values['name'] ?? cfg('szerzo')) . '"></label>'
        . '<label>E-mail-cím <input type="email" name="email" required value="' . esc($values['email'] ?? '') . '"></label>'
        . '<label>Jelszó <input type="password" name="password" required minlength="8" autocomplete="new-password"></label>'
        . '<small class="muted">Legalább 8 karakter.</small>'
        . '<button class="btn">Létrehozom</button></form></section>';
}

/* ---------------- szerkesztői felület ---------------- */

function admin_nav(string $active): string
{
    $items = ['comments' => ['/admin', 'Hozzászólások'], 'writings' => ['/admin/irasok', 'Írások'],
        'new' => ['/admin/iras/uj', '+ Új írás'], 'users' => ['/admin/felhasznalok', 'Olvasók'], 'import' => ['/admin/import', 'Importálás']];
    $h = '<h1>Szerkesztés</h1><nav class="admin-nav">';
    foreach ($items as $k => [$href, $label]) $h .= '<a href="' . esc(url($href)) . '" class="' . ($active === $k ? 'active' : '') . '">' . $label . '</a>';
    return $h . '</nav>';
}

function post_button(string $action, string $label, string $cls = '', string $confirm = ''): string
{
    return '<form method="post" action="' . esc(url($action)) . '" class="inline"' . ($confirm ? ' data-confirm="' . esc($confirm) . '"' : '') . '>'
        . csrf_field() . '<button class="btn btn-small ' . $cls . '">' . $label . '</button></form>';
}

function admin_comments_view(array $pending, array $recent): string
{
    $row = function ($c, $actions) {
        return '<div class="mod-item"><div class="mod-meta"><strong>' . esc($c['name']) . '</strong> <span class="muted">&lt;' . esc($c['email']) . '&gt;</span> · '
            . '<a href="' . esc(url('/iras/' . $c['slug'])) . '#hozzaszolasok">' . esc($c['title']) . '</a> · <time>' . hu_datetime($c['created_at']) . '</time></div>'
            . '<p>' . nl2br(esc($c['body'])) . '</p><div class="mod-actions">' . $actions . '</div></div>';
    };
    $h = admin_nav('comments') . '<h2>Jóváhagyásra váró hozzászólások (' . count($pending) . ')</h2>';
    if (!$pending) $h .= '<p class="muted">Nincs új hozzászólás. ☕</p>';
    foreach ($pending as $c) {
        $h .= $row($c, post_button("/admin/hozzaszolas/{$c['id']}/jovahagy", 'Megjelenhet') . post_button("/admin/hozzaszolas/{$c['id']}/elutasit", 'Elutasítom', 'btn-ghost'));
    }
    $h .= '<h2>Korábbi döntések</h2>';
    if (!$recent) $h .= '<p class="muted">Még nincs.</p>';
    foreach ($recent as $c) {
        $ok = $c['status'] === 'approved';
        $h .= $row($c, '<span class="pill ' . ($ok ? 'pill-ok' : 'pill-no') . '">' . ($ok ? 'Megjelent' : 'Elutasítva') . '</span>'
            . ($ok ? post_button("/admin/hozzaszolas/{$c['id']}/elutasit", 'Visszavonom', 'btn-ghost') : post_button("/admin/hozzaszolas/{$c['id']}/jovahagy", 'Mégis megjelenhet', 'btn-ghost'))
            . post_button("/admin/hozzaszolas/{$c['id']}/torol", 'Törlés', 'btn-danger', 'Végleg törlöd ezt a hozzászólást?'));
    }
    return $h;
}

function admin_writings_view(array $writings, string $show, array $counts): string
{
    $f = fn($key, $label) => '<a href="' . esc(url('/admin/irasok') . ($key ? "?mutat=$key" : '')) . '" class="' . ($show === $key ? 'active' : '') . '">' . $label . '</a>';
    $back = url('/admin/irasok') . ($show ? "?mutat=$show" : '');
    $h = admin_nav('writings')
        . '<p class="muted">A rejtett írások piszkozatok: az olvasók nem látják őket. A „Közzéteszem” gombbal egy kattintással megjelentetheted bármelyiket.</p>'
        . '<nav class="tabs tabs-small">' . $f('', "Mind ({$counts['osszes']})") . $f('lathato', "Láthatók ({$counts['lathato']})") . $f('rejtett', "Rejtettek ({$counts['rejtett']})") . '</nav>'
        . '<table class="table"><thead><tr><th>Cím</th><th>Fajta</th><th>Dátum</th><th>Kép</th><th>♥</th><th>Hozzá&shy;szólás</th><th></th></tr></thead><tbody>';
    foreach ($writings as $w) {
        $h .= '<tr class="' . ($w['hidden'] ? 'is-hidden' : '') . '"><td><a href="' . esc(url('/iras/' . $w['slug'])) . '">' . esc($w['title']) . '</a>'
            . ($w['hidden'] ? ' <span class="pill">rejtett</span>' : '')
            . ($w['source_file'] ? '<br><small class="muted">' . esc($w['source_file']) . '</small>' : '') . '</td>'
            . '<td>' . esc(CATEGORIES[$w['category']] ?? '') . '</td>'
            . '<td class="nowrap">' . esc($w['published_at']) . '</td>'
            . '<td>' . ($w['image_count'] ?: '<span class="warn">nincs</span>') . '</td>'
            . '<td>' . (int)$w['like_count'] . '</td><td>' . (int)$w['comment_count'] . '</td>'
            . '<td class="nowrap"><a href="' . esc(url('/admin/iras/' . $w['id'])) . '">szerkesztés</a><br>'
            . '<form method="post" action="' . esc(url("/admin/iras/{$w['id']}/lathatosag")) . '" class="inline">' . csrf_field()
            . '<input type="hidden" name="vissza" value="' . esc($back) . '"><button class="linkish">' . ($w['hidden'] ? 'Közzéteszem' : 'Elrejtem') . '</button></form></td></tr>';
    }
    return $h . '</tbody></table>';
}

function admin_edit_view(array $w, array $images, array $library, ?string $error = null): string
{
    $isNew = empty($w['id']);
    $action = $isNew ? '/admin/iras/uj' : "/admin/iras/{$w['id']}";
    $cat = $w['category'] ?? 'proza';
    $opts = '';
    foreach (CATEGORIES as $k => $l) $opts .= '<option value="' . $k . '"' . ($cat === $k ? ' selected' : '') . '>' . $l . '</option>';

    $h = admin_nav($isNew ? 'new' : 'writings') . '<h2>' . ($isNew ? 'Új írás' : esc($w['title'])) . '</h2>'
        . ($error ? '<p class="error">' . esc($error) . '</p>' : '')
        . '<form method="post" action="' . esc(url($action)) . '" enctype="multipart/form-data" class="stack edit-form">' . csrf_field()
        . '<label>Cím <input name="title" required maxlength="200" value="' . esc($w['title'] ?? '') . '"></label>'
        . '<div class="form-grid"><label>Dátum <input type="date" name="published_at" required value="' . esc($w['published_at'] ?? date('Y-m-d')) . '"></label>'
        . '<label>Fajta <select name="category">' . $opts . '</select></label></div>'
        . '<label>Szöveg <textarea name="body" rows="22" required>' . esc($w['body'] ?? '') . '</textarea></label>'
        . '<small class="muted">Üres sor = új bekezdés (versnél: új versszak). **félkövér**, *dőlt*.</small>';
    if ($images) {
        $h .= '<fieldset><legend>Képek</legend><div class="admin-images">';
        foreach ($images as $i) {
            $h .= '<label class="admin-image"><img src="' . esc(img_url($i['path'])) . '" alt=""><span><input type="checkbox" name="remove_image[]" value="' . (int)$i['id'] . '"> levétel</span></label>';
        }
        $h .= '</div></fieldset>';
    }
    if ($library) {
        $h .= '<details class="library"><summary>Kép a képtárból (' . count($library) . ')</summary>'
            . '<p class="muted">Jelöld be, amelyik képet ehhez az íráshoz szeretnéd tenni. Ha egy kép már máshol is szerepel, az alatta lévő felirat mutatja, hol.</p><div class="admin-images">';
        foreach ($library as $i) {
            $h .= '<label class="admin-image"><img src="' . esc(img_url($i['path'])) . '" alt="" loading="lazy"><span><input type="checkbox" name="library[]" value="' . esc($i['name']) . '"> ' . esc($i['name']) . '</span>'
                . ($i['usedBy'] ? '<small class="muted">már itt: ' . esc($i['usedBy']) . '</small>' : '') . '</label>';
        }
        $h .= '</div></details>';
    }
    $h .= '<label>Új kép feltöltése a gépről <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple></label>'
        . '<label class="check"><input type="checkbox" name="hidden" value="1"' . (!empty($w['hidden']) ? ' checked' : '') . '> Rejtett (piszkozat – az olvasók nem látják)</label>'
        . '<div class="form-row"><button class="btn">Mentés</button>'
        . ($isNew ? '' : '<a href="' . esc(url('/iras/' . $w['slug'])) . '">Megtekintés</a>') . '</div></form>';
    if (!$isNew) {
        $h .= '<div class="danger-zone">' . post_button("/admin/iras/{$w['id']}/torol", 'Írás végleges törlése', 'btn-danger', 'Biztosan törlöd ezt az írást a lájkokkal és hozzászólásokkal együtt?') . '</div>';
    }
    return $h;
}

function admin_users_view(array $users): string
{
    $h = admin_nav('users') . '<table class="table"><thead><tr><th>Név</th><th>E-mail</th><th>Regisztrált</th><th>Hozzászólás</th><th></th></tr></thead><tbody>';
    foreach ($users as $u) {
        $h .= '<tr><td>' . esc($u['name']) . ($u['role'] === 'admin' ? ' <span class="pill">szerkesztő</span>' : '') . '</td>'
            . '<td>' . esc($u['email']) . '</td><td>' . hu_datetime($u['created_at']) . '</td><td>' . (int)$u['comment_count'] . '</td><td>'
            . ($u['role'] === 'admin' ? '' : post_button("/admin/felhasznalo/{$u['id']}/torol", 'törlés', 'btn-ghost', "Törlöd {$u['name']} fiókját és minden hozzászólását?"))
            . '</td></tr>';
    }
    return $h . '</tbody></table>';
}

function admin_import_view(?array $result): string
{
    $h = admin_nav('import')
        . '<p>Az importálás beolvassa a <code>tartalom/</code> mappát (írások, képek, <code>tartalom.json</code>), és felveszi az új írásokat. '
        . 'Amit itt a szerkesztőfelületen már átírtál, azt nem írja felül.</p>'
        . '<p class="muted">Új írást a legegyszerűbb a „+ Új írás” menüben felvenni. Importálni akkor kell, ha FTP-vel új fájlokat töltöttél fel a <code>tartalom</code> mappába.</p>'
        . '<form method="post" action="' . esc(url('/admin/import')) . '">' . csrf_field() . '<button class="btn">Importálás indítása</button></form>';
    if ($result) {
        $h .= '<h2>Eredmény</h2><ul class="import-log">';
        foreach ($result['log'] as $line) $h .= '<li>' . esc($line) . '</li>';
        $h .= '</ul>';
    }
    return $h;
}
