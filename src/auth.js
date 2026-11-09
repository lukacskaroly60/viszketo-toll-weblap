// Regisztráció, bejelentkezés, munkamenetek, anonim látogató-azonosító.
import crypto from 'node:crypto';
import { db } from './db.js';
import { IS_PROD, SESSION_DAYS } from './config.js';

const SCRYPT_N = 16384;

export function hashPassword(password) {
  const salt = crypto.randomBytes(16);
  const hash = crypto.scryptSync(password, salt, 64, { N: SCRYPT_N });
  return `scrypt$${SCRYPT_N}$${salt.toString('base64')}$${hash.toString('base64')}`;
}

export function verifyPassword(password, stored) {
  const [alg, n, saltB64, hashB64] = String(stored).split('$');
  if (alg !== 'scrypt') return false;
  const expected = Buffer.from(hashB64, 'base64');
  const actual = crypto.scryptSync(password, Buffer.from(saltB64, 'base64'), expected.length, { N: Number(n) });
  return crypto.timingSafeEqual(expected, actual);
}

export function normalizeEmail(email) {
  return String(email || '').trim().toLowerCase();
}

export function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email) && email.length <= 200;
}

/* ---------- sütik ---------- */

export function parseCookies(header) {
  const out = {};
  for (const part of String(header || '').split(';')) {
    const i = part.indexOf('=');
    if (i < 0) continue;
    const k = part.slice(0, i).trim();
    if (k) out[k] = decodeURIComponent(part.slice(i + 1).trim());
  }
  return out;
}

function cookieOpts(maxAgeDays) {
  return {
    httpOnly: true,
    sameSite: 'lax',
    secure: IS_PROD,
    path: '/',
    maxAge: maxAgeDays * 24 * 3600 * 1000,
  };
}

/* ---------- munkamenetek ---------- */

const stmt = {
  getSession: db.prepare(`
    SELECT u.id, u.email, u.name, u.role
    FROM sessions s JOIN users u ON u.id = s.user_id
    WHERE s.token = ? AND s.expires_at > datetime('now')`),
  insertSession: db.prepare(`INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, datetime('now', ?))`),
  deleteSession: db.prepare(`DELETE FROM sessions WHERE token = ?`),
  purgeSessions: db.prepare(`DELETE FROM sessions WHERE expires_at <= datetime('now')`),
};

export function startSession(res, userId) {
  const token = crypto.randomBytes(32).toString('base64url');
  stmt.insertSession.run(token, userId, `+${SESSION_DAYS} days`);
  res.cookie('sid', token, cookieOpts(SESSION_DAYS));
}

export function endSession(req, res) {
  const token = req.cookies.sid;
  if (token) stmt.deleteSession.run(token);
  res.clearCookie('sid', { path: '/' });
}

/** Middleware: req.cookies, req.user, req.visitorId beállítása. */
export function sessionMiddleware(req, res, next) {
  req.cookies = parseCookies(req.headers.cookie);
  req.user = req.cookies.sid ? stmt.getSession.get(req.cookies.sid) || null : null;

  // Anonim látogató-azonosító a lájkokhoz (bejelentkezés nélkül is)
  let vid = req.cookies.vid;
  if (!vid || !/^[A-Za-z0-9_-]{16,64}$/.test(vid)) {
    vid = crypto.randomBytes(18).toString('base64url');
    res.cookie('vid', vid, cookieOpts(3650));
  }
  req.visitorId = vid;
  next();
}

// Lejárt munkamenetek takarítása óránként
setInterval(() => stmt.purgeSessions.run(), 3600 * 1000).unref();

/* ---------- jogosultság ---------- */

export function requireUser(req, res, next) {
  if (req.user) return next();
  res.redirect(`/belepes?vissza=${encodeURIComponent(req.originalUrl)}`);
}

export function requireAdmin(req, res, next) {
  if (req.user?.role === 'admin') return next();
  if (!req.user) return res.redirect(`/belepes?vissza=${encodeURIComponent(req.originalUrl)}`);
  res.status(403).send('Ehhez az oldalhoz nincs jogosultságod.');
}

/**
 * CSRF-védelem: a sütik SameSite=Lax beállításúak, és minden POST-nál
 * ellenőrizzük, hogy az Origin / Referer a saját oldalunk-e.
 */
export function sameOriginGuard(req, res, next) {
  if (req.method !== 'POST') return next();
  const origin = req.headers.origin || req.headers.referer;
  if (!origin) return next(); // néhány régi böngésző nem küldi; a SameSite süti így is véd
  try {
    if (new URL(origin).host === req.headers.host) return next();
  } catch { /* érvénytelen fejléc */ }
  res.status(403).send('Érvénytelen kérés.');
}

/* ---------- egyszerű, memóriában tartott sebességkorlát ---------- */

const buckets = new Map();
export function rateLimit({ windowMs, max, key = (req) => req.ip }) {
  return (req, res, next) => {
    const k = `${req.path}|${key(req)}`;
    const now = Date.now();
    const b = buckets.get(k);
    if (!b || b.reset < now) {
      buckets.set(k, { count: 1, reset: now + windowMs });
      return next();
    }
    if (++b.count > max) {
      return res.status(429).send('Túl sok próbálkozás, kérlek, várj egy kicsit.');
    }
    next();
  };
}
setInterval(() => {
  const now = Date.now();
  for (const [k, b] of buckets) if (b.reset < now) buckets.delete(k);
}, 10 * 60 * 1000).unref();
