// Írások és képek importálása a tartalom/ mappából.
//
//   tartalom/irasok/proza/   – prózai írások (.docx, .txt, .md)
//   tartalom/irasok/versek/  – versek
//   tartalom/irasok/         – (közvetlenül ide tett fájl prózának számít)
//   tartalom/kepek/          – illusztrációk (.jpg, .png, .webp, .gif)
//   tartalom/tartalom.json   – (nem kötelező) pontos lista: cím, dátum, kategória,
//                              látható-e, mely képek tartoznak hozzá
//
// Ha nincs tartalom.json, a program a fájlnevekből dolgozik: a cím a fájl neve,
// a kép pedig a fájlneve alapján kerül az írás mellé (lásd matchImage).
//
// Futtatás:  npm run import
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import mammoth from 'mammoth';
import { db, tx } from './db.js';
import { WRITINGS_DIR, IMAGES_DIR, CONTENT_DIR } from './config.js';
import { parseFileName, matchKey, slugify, htmlToMarkup, poemHtmlToMarkup } from './text.js';

const TEXT_EXT = new Set(['.docx', '.txt', '.md']);
const IMAGE_EXT = new Set(['.jpg', '.jpeg', '.png', '.webp', '.gif']);
const MANIFEST = path.join(CONTENT_DIR, 'tartalom.json');

/** Almappa neve -> kategória */
export const CATEGORY_DIRS = { proza: 'proza', 'próza': 'proza', versek: 'vers', vers: 'vers' };

function listFiles(dir, exts) {
  if (!fs.existsSync(dir)) return [];
  return fs.readdirSync(dir, { withFileTypes: true })
    .filter((d) => d.isFile() && !/^(\.|~\$|OLVASS_EL\.|README\.)/i.test(d.name))
    .map((d) => d.name)
    .filter((n) => !exts || exts.has(path.extname(n).toLowerCase()))
    .sort((a, b) => a.localeCompare(b, 'hu'));
}

/** Az írások listája a mappákból (relatív útvonal + kategória). */
function scanWritings() {
  const out = [];
  for (const f of listFiles(WRITINGS_DIR, TEXT_EXT)) out.push({ file: f, category: 'proza' });
  if (fs.existsSync(WRITINGS_DIR)) {
    for (const d of fs.readdirSync(WRITINGS_DIR, { withFileTypes: true })) {
      const cat = d.isDirectory() && CATEGORY_DIRS[d.name.toLowerCase()];
      if (!cat) continue;
      for (const f of listFiles(path.join(WRITINGS_DIR, d.name), TEXT_EXT)) out.push({ file: `${d.name}/${f}`, category: cat });
    }
  }
  return out;
}

async function readText(file, category) {
  const full = path.join(WRITINGS_DIR, file);
  const ext = path.extname(file).toLowerCase();
  if (ext === '.docx') {
    if (category === 'vers') {
      const { value } = await mammoth.convertToHtml({ path: full }, { ignoreEmptyParagraphs: false });
      return poemHtmlToMarkup(value);
    }
    const { value } = await mammoth.convertToHtml({ path: full });
    return htmlToMarkup(value);
  }
  return fs.readFileSync(full, 'utf8').replace(/^﻿/, '').replace(/\r\n?/g, '\n').trim();
}

