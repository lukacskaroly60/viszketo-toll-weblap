// HTML-sablonok (sima JavaScript template literalok, minden adat escapelve).
import { esc, renderBody, excerpt, huDate, huDateTime } from './text.js';
import { SITE_NAME, AUTHOR, COMMENT_MAX } from './config.js';
import { CATEGORIES } from './db.js';

const imgUrl = (p) => '/' + p.split('/').map(encodeURIComponent).join('/');

const QUILL = `<svg class="quill" viewBox="0 0 32 32" aria-hidden="true"><path d="M27 3c-7 1-13 6-16 13l-3 8 2 1 2-4c6 0 11-4 13-10-2 1-4 1-6 1 3-1 6-4 8-9Z" fill="currentColor"/><path d="M5 29c2-4 4-8 7-12" stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="round"/></svg>`;

const HEART = `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7.5-4.6-10-9.3C.4 8.5 2.3 4.5 6.2 4.5c2.2 0 3.6 1.2 4.4 2.5h.8c.8-1.3 2.2-2.5 4.4-2.5 3.9 0 5.8 4 4.2 7.2C19.5 16.4 12 21 12 21Z"/></svg>`;

export function layout({ title, user, pendingCount = 0, body, flash, description }) {
  const pageTitle = title ? `${title} – ${SITE_NAME}` : `${SITE_NAME} – ${AUTHOR} írásai`;
  return `<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(pageTitle)}</title>
<meta name="description" content="${esc(description || `${AUTHOR} novellái, tárcái és gondolatai.`)}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,600;1,400&family=Playfair+Display:ital,wght@0,600;1,500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/static/style.css">
<link rel="icon" href="/static/favicon.svg" type="image/svg+xml">
</head>
<body>
<header class="site-header">
  <div class="wrap header-inner">
    <a class="brand" href="/">${QUILL}<span><span class="brand-name">${esc(SITE_NAME)}</span><span class="brand-sub">${esc(AUTHOR)} írásai</span></span></a>
    <nav class="nav">
      ${user ? `
        ${user.role === 'admin' ? `<a href="/admin">Szerkesztés${pendingCount ? ` <span class="badge" title="Jóváhagyásra váró hozzászólások">${pendingCount}</span>` : ''}</a>` : ''}
        <span class="nav-user">${esc(user.name)}</span>
        <form method="post" action="/kilepes" class="inline"><button class="linkish">Kilépés</button></form>
      ` : `
        <a href="/belepes">Belépés</a>
        <a href="/regisztracio" class="btn btn-small">Regisztráció</a>
      `}
    </nav>
  </div>
</header>
<main class="wrap">
${flash ? `<div class="flash flash-${esc(flash.type)}" role="status">${esc(flash.text)}</div>` : ''}
${body}
</main>
<footer class="site-footer">
  <div class="wrap">© ${new Date().getFullYear()} ${esc(AUTHOR)} · Az írások szerzői jogvédelem alatt állnak.</div>
</footer>
<script src="/static/app.js" defer></script>
</body>
</html>`;
}

function likeButton(w) {
  return `<button class="like ${w.liked ? 'liked' : ''}" data-like="${esc(w.slug)}" aria-pressed="${w.liked ? 'true' : 'false'}" title="Tetszik">
    ${HEART}<span class="like-count">${w.like_count}</span><span class="sr-only"> kedvelés</span>
  </button>`;
}

function pagination(page, pages, q, kat) {
  if (pages <= 1) return '';
  const href = (p) => `/?${new URLSearchParams({ ...(kat ? { kat } : {}), ...(q ? { q } : {}), ...(p > 1 ? { oldal: p } : {}) })}`;
  return `<nav class="pager" aria-label="Lapozás">
    ${page > 1 ? `<a href="${href(page - 1)}">← Újabbak</a>` : '<span></span>'}
    <span class="pager-info">${page}. oldal / ${pages}</span>
    ${page < pages ? `<a href="${href(page + 1)}">Régebbiek →</a>` : '<span></span>'}
  </nav>`;
}

