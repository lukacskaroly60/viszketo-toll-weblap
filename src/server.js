import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import express from 'express';
import multer from 'multer';
import { db, tx, CATEGORIES } from './db.js';
import {
  ROOT, PORT, IS_PROD, IMAGES_DIR, UPLOAD_DIR, PAGE_SIZE, COMMENT_MAX,
} from './config.js';
import {
  sessionMiddleware, sameOriginGuard, rateLimit, requireAdmin,
  hashPassword, verifyPassword, normalizeEmail, isValidEmail, startSession, endSession,
} from './auth.js';
import * as V from './views.js';
import { slugify, excerpt } from './text.js';
import { importContent, uniqueSlug, listLibraryImages } from './import.js';

fs.mkdirSync(UPLOAD_DIR, { recursive: true });

const app = express();
app.disable('x-powered-by');
if (IS_PROD) app.set('trust proxy', 1);

app.use((req, res, next) => {
  res.set({
    'X-Content-Type-Options': 'nosniff',
    'Referrer-Policy': 'same-origin',
    'X-Frame-Options': 'SAMEORIGIN',
  });
  next();
});

app.use('/static', express.static(path.join(ROOT, 'public'), { maxAge: IS_PROD ? '7d' : 0 }));
app.use('/kepek', express.static(IMAGES_DIR, { maxAge: '30d' }));
app.use('/feltoltesek', express.static(UPLOAD_DIR, { maxAge: '30d' }));
app.use(express.urlencoded({ extended: false, limit: '200kb' }));
app.use(express.json({ limit: '10kb' }));
app.use(sessionMiddleware);
app.use(sameOriginGuard);

/* ---------- segédek ---------- */

const pendingCountStmt = db.prepare(`SELECT COUNT(*) AS n FROM comments WHERE status = 'pending'`);

function setFlash(res, type, text) {
  res.cookie('flash', JSON.stringify({ type, text }), { httpOnly: true, sameSite: 'lax', maxAge: 60_000, path: '/' });
}

function render(req, res, opts, status = 200) {
  let flash = null;
  if (req.cookies.flash) {
    try { flash = JSON.parse(req.cookies.flash); } catch { /* hibás süti */ }
    res.clearCookie('flash', { path: '/' });
  }
  const pendingCount = req.user?.role === 'admin' ? pendingCountStmt.get().n : 0;
  res.status(status).type('html').send(V.layout({ ...opts, user: req.user, pendingCount, flash }));
}

/** Csak a saját oldalunkon belüli visszairányítás engedett. */
function safeBack(url) {
  return typeof url === 'string' && url.startsWith('/') && !url.startsWith('//') ? url : '/';
}

const LIST_COLUMNS = `
  w.id, w.slug, w.title, w.body, w.published_at, w.hidden, w.category,
  (SELECT COUNT(*) FROM likes l WHERE l.writing_id = w.id) AS like_count,
  (SELECT COUNT(*) FROM comments c WHERE c.writing_id = w.id AND c.status = 'approved') AS comment_count,
  (SELECT path FROM images i WHERE i.writing_id = w.id ORDER BY position, id LIMIT 1) AS image,
  EXISTS (SELECT 1 FROM likes l WHERE l.writing_id = w.id AND l.visitor_id = :vid) AS liked`;

/* ---------- nyilvános oldalak ---------- */

app.get('/', (req, res) => {
  const q = String(req.query.q || '').trim().slice(0, 100);
  const kat = CATEGORIES[req.query.kat] ? req.query.kat : '';
  const page = Math.max(1, Number.parseInt(req.query.oldal, 10) || 1);
  const where = `w.hidden = 0 ${q ? `AND (w.title LIKE :like OR w.body LIKE :like)` : ''} ${kat ? 'AND w.category = :kat' : ''}`;
  const filter = { ...(q ? { like: `%${q}%` } : {}), ...(kat ? { kat } : {}) };
  const params = { vid: req.visitorId, ...filter };
  const { n: total } = db.prepare(`SELECT COUNT(*) AS n FROM writings w WHERE ${where}`).get(filter);
  const pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
  const writings = db.prepare(`
    SELECT ${LIST_COLUMNS} FROM writings w WHERE ${where}
    ORDER BY w.published_at DESC, w.id DESC LIMIT ${PAGE_SIZE} OFFSET ${(page - 1) * PAGE_SIZE}`).all(params);
  render(req, res, { body: V.homePage({ writings, page, pages, q, kat, total }) });
});