async function readWriting(entry) {
  const full = path.join(WRITINGS_DIR, entry.file);
  let { title, date } = parseFileName(path.basename(entry.file));
  let body = await readText(entry.file, entry.category);

  if (path.extname(entry.file).toLowerCase() === '.md') {
    const h = body.match(/^#\s+(.+)\n+/);
    if (h) { title = h[1].trim(); body = body.slice(h[0].length); }
  }
  if (entry.title) title = entry.title;
  if (entry.date) date = entry.date;

  // Ha a szöveg első bekezdése maga a cím, ne jelenjen meg kétszer.
  const sep = entry.category === 'vers' ? /\n/ : /\n\s*\n/;
  const [first, ...rest] = body.split(sep);
  const firstIsTitle = first && slugify(first.replace(/\*/g, '')) === slugify(title);
  if (entry.dropFirst ?? firstIsTitle) body = rest.join(entry.category === 'vers' ? '\n' : '\n\n').trim();

  if (!date) date = fs.statSync(full).mtime.toISOString().slice(0, 10);
  return { title, date, body, category: entry.category || 'proza', hidden: entry.hidden ? 1 : 0 };
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
  insert: db.prepare(`INSERT INTO writings (slug, title, body, published_at, source_file, category, hidden)
    VALUES (?, ?, ?, ?, ?, ?, ?)`),
  update: db.prepare('UPDATE writings SET title = ?, body = ?, published_at = ?, category = ?, hidden = ? WHERE id = ?'),
  clearImported: db.prepare(`DELETE FROM images WHERE writing_id = ? AND path LIKE 'kepek/%'`),
  addImage: db.prepare('INSERT OR IGNORE INTO images (writing_id, path, position) VALUES (?, ?, ?)'),
};

export function uniqueSlug(base) {
  let slug = base, i = 2;
  while (q.slugTaken.get(slug)) slug = `${base}-${i++}`;
  return slug;
}

export async function importContent({ log = console.log } = {}) {
  const useManifest = fs.existsSync(MANIFEST);
  const entries = useManifest ? JSON.parse(fs.readFileSync(MANIFEST, 'utf8')).irasok : scanWritings();
  const imageFiles = listFiles(IMAGES_DIR, IMAGE_EXT);
  const imageSet = new Set(imageFiles);

  const parsed = [];
  for (const entry of entries) {
    if (!fs.existsSync(path.join(WRITINGS_DIR, entry.file))) { log(`  ! Hiányzó fájl: ${entry.file}`); continue; }
    try {
      parsed.push({ ...entry, key: matchKey(entry.title || path.basename(entry.file)), ...(await readWriting(entry)) });
    } catch (e) {
      log(`  ! Nem sikerült beolvasni: ${entry.file} (${e.message})`);
    }
  }

  // Képek: a tartalom.json-ban megadottak, a többi a fájlnév alapján
  const imagesFor = new Map(parsed.map((p) => [p, []]));
  const used = new Set();
  for (const p of parsed) {
    for (const img of p.images || []) {
      if (imageSet.has(img)) { imagesFor.get(p).push(img); used.add(img); } else log(`  ! Hiányzó kép: ${img} (${p.file})`);
    }
  }
  if (!useManifest) {
    const byKey = new Map(parsed.map((p) => [p.key, p]));
    for (const img of imageFiles) {
      const k = matchImage(img, [...byKey.keys()]);
      if (k) { imagesFor.get(byKey.get(k)).push(img); used.add(img); }
    }
  }
  const unmatched = imageFiles.filter((f) => !used.has(f));

  const stats = { new: 0, updated: 0, keptEdited: 0, images: 0, visible: 0 };
  tx(() => {
    for (const p of parsed) {
      const existing = q.bySource.get(p.file);
      let id;
      if (!existing) {
        id = Number(q.insert.run(uniqueSlug(slugify(p.title)), p.title, p.body, p.date, p.file, p.category, p.hidden).lastInsertRowid);
        stats.new++;
      } else {
        id = existing.id;
        if (existing.edited) { stats.keptEdited++; continue; } // kézzel szerkesztett: szöveghez, képekhez nem nyúlunk
        q.update.run(p.title, p.body, p.date, p.category, p.hidden, id);
        stats.updated++;
      }
      if (!p.hidden) stats.visible++;
      q.clearImported.run(id);
      const imgs = imagesFor.get(p);
      if (!p.images) imgs.sort((a, b) => a.localeCompare(b, 'hu', { numeric: true }));
      imgs.forEach((img, i) => { q.addImage.run(id, `kepek/${img}`, i); stats.images++; });
    }
  });

  log(`Import kész: ${stats.new} új, ${stats.updated} frissített írás` +
    (stats.keptEdited ? `, ${stats.keptEdited} kézzel szerkesztett (nem írtam felül)` : '') +
    `; ebből ${stats.visible} látható; ${stats.images} kép párosítva.`);
  if (unmatched.length) {
    log(`\nÍrás nélküli képek (${unmatched.length}) – a szerkesztőfelületen a képtárból hozzáadhatók:`);
    unmatched.forEach((f) => log(`  - ${f}`));
  }
  return { ...stats, unmatched };
}

/** A képtár (tartalom/kepek) összes képe – a szerkesztőfelület használja. */
export function listLibraryImages() {
  return listFiles(IMAGES_DIR, IMAGE_EXT);
}

// Parancssorból indítva
if (import.meta.url === pathToFileURL(process.argv[1] || '').href) {
  importContent().catch((e) => { console.error(e); process.exit(1); });
}