export function homePage({ writings, page, pages, q, kat, total }) {
  const cards = writings.map((w) => `
    <article class="card">
      ${w.image ? `<a href="/iras/${esc(w.slug)}" class="card-img" tabindex="-1"><img src="${imgUrl(w.image)}" alt="" loading="lazy"></a>` : `<a href="/iras/${esc(w.slug)}" class="card-img card-img-empty" tabindex="-1">${QUILL}</a>`}
      <div class="card-body">
        <div class="card-kicker"><span class="cat cat-${esc(w.category)}">${esc(CATEGORIES[w.category] || '')}</span> · <time datetime="${esc(w.published_at)}">${huDate(w.published_at)}</time></div>
        <h2><a href="/iras/${esc(w.slug)}">${esc(w.title)}</a></h2>
        <p>${esc(excerpt(w.body))}</p>
        <div class="card-meta">
          ${likeButton(w)}
          <a class="comment-count" href="/iras/${esc(w.slug)}#hozzaszolasok">${w.comment_count} hozzászólás</a>
        </div>
      </div>
    </article>`).join('');

  return `
  <section class="hero">
    <h1>Viszkető Toll</h1>
    <p>${esc(AUTHOR)} történetei, tárcái és gondolatai – ahogy a toll viszketett.</p>
    <form class="search" method="get" action="/" role="search">
      ${kat ? `<input type="hidden" name="kat" value="${esc(kat)}">` : ''}
      <input type="search" name="q" value="${esc(q || '')}" placeholder="Keresés az írásokban…" aria-label="Keresés">
      <button class="btn">Keresés</button>
    </form>
  </section>
  <nav class="tabs" aria-label="Kategóriák">
    <a href="/${q ? `?q=${encodeURIComponent(q)}` : ''}" class="${!kat ? 'active' : ''}">Összes</a>
    ${Object.entries(CATEGORIES).map(([k, label]) => `<a href="/?${new URLSearchParams({ kat: k, ...(q ? { q } : {}) })}" class="${kat === k ? 'active' : ''}">${label}</a>`).join('')}
  </nav>
  ${q ? `<p class="result-info">${total} találat erre: „${esc(q)}” · <a href="/">összes írás</a></p>` : ''}
  ${writings.length ? `<div class="grid">${cards}</div>` : `<p class="empty">${q ? 'Nincs találat.' : 'Még nincsenek írások feltöltve.'}</p>`}
  ${pagination(page, pages, q, kat)}`;
}