const getWriting = db.prepare(`SELECT ${LIST_COLUMNS} FROM writings w WHERE w.slug = :slug`);
const getImages = db.prepare('SELECT id, path FROM images WHERE writing_id = ? ORDER BY position, id');
const getComments = db.prepare(`
  SELECT c.body, c.created_at, u.name FROM comments c JOIN users u ON u.id = c.user_id
  WHERE c.writing_id = ? AND c.status = 'approved' ORDER BY c.created_at`);
const getMyPending = db.prepare(`
  SELECT body FROM comments WHERE writing_id = ? AND user_id = ? AND status = 'pending' ORDER BY created_at`);
const getPrev = db.prepare(`
  SELECT slug, title FROM writings WHERE hidden = 0 AND (published_at < :d OR (published_at = :d AND id < :id))
  ORDER BY published_at DESC, id DESC LIMIT 1`);
const getNext = db.prepare(`
  SELECT slug, title FROM writings WHERE hidden = 0 AND (published_at > :d OR (published_at = :d AND id > :id))
  ORDER BY published_at ASC, id ASC LIMIT 1`);

function showWriting(req, res, extra = {}) {
  const w = getWriting.get({ slug: req.params.slug, vid: req.visitorId });
  if (!w || (w.hidden && req.user?.role !== 'admin')) {
    return render(req, res, { title: 'Nem található', body: V.messagePage('Nem található', 'Ilyen írás nincs (vagy már nincs) az oldalon.') }, 404);
  }
  render(req, res, {
    title: w.title,
    description: excerpt(w.body, 160),
    body: V.writingPage({
      w,
      images: getImages.all(w.id),
      comments: getComments.all(w.id),
      myPending: req.user ? getMyPending.all(w.id, req.user.id) : [],
      user: req.user,
      prev: getPrev.get({ d: w.published_at, id: w.id }),
      next: getNext.get({ d: w.published_at, id: w.id }),
      ...extra,
    }),
  }, extra.status || 200);
}

app.get('/iras/:slug', (req, res) => showWriting(req, res));

/* ---------- lájk (bejelentkezés nélkül) ---------- */

const likeStmts = {
  find: db.prepare('SELECT id FROM writings WHERE slug = ? AND hidden = 0'),
  has: db.prepare('SELECT 1 FROM likes WHERE writing_id = ? AND visitor_id = ?'),
  add: db.prepare('INSERT OR IGNORE INTO likes (writing_id, visitor_id) VALUES (?, ?)'),
  del: db.prepare('DELETE FROM likes WHERE writing_id = ? AND visitor_id = ?'),
  count: db.prepare('SELECT COUNT(*) AS n FROM likes WHERE writing_id = ?'),
};

app.post('/iras/:slug/lajk', rateLimit({ windowMs: 60_000, max: 30 }), (req, res) => {
  const w = likeStmts.find.get(req.params.slug);
  if (!w) return res.status(404).json({ error: 'not_found' });
  const liked = !likeStmts.has.get(w.id, req.visitorId);
  if (liked) likeStmts.add.run(w.id, req.visitorId);
  else likeStmts.del.run(w.id, req.visitorId);
  const count = likeStmts.count.get(w.id).n;
  if (req.accepts(['json', 'html']) === 'json') return res.json({ liked, count });
  res.redirect(`/iras/${encodeURIComponent(req.params.slug)}`);
});

/* ---------- hozzászólás (csak regisztrált olvasóknak) ---------- */

const insertComment = db.prepare('INSERT INTO comments (writing_id, user_id, body, status) VALUES (?, ?, ?, ?)');

