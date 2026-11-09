// Írások és képek importálása a tartalom/ mappából.
//
//   tartalom/irasok/  – .docx, .txt vagy .md fájlok (egy fájl = egy írás)
//   tartalom/kepek/   – .jpg, .png, .webp, .gif képek
//
// A kép a fájlneve alapján kerül az írás mellé, pl.:
//   irasok/Őszi séta.docx  <->  kepek/Őszi séta.jpg, kepek/oszi_seta_2.png
//
// Futtatás:  npm run import
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import mammoth from 'mammoth';
import { db, tx } from './db.js';
import { WRITINGS_DIR, IMAGES_DIR } from './config.js';
import { parseFileName, matchKey, slugify, htmlToMarkup } from './text.js';

const TEXT_EXT = new Set(['.docx', '.txt', '.md']);
const IMAGE_EXT = new Set(['.jpg', '.jpeg', '.png', '.webp', '.gif']);

function listFiles(dir, exts) {
  if (!fs.existsSync(dir)) return [];
  return fs.readdirSync(dir, { withFileTypes: true })
    .filter((d) => d.isFile() && !/^(\.|~\$|OLVASS_EL\.|README\.)/i.test(d.name))
    .map((d) => d.name)
    .filter((n) => !exts || exts.has(path.extname(n).toLowerCase()))
    .sort((a, b) => a.localeCompare(b, 'hu'));
}