export function writingPage({ w, images, comments, myPending, user, prev, next, commentError, commentDraft }) {
  const [hero, ...rest] = images;
  return `
  <article class="writing">
    <header class="writing-head">
      <div class="card-kicker"><a class="cat cat-${esc(w.category)}" href="/?kat=${esc(w.category)}">${esc(CATEGORIES[w.category] || '')}</a> · <time datetime="${esc(w.published_at)}">${huDate(w.published_at)}</time></div>
      <h1>${esc(w.title)}</h1>
      ${w.hidden ? '<p><span class="pill">Rejtett – csak te látod</span></p>' : ''}
    </header>
    ${hero ? `<figure class="writing-hero"><img src="${imgUrl(hero.path)}" alt="Illusztráció: ${esc(w.title)}"></figure>` : ''}
    <div class="writing-body ${w.category === 'vers' ? 'poem' : ''}">${renderBody(w.body)}</div>
    ${rest.length ? `<div class="gallery">${rest.map((i) => `<figure><img src="${imgUrl(i.path)}" alt="Illusztráció: ${esc(w.title)}" loading="lazy"></figure>`).join('')}</div>` : ''}
    <div class="writing-actions">
      ${likeButton(w)}
      <span class="muted">Ha tetszett, jelezd egy szívvel – ehhez nem kell bejelentkezni.</span>
    </div>
    ${user?.role === 'admin' ? `<p class="admin-link"><a href="/admin/iras/${w.id}">✎ Írás szerkesztése</a></p>` : ''}
  </article>

  <nav class="prevnext">
    ${next ? `<a href="/iras/${esc(next.slug)}"><small>← Újabb írás</small>${esc(next.title)}</a>` : '<span></span>'}
    ${prev ? `<a href="/iras/${esc(prev.slug)}" class="right"><small>Régebbi írás →</small>${esc(prev.title)}</a>` : '<span></span>'}
  </nav>

  <section class="comments" id="hozzaszolasok">
    <h2>Hozzászólások <span class="muted">(${comments.length})</span></h2>
    ${comments.length ? comments.map((c) => `
      <div class="comment">
        <div class="comment-head"><strong>${esc(c.name)}</strong> <time>${huDateTime(c.created_at)}</time></div>
        <p>${esc(c.body).replace(/\n/g, '<br>')}</p>
      </div>`).join('') : '<p class="muted">Még nincs hozzászólás. Légy te az első!</p>'}

    ${myPending.map((c) => `
      <div class="comment comment-pending">
        <div class="comment-head"><strong>${esc(user.name)}</strong> <span class="pill">Jóváhagyásra vár</span></div>
        <p>${esc(c.body).replace(/\n/g, '<br>')}</p>
      </div>`).join('')}

    ${user ? `
      <form method="post" action="/iras/${esc(w.slug)}/hozzaszolas" class="comment-form">
        <label for="comment">Írd meg a véleményed</label>
        ${commentError ? `<p class="error">${esc(commentError)}</p>` : ''}
        <textarea id="comment" name="body" rows="4" maxlength="${COMMENT_MAX}" required data-counter>${esc(commentDraft || '')}</textarea>
        <div class="form-row"><small class="muted"><span data-count>0</span>/${COMMENT_MAX} karakter · A hozzászólás Judit jóváhagyása után jelenik meg.</small>
        <button class="btn">Elküldöm</button></div>
      </form>` : `
      <p class="login-hint">Hozzászólás írásához <a href="/belepes?vissza=${encodeURIComponent(`/iras/${w.slug}#hozzaszolasok`)}">jelentkezz be</a>, vagy <a href="/regisztracio?vissza=${encodeURIComponent(`/iras/${w.slug}#hozzaszolasok`)}">regisztrálj</a> – csak a neved és az e-mail-címed kell hozzá.</p>`}
  </section>`;
}

export function authPage({ mode, error, values = {}, back }) {
  const reg = mode === 'register';
  return `
  <section class="auth">
    <h1>${reg ? 'Regisztráció' : 'Belépés'}</h1>
    ${reg ? '<p class="muted">Regisztráció után hozzászólhatsz az írásokhoz. Csak a neved és az e-mail-címed kell.</p>' : ''}
    ${error ? `<p class="error">${esc(error)}</p>` : ''}
    <form method="post" action="${reg ? '/regisztracio' : '/belepes'}" class="stack">
      <input type="hidden" name="vissza" value="${esc(back || '/')}">
      ${reg ? `<label>Név <input name="name" required maxlength="60" value="${esc(values.name || '')}" autocomplete="name"></label>` : ''}
      <label>E-mail-cím <input type="email" name="email" required maxlength="200" value="${esc(values.email || '')}" autocomplete="email"></label>
      <label>Jelszó <input type="password" name="password" required minlength="${reg ? 6 : 1}" autocomplete="${reg ? 'new-password' : 'current-password'}"></label>
      ${reg ? '<small class="muted">Legalább 6 karakter.</small>' : ''}
      <button class="btn">${reg ? 'Regisztrálok' : 'Belépek'}</button>
    </form>
    <p class="muted">${reg
      ? `Van már fiókod? <a href="/belepes?vissza=${encodeURIComponent(back || '/')}">Belépés</a>`
      : `Még nincs fiókod? <a href="/regisztracio?vissza=${encodeURIComponent(back || '/')}">Regisztráció</a>`}</p>
  </section>`;
}

/* ---------------- admin ---------------- */

function adminNav(active) {
  const item = (href, label, key) => `<a href="${href}" class="${active === key ? 'active' : ''}">${label}</a>`;
  return `<nav class="admin-nav">
    ${item('/admin', 'Hozzászólások', 'comments')}
    ${item('/admin/irasok', 'Írások', 'writings')}
    ${item('/admin/iras/uj', '+ Új írás', 'new')}
    ${item('/admin/felhasznalok', 'Olvasók', 'users')}
  </nav>`;
}