app.post('/iras/:slug/hozzaszolas',
  (req, res, next) => (req.user ? next()
    : res.redirect(`/belepes?vissza=${encodeURIComponent(`/iras/${req.params.slug}#hozzaszolasok`)}`)),
  rateLimit({ windowMs: 10 * 60_000, max: 10, key: (req) => `u${req.user.id}` }),
  (req, res) => {
    const w = likeStmts.find.get(req.params.slug);
    if (!w) return res.redirect('/');
    const body = String(req.body.body || '').replace(/\r\n?/g, '\n').trim();
    if (body.length < 2 || body.length > COMMENT_MAX) {
      return showWriting(req, res, { commentError: `A hozzászólás 2 és ${COMMENT_MAX} karakter között lehet.`, commentDraft: body, status: 400 });
    }
    // Judit saját hozzászólásai azonnal megjelennek
    const status = req.user.role === 'admin' ? 'approved' : 'pending';
    insertComment.run(w.id, req.user.id, body, status);
    setFlash(res, 'ok', status === 'approved'
      ? 'A hozzászólásod megjelent.'
      : 'Köszönöm a hozzászólást! Jóváhagyás után jelenik meg.');
    res.redirect(`/iras/${encodeURIComponent(req.params.slug)}#hozzaszolasok`);
  });

/* ---------- regisztráció, belépés ---------- */

const userStmts = {
  byEmail: db.prepare('SELECT * FROM users WHERE email = ?'),
  insert: db.prepare('INSERT INTO users (email, name, password_hash) VALUES (?, ?, ?)'),
};

app.get('/regisztracio', (req, res) => {
  if (req.user) return res.redirect(safeBack(req.query.vissza));
  render(req, res, { title: 'Regisztráció', body: V.authPage({ mode: 'register', back: safeBack(req.query.vissza) }) });
});

app.post('/regisztracio', rateLimit({ windowMs: 60 * 60_000, max: 10 }), (req, res) => {
  const name = String(req.body.name || '').trim().replace(/\s+/g, ' ');
  const email = normalizeEmail(req.body.email);
  const password = String(req.body.password || '');
  const back = safeBack(req.body.vissza);
  const fail = (error) => render(req, res, {
    title: 'Regisztráció', body: V.authPage({ mode: 'register', error, values: { name, email }, back }),
  }, 400);

  if (name.length < 2 || name.length > 60) return fail('Kérlek, add meg a neved (2–60 karakter).');
  if (!isValidEmail(email)) return fail('Ez nem tűnik érvényes e-mail-címnek.');
  if (password.length < 6) return fail('A jelszó legalább 6 karakter legyen.');
  if (userStmts.byEmail.get(email)) return fail('Ezzel az e-mail-címmel már regisztráltak. Próbálj belépni.');

  const id = Number(userStmts.insert.run(email, name, hashPassword(password)).lastInsertRowid);
  startSession(res, id);
  setFlash(res, 'ok', `Üdv, ${name}! Most már hozzászólhatsz az írásokhoz.`);
  res.redirect(back);
});

app.get('/belepes', (req, res) => {
  if (req.user) return res.redirect(safeBack(req.query.vissza));
  render(req, res, { title: 'Belépés', body: V.authPage({ mode: 'login', back: safeBack(req.query.vissza) }) });
});

app.post('/belepes', rateLimit({ windowMs: 15 * 60_000, max: 20 }), (req, res) => {
  const email = normalizeEmail(req.body.email);
  const back = safeBack(req.body.vissza);
  const user = userStmts.byEmail.get(email);
  if (!user || !verifyPassword(String(req.body.password || ''), user.password_hash)) {
    return render(req, res, {
      title: 'Belépés', body: V.authPage({ mode: 'login', error: 'Hibás e-mail-cím vagy jelszó.', values: { email }, back }),
    }, 401);
  }
  startSession(res, user.id);
  res.redirect(back);
});

app.post('/kilepes', (req, res) => {
  endSession(req, res);
  res.redirect('/');
});

/* ---------- szerkesztői felület (Judit) ---------- */

const admin = express.Router();
admin.use(requireAdmin);

admin.get('/', (req, res) => {
  const base = `SELECT c.*, u.name, u.email, w.title, w.slug FROM comments c
    JOIN users u ON u.id = c.user_id JOIN writings w ON w.id = c.writing_id`;
  const pending = db.prepare(`${base} WHERE c.status = 'pending' ORDER BY c.created_at`).all();
  const recent = db.prepare(`${base} WHERE c.status != 'pending' ORDER BY c.reviewed_at DESC LIMIT 30`).all();
  render(req, res, { title: 'Hozzászólások', body: V.adminComments({ pending, recent }) });
});

const commentActions = {
  jovahagy: db.prepare(`UPDATE comments SET status = 'approved', reviewed_at = datetime('now') WHERE id = ?`),
  elutasit: db.prepare(`UPDATE comments SET status = 'rejected', reviewed_at = datetime('now') WHERE id = ?`),
  torol: db.prepare('DELETE FROM comments WHERE id = ?'),
};
admin.post('/hozzaszolas/:id/:action', (req, res) => {
  const action = commentActions[req.params.action];
  if (!action) return res.status(404).end();
  action.run(Number(req.params.id));
  res.redirect('/admin');
});

admin.get('/irasok', (req, res) => {
  const show = ['lathato', 'rejtett'].includes(req.query.mutat) ? req.query.mutat : '';
  const where = show === 'lathato' ? 'WHERE w.hidden = 0' : show === 'rejtett' ? 'WHERE w.hidden = 1' : '';
  const writings = db.prepare(`
    SELECT ${LIST_COLUMNS}, w.source_file, (SELECT COUNT(*) FROM images i WHERE i.writing_id = w.id) AS image_count
    FROM writings w ${where} ORDER BY w.hidden, w.published_at DESC, w.id DESC`).all({ vid: req.visitorId });
  const counts = db.prepare('SELECT COUNT(*) AS all_, SUM(hidden = 0) AS visible, SUM(hidden = 1) AS hidden FROM writings').get();
  render(req, res, { title: 'Írások', body: V.adminWritings({ writings, show, counts }) });
});

const upload = multer({
  storage: multer.diskStorage({
    destination: UPLOAD_DIR,
    filename: (req, file, cb) => {
      const ext = { 'image/jpeg': '.jpg', 'image/png': '.png', 'image/webp': '.webp', 'image/gif': '.gif' }[file.mimetype];
      cb(null, `${Date.now()}-${crypto.randomBytes(4).toString('hex')}${ext}`);
    },
  }),
  limits: { fileSize: 15 * 1024 * 1024, files: 10 },
  fileFilter: (req, file, cb) => cb(null, /^image\/(jpeg|png|webp|gif)$/.test(file.mimetype)),
});

function readWritingForm(req) {
  return {
    title: String(req.body.title || '').trim(),
    body: String(req.body.body || '').replace(/\r\n?/g, '\n').trim(),
    published_at: /^\d{4}-\d{2}-\d{2}$/.test(req.body.published_at) ? req.body.published_at : new Date().toISOString().slice(0, 10),
    hidden: req.body.hidden ? 1 : 0,
    category: CATEGORIES[req.body.category] ? req.body.category : 'proza',
  };
}

const addImage = db.prepare(`INSERT INTO images (writing_id, path, position)
  VALUES (?, ?, (SELECT COALESCE(MAX(position), -1) + 1 FROM images WHERE writing_id = ?))`);

function saveUploads(writingId, files) {
  for (const f of files || []) addImage.run(writingId, `feltoltesek/${f.filename}`, writingId);
}

/** A képtárból (tartalom/kepek) kiválasztott képek hozzárendelése. */
function saveLibraryPicks(writingId, picks) {
  const lib = new Set(listLibraryImages());
  for (const name of [].concat(picks || [])) {
    if (lib.has(name)) db.prepare('INSERT OR IGNORE INTO images (writing_id, path, position) VALUES (?, ?, (SELECT COALESCE(MAX(position), -1) + 1 FROM images WHERE writing_id = ?))')
      .run(writingId, `kepek/${name}`, writingId);
  }
}

function libraryFor(writingId) {
  const usedHere = new Set(writingId ? getImages.all(writingId).map((i) => i.path) : []);
  const usedElsewhere = new Map(db.prepare(`SELECT i.path, w.title FROM images i JOIN writings w ON w.id = i.writing_id
    WHERE i.path LIKE 'kepek/%'`).all().map((r) => [r.path, r.title]));
  return listLibraryImages()
    .filter((n) => !usedHere.has(`kepek/${n}`))
    .map((n) => ({ name: n, path: `kepek/${n}`, usedBy: usedElsewhere.get(`kepek/${n}`) || null }));
}

function removeUploadedFile(p) {
  if (p.startsWith('feltoltesek/')) fs.rm(path.join(UPLOAD_DIR, path.basename(p)), { force: true }, () => {});
}

admin.get('/iras/uj', (req, res) => {
  render(req, res, { title: 'Új írás', body: V.adminEditWriting({ w: {}, library: libraryFor(null) }) });
});

admin.post('/iras/uj', upload.array('images', 10), (req, res) => {
  const f = readWritingForm(req);
  if (!f.title || !f.body) {
    (req.files || []).forEach((x) => removeUploadedFile(`feltoltesek/${x.filename}`));
    return render(req, res, { title: 'Új írás', body: V.adminEditWriting({ w: f, library: libraryFor(null), error: 'A cím és a szöveg kötelező.' }) }, 400);
  }
  const slug = uniqueSlug(slugify(f.title));
  tx(() => {
    const newId = Number(db.prepare(`INSERT INTO writings (slug, title, body, published_at, category, edited) VALUES (?, ?, ?, ?, ?, 1)`)
      .run(slug, f.title, f.body, f.published_at, f.category).lastInsertRowid);
    saveUploads(newId, req.files);
    saveLibraryPicks(newId, req.body.library);
  });
  setFlash(res, 'ok', 'Az írás megjelent.');
  res.redirect(`/iras/${slug}`);
});

const getWritingById = db.prepare('SELECT * FROM writings WHERE id = ?');

admin.get('/iras/:id', (req, res) => {
  const w = getWritingById.get(Number(req.params.id));
  if (!w) return res.redirect('/admin/irasok');
  render(req, res, { title: w.title, body: V.adminEditWriting({ w, images: getImages.all(w.id), library: libraryFor(w.id) }) });
});

admin.post('/iras/:id', upload.array('images', 10), (req, res) => {
  const w = getWritingById.get(Number(req.params.id));
  if (!w) return res.redirect('/admin/irasok');
  const f = readWritingForm(req);
  if (!f.title || !f.body) {
    return render(req, res, { title: w.title, body: V.adminEditWriting({ w: { ...w, ...f }, images: getImages.all(w.id), library: libraryFor(w.id), error: 'A cím és a szöveg kötelező.' }) }, 400);
  }
  const removeIds = [].concat(req.body.remove_image || []).map(Number).filter(Boolean);
  tx(() => {
    db.prepare(`UPDATE writings SET title = ?, body = ?, published_at = ?, hidden = ?, category = ?, edited = 1 WHERE id = ?`)
      .run(f.title, f.body, f.published_at, f.hidden, f.category, w.id);
    for (const imgId of removeIds) {
      const img = db.prepare('SELECT path FROM images WHERE id = ? AND writing_id = ?').get(imgId, w.id);
      if (!img) continue;
      db.prepare('DELETE FROM images WHERE id = ?').run(imgId);
      removeUploadedFile(img.path);
    }
    saveUploads(w.id, req.files);
    saveLibraryPicks(w.id, req.body.library);
  });
  setFlash(res, 'ok', 'Mentve.');
  res.redirect(`/admin/iras/${w.id}`);
});

admin.post('/iras/:id/lathatosag', (req, res) => {
  db.prepare('UPDATE writings SET hidden = 1 - hidden, edited = 1 WHERE id = ?').run(Number(req.params.id));
  res.redirect(safeBack(req.body.vissza || '/admin/irasok'));
});

admin.post('/iras/:id/torol', (req, res) => {
  const w = getWritingById.get(Number(req.params.id));
  if (w) {
    getImages.all(w.id).forEach((i) => removeUploadedFile(i.path));
    db.prepare('DELETE FROM writings WHERE id = ?').run(w.id);
    setFlash(res, 'ok', `„${w.title}” törölve.`);
  }
  res.redirect('/admin/irasok');
});

admin.get('/felhasznalok', (req, res) => {
  const users = db.prepare(`SELECT u.*, (SELECT COUNT(*) FROM comments c WHERE c.user_id = u.id) AS comment_count
    FROM users u ORDER BY u.created_at DESC`).all();
  render(req, res, { title: 'Olvasók', body: V.adminUsers({ users }) });
});

admin.post('/felhasznalo/:id/torol', (req, res) => {
  db.prepare(`DELETE FROM users WHERE id = ? AND role != 'admin'`).run(Number(req.params.id));
  res.redirect('/admin/felhasznalok');
});

app.use('/admin', admin);

/* ---------- hibák ---------- */

app.use((req, res) => {
  render(req, res, { title: 'Nem található', body: V.messagePage('Nem található', 'Ez az oldal nem létezik.') }, 404);
});

app.use((err, req, res, _next) => {
  console.error(err);
  const msg = err instanceof multer.MulterError ? 'A feltöltött kép túl nagy vagy túl sok (max. 10 db, egyenként 15 MB).' : 'Váratlan hiba történt.';
  res.status(err instanceof multer.MulterError ? 400 : 500).type('html').send(
    V.layout({ title: 'Hiba', user: req.user, body: V.messagePage('Hoppá', msg) }));
});

/* ---------- indítás ---------- */

const { n: writingCount } = db.prepare('SELECT COUNT(*) AS n FROM writings').get();
if (writingCount === 0) {
  console.log('Üres adatbázis – írások importálása a tartalom/ mappából…');
  await importContent();
}

app.listen(PORT, () => console.log(`Viszkető Toll fut: http://localhost:${PORT}`));