async function readWriting(file) {
  const full = path.join(WRITINGS_DIR, file);
  const ext = path.extname(file).toLowerCase();
  let { title, date } = parseFileName(file);
  let body;

  if (ext === '.docx') {
    const { value } = await mammoth.convertToHtml({ path: full });
    body = htmlToMarkup(value);
  } else {
    body = fs.readFileSync(full, 'utf8').replace(/^﻿/, '').replace(/\r\n?/g, '\n').trim();
    if (ext === '.md') {
      const h = body.match(/^#\s+(.+)\n+/);
      if (h) { title = h[1].trim(); body = body.slice(h[0].length); }
    }
  }

  // Ha a szöveg első bekezdése maga a cím, ne jelenjen meg kétszer.
  const [first, ...rest] = body.split(/\n\s*\n/);
  if (first && slugify(first.replace(/\*/g, '')) === slugify(title)) body = rest.join('\n\n').trim();

  if (!date) date = fs.statSync(full).mtime.toISOString().slice(0, 10);
  return { title, date, body };
}

/**
 * Melyik íráshoz tartozik a kép? A leghosszabb olyan írás-kulcs nyer,
 * amellyel a kép neve megegyezik vagy azzal kezdődik (pl. "oszi-seta-2").
 * Ha nincs ilyen, az nyer, amelyiknek a kulcsa szerepel a kép nevében.
 */
export function matchImage(imageFile, writingKeys) {
  const key = matchKey(imageFile);
  const longest = (arr) => arr.reduce((a, b) => (b.length > a.length ? b : a), arr[0]) ?? null;
  const shortest = (arr) => arr.reduce((a, b) => (b.length < a.length ? b : a), arr[0]) ?? null;
  const ks = writingKeys.filter(Boolean);

  // 1. pontos egyezés vagy "cím-2", "cím-illusztracio" stb.
  const prefix = ks.filter((wk) => key === wk || key.startsWith(wk + '-'));
  if (prefix.length) return longest(prefix);
  // 2. az írás címe szerepel a kép nevében (pl. "kep-oszi-seta")
  const inside = ks.filter((wk) => wk.length >= 4 && key.includes(wk));
  if (inside.length) return longest(inside);
  // 3. a kép neve az írás címének eleje (pl. kép: "macska", írás: "macska a padlason")
  const start = ks.filter((wk) => key.length >= 4 && wk.startsWith(key + '-'));
  if (start.length) return shortest(start);
  return null;
}

const q = {
  bySource: db.prepare('SELECT id, slug, edited FROM writings WHERE source_file = ?'),
  slugTaken: db.prepare('SELECT 1 FROM writings WHERE slug = ?'),
  insert: db.prepare('INSERT INTO writings (slug, title, body, published_at, source_file) VALUES (?, ?, ?, ?, ?)'),
  update: db.prepare('UPDATE writings SET title = ?, body = ?, published_at = ? WHERE id = ?'),
  clearImported: db.prepare(`DELETE FROM images WHERE writing_id = ? AND path LIKE 'kepek/%'`),
  addImage: db.prepare('INSERT OR IGNORE INTO images (writing_id, path, position) VALUES (?, ?, ?)'),
};

export function uniqueSlug(base) {
  let slug = base, i = 2;
  while (q.slugTaken.get(slug)) slug = `${base}-${i++}`;
  return slug;
}

export async function importContent({ log = console.log } = {}) {
  const textFiles = listFiles(WRITINGS_DIR, TEXT_EXT);
  const otherFiles = listFiles(WRITINGS_DIR).filter((f) => !TEXT_EXT.has(path.extname(f).toLowerCase()));
  const imageFiles = listFiles(IMAGES_DIR, IMAGE_EXT);

  const parsed = [];
  for (const file of textFiles) {
    try {
      parsed.push({ file, key: matchKey(file), ...(await readWriting(file)) });
    } catch (e) {
      log(`  ! Nem sikerült beolvasni: ${file} (${e.message})`);
    }
  }

  const keys = parsed.map((p) => p.key);
  const imagesByKey = new Map();
  const unmatched = [];
  for (const img of imageFiles) {
    const k = matchImage(img, keys);
    if (!k) { unmatched.push(img); continue; }
    if (!imagesByKey.has(k)) imagesByKey.set(k, []);
    imagesByKey.get(k).push(img);
  }

  const stats = { new: 0, updated: 0, keptEdited: 0, images: 0 };
  tx(() => {
    for (const p of parsed) {
      const existing = q.bySource.get(p.file);
      let id;
      if (!existing) {
        id = Number(q.insert.run(uniqueSlug(slugify(p.title)), p.title, p.body, p.date, p.file).lastInsertRowid);
        stats.new++;
      } else {
        id = existing.id;
        if (existing.edited) { stats.keptEdited++; continue; } // kézzel szerkesztett: szöveghez, képekhez nem nyúlunk
        q.update.run(p.title, p.body, p.date, id);
        stats.updated++;
      }
      q.clearImported.run(id);
      const imgs = (imagesByKey.get(p.key) || []).sort((a, b) => a.localeCompare(b, 'hu', { numeric: true }));
      imgs.forEach((img, i) => { q.addImage.run(id, `kepek/${img}`, i); stats.images++; });
    }
  });

  log(`Import kész: ${stats.new} új, ${stats.updated} frissített írás` +
    (stats.keptEdited ? `, ${stats.keptEdited} kézzel szerkesztett (nem írtam felül)` : '') +
    `; ${stats.images} kép párosítva.`);
  if (unmatched.length) {
    log(`\nEzekhez a képekhez nem találtam írást (nevezd át őket az írás címére):`);
    unmatched.forEach((f) => log(`  - ${f}`));
  }
  const noImage = parsed.filter((p) => !imagesByKey.has(p.key));
  if (noImage.length && imageFiles.length) {
    log(`\nKép nélküli írások (${noImage.length}):`);
    noImage.forEach((p) => log(`  - ${p.file}`));
  }
  if (otherFiles.length) {
    log(`\nNem támogatott formátum (mentsd .docx-ként): `);
    otherFiles.forEach((f) => log(`  - ${f}`));
  }
  return { ...stats, unmatched, noImage: noImage.map((p) => p.file), unsupported: otherFiles };
}

// Parancssorból indítva
if (import.meta.url === pathToFileURL(process.argv[1] || '').href) {
  importContent().catch((e) => { console.error(e); process.exit(1); });
}