export function adminComments({ pending, recent }) {
  const row = (c, actions) => `
    <div class="mod-item">
      <div class="mod-meta"><strong>${esc(c.name)}</strong> <span class="muted">&lt;${esc(c.email)}&gt;</span> ·
        <a href="/iras/${esc(c.slug)}#hozzaszolasok">${esc(c.title)}</a> · <time>${huDateTime(c.created_at)}</time></div>
      <p>${esc(c.body).replace(/\n/g, '<br>')}</p>
      <div class="mod-actions">${actions}</div>
    </div>`;
  const btn = (id, action, label, cls = '') =>
    `<form method="post" action="/admin/hozzaszolas/${id}/${action}" class="inline"><button class="btn btn-small ${cls}">${label}</button></form>`;

  return `
  <h1>Szerkesztés</h1>
  ${adminNav('comments')}
  <h2>Jóváhagyásra váró hozzászólások (${pending.length})</h2>
  ${pending.length ? pending.map((c) => row(c, btn(c.id, 'jovahagy', 'Megjelenhet') + btn(c.id, 'elutasit', 'Elutasítom', 'btn-ghost'))).join('')
    : '<p class="muted">Nincs új hozzászólás. ☕</p>'}
  <h2>Korábbi döntések</h2>
  ${recent.length ? recent.map((c) => row(c,
    `<span class="pill ${c.status === 'approved' ? 'pill-ok' : 'pill-no'}">${c.status === 'approved' ? 'Megjelent' : 'Elutasítva'}</span>
     ${c.status === 'approved' ? btn(c.id, 'elutasit', 'Visszavonom', 'btn-ghost') : btn(c.id, 'jovahagy', 'Mégis megjelenhet', 'btn-ghost')}
     ${btn(c.id, 'torol', 'Törlés', 'btn-danger')}`)).join('') : '<p class="muted">Még nincs.</p>'}`;
}

export function adminWritings({ writings, show, counts }) {
  const f = (key, label) => `<a href="/admin/irasok${key ? `?mutat=${key}` : ''}" class="${show === key ? 'active' : ''}">${label}</a>`;
  return `
  <h1>Szerkesztés</h1>
  ${adminNav('writings')}
  <p class="muted">A rejtett írások piszkozatok: az olvasók nem látják őket. A „Közzéteszem” gombbal egy kattintással megjelentetheted bármelyiket.</p>
  <nav class="tabs tabs-small">${f('', `Mind (${counts.all_})`)}${f('lathato', `Láthatók (${counts.visible || 0})`)}${f('rejtett', `Rejtettek (${counts.hidden || 0})`)}</nav>
  <table class="table">
    <thead><tr><th>Cím</th><th>Fajta</th><th>Dátum</th><th>Kép</th><th>♥</th><th>Hozzá&shy;szólás</th><th></th></tr></thead>
    <tbody>
    ${writings.map((w) => `<tr class="${w.hidden ? 'is-hidden' : ''}">
      <td><a href="/iras/${esc(w.slug)}">${esc(w.title)}</a>${w.hidden ? ' <span class="pill">rejtett</span>' : ''}
        ${w.source_file ? `<br><small class="muted">${esc(w.source_file)}</small>` : ''}</td>
      <td>${esc(CATEGORIES[w.category] || '')}</td>
      <td class="nowrap">${esc(w.published_at)}</td>
      <td>${w.image_count || '<span class="warn">nincs</span>'}</td>
      <td>${w.like_count}</td>
      <td>${w.comment_count}</td>
      <td class="nowrap"><a href="/admin/iras/${w.id}">szerkesztés</a><br>
        <form method="post" action="/admin/iras/${w.id}/lathatosag" class="inline"><input type="hidden" name="vissza" value="/admin/irasok${show ? `?mutat=${show}` : ''}">
        <button class="linkish">${w.hidden ? 'Közzéteszem' : 'Elrejtem'}</button></form></td>
    </tr>`).join('')}
    </tbody>
  </table>`;
}

