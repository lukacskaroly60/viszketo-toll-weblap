// Szövegkezelő segédfüggvények: escapelés, slug, fájlnév-normalizálás, egyszerű formázás.

export function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/** Ékezetek eltávolítása (ő, ű is). */
export function stripAccents(s) {
  return String(s).normalize('NFD').replace(/[̀-ͯ]/g, '');
}

/** URL-barát azonosító: "Őszi séta a parkban" -> "oszi-seta-a-parkban". */
export function slugify(s) {
  return stripAccents(s)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 80) || 'iras';
}

const DATE_PREFIX = /^\s*(\d{4})[-._ ](\d{1,2})[-._ ](\d{1,2})\.?[\s_-]*/;

/**
 * Fájlnévből cím és (opcionális) dátum.
 * "2019-05-03 Őszi séta.docx" -> { title: "Őszi séta", date: "2019-05-03" }
 */
export function parseFileName(fileName) {
  let base = fileName.replace(/\.[^.]+$/, '');
  let date = null;
  const m = base.match(DATE_PREFIX);
  if (m) {
    date = `${m[1]}-${m[2].padStart(2, '0')}-${m[3].padStart(2, '0')}`;
    base = base.slice(m[0].length);
  }
  const title = base.replace(/[_]+/g, ' ').replace(/\s+/g, ' ').trim();
  return { title: title || fileName, date };
}

/** Összehasonlításhoz használt kulcs (dátum-előtag, ékezet, írásjelek nélkül). */
export function matchKey(fileName) {
  return slugify(parseFileName(fileName).title);
}

/**
 * Az írások szövege egyszerű jelöléssel tárolódik:
 *  - üres sor = új bekezdés, sima sortörés = sortörés
 *  - **félkövér**, *dőlt*
 * Ez HTML-lé alakítja, biztonságosan (minden más escapelve).
 */
export function renderBody(text) {
  const paras = String(text).replace(/\r\n?/g, '\n').split(/\n\s*\n/);
  return paras
    .map((p) => p.trim())
    .filter(Boolean)
    .map((p) => {
      let h = esc(p);
      h = h.replace(/\*\*([^*\n]+?)\*\*/g, '<strong>$1</strong>');
      h = h.replace(/(^|[^*])\*([^*\n]+?)\*(?!\*)/g, '$1<em>$2</em>');
      h = h.replace(/\n/g, '<br>\n');
      return `<p>${h}</p>`;
    })
    .join('\n');
}

/** Rövid kivonat a listaoldalakhoz. */
export function excerpt(text, len = 220) {
  const plain = String(text).replace(/\*+/g, '').replace(/\s+/g, ' ').trim();
  if (plain.length <= len) return plain;
  const cut = plain.slice(0, len);
  return cut.slice(0, cut.lastIndexOf(' ') > 0 ? cut.lastIndexOf(' ') : len) + '…';
}

const HU_MONTHS = ['január', 'február', 'március', 'április', 'május', 'június', 'július',
  'augusztus', 'szeptember', 'október', 'november', 'december'];

export function huDate(iso) {
  const m = String(iso || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!m) return '';
  return `${m[1]}. ${HU_MONTHS[Number(m[2]) - 1]} ${Number(m[3])}.`;
}

export function huDateTime(sqlDate) {
  // SQLite datetime('now') UTC-ben van
  const d = new Date(String(sqlDate).replace(' ', 'T') + 'Z');
  if (Number.isNaN(d.getTime())) return '';
  return d.toLocaleString('hu-HU', { timeZone: 'Europe/Budapest', dateStyle: 'medium', timeStyle: 'short' });
}

/** Mammoth által előállított HTML -> a fenti egyszerű jelölés. */
export function htmlToMarkup(html) {
  return String(html)
    .replace(/<(strong|b)>\s*<\/\1>/g, '')
    .replace(/<(em|i)>\s*<\/\1>/g, '')
    .replace(/<(strong|b)>/g, '**').replace(/<\/(strong|b)>/g, '**')
    .replace(/<(em|i)>/g, '*').replace(/<\/(em|i)>/g, '*')
    .replace(/<br\s*\/?>/g, '\n')
    .replace(/<\/(p|h[1-6]|li|blockquote)>/g, '\n\n')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/g, ' ')
    .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'")
    .replace(/&amp;/g, '&')
    .replace(/[ \t]+\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}