export function adminEditWriting({ w, images, library = [], error }) {
  const isNew = !w.id;
  return `
  <h1>Szerkesztés</h1>
  ${adminNav(isNew ? 'new' : 'writings')}
  <h2>${isNew ? 'Új írás' : esc(w.title)}</h2>
  ${error ? `<p class="error">${esc(error)}</p>` : ''}
  <form method="post" action="${isNew ? '/admin/iras/uj' : `/admin/iras/${w.id}`}" enctype="multipart/form-data" class="stack edit-form">
    <label>Cím <input name="title" required maxlength="200" value="${esc(w.title || '')}"></label>
    <div class="form-grid">
      <label>Dátum <input type="date" name="published_at" required value="${esc(w.published_at || new Date().toISOString().slice(0, 10))}"></label>
      <label>Fajta <select name="category">${Object.entries(CATEGORIES).map(([k, l]) => `<option value="${k}" ${(w.category || 'proza') === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
    </div>
    <label>Szöveg
      <textarea name="body" rows="22" required>${esc(w.body || '')}</textarea>
    </label>
    <small class="muted">Üres sor = új bekezdés. **félkövér**, *dőlt*.</small>
    ${images?.length ? `<fieldset><legend>Képek</legend><div class="admin-images">
      ${images.map((i) => `<label class="admin-image"><img src="${imgUrl(i.path)}" alt=""><span><input type="checkbox" name="remove_image" value="${i.id}"> törlés</span></label>`).join('')}
    </div></fieldset>` : ''}
    ${library.length ? `<details class="library"><summary>Kép a képtárból (${library.length})</summary>
      <p class="muted">Jelöld be, amelyik képet ehhez az íráshoz szeretnéd tenni. Ha egy kép már máshol is szerepel, az alatta lévő felirat mutatja, hol.</p>
      <div class="admin-images">
      ${library.map((i) => `<label class="admin-image"><img src="${imgUrl(i.path)}" alt="" loading="lazy"><span><input type="checkbox" name="library" value="${esc(i.name)}"> ${esc(i.name)}</span>${i.usedBy ? `<small class="muted">már itt: ${esc(i.usedBy)}</small>` : ''}</label>`).join('')}
      </div></details>` : ''}
    <label>Új kép feltöltése a gépről <input type="file" name="images" accept="image/jpeg,image/png,image/webp,image/gif" multiple></label>
    ${isNew ? '' : `<label class="check"><input type="checkbox" name="hidden" value="1" ${w.hidden ? 'checked' : ''}> Rejtett (nem jelenik meg az olvasóknak)</label>`}
    <div class="form-row">
      <button class="btn">${isNew ? 'Közzéteszem' : 'Mentés'}</button>
      ${isNew ? '' : `<a href="/iras/${esc(w.slug)}">Megtekintés</a>`}
    </div>
  </form>
  ${isNew ? '' : `<form method="post" action="/admin/iras/${w.id}/torol" class="danger-zone" data-confirm="Biztosan törlöd ezt az írást a lájkokkal és hozzászólásokkal együtt?">
    <button class="btn btn-danger btn-small">Írás végleges törlése</button>
  </form>`}`;
}

export function adminUsers({ users }) {
  return `
  <h1>Szerkesztés</h1>
  ${adminNav('users')}
  <table class="table">
    <thead><tr><th>Név</th><th>E-mail</th><th>Regisztrált</th><th>Hozzászólás</th><th></th></tr></thead>
    <tbody>
    ${users.map((u) => `<tr>
      <td>${esc(u.name)}${u.role === 'admin' ? ' <span class="pill">szerkesztő</span>' : ''}</td>
      <td>${esc(u.email)}</td>
      <td>${huDateTime(u.created_at)}</td>
      <td>${u.comment_count}</td>
      <td>${u.role === 'admin' ? '' : `<form method="post" action="/admin/felhasznalo/${u.id}/torol" class="inline" data-confirm="Törlöd ${esc(u.name)} fiókját és minden hozzászólását?"><button class="linkish danger">törlés</button></form>`}</td>
    </tr>`).join('')}
    </tbody>
  </table>`;
}

export function messagePage(title, text) {
  return `<section class="auth"><h1>${esc(title)}</h1><p>${esc(text)}</p><p><a href="/">Vissza a főoldalra</a></p></section>`;
}
