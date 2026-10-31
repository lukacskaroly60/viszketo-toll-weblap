#!/usr/bin/env python3
"""Facebook-exportból (JSON) statikus blogot készít.

Használat:
    python3 build.py <export_mappa_vagy_zip> [<további zip> ...] [-o kimenet] [--cim "Viszkető toll"]

A kimenet (alapból: ./site) mappa tartalmát kell feltölteni a tárhelyre.
Csak a Python 3 standard könyvtárát használja, semmit nem kell telepíteni.
"""
import argparse
import datetime as dt
import html
import json
import os
import re
import shutil
import sys
import tempfile
import zipfile
from pathlib import Path

PER_PAGE = 20
MONTHS = ["január", "február", "március", "április", "május", "június", "július",
          "augusztus", "szeptember", "október", "november", "december"]


def fix(s):
    """A Facebook export a UTF-8 szöveget latin-1 escape-ként tárolja; ezt javítjuk."""
    if not isinstance(s, str):
        return s
    try:
        return s.encode("latin-1").decode("utf-8")
    except (UnicodeEncodeError, UnicodeDecodeError):
        return s


def fix_all(o):
    if isinstance(o, dict):
        return {fix(k): fix_all(v) for k, v in o.items()}
    if isinstance(o, list):
        return [fix_all(v) for v in o]
    return fix(o)


def hu_date(ts):
    d = dt.datetime.fromtimestamp(ts)
    return f"{d.year}. {MONTHS[d.month - 1]} {d.day}. {d:%H:%M}"


def prepare_inputs(inputs):
    """ZIP-eket kicsomagol egy közös ideiglenes mappába; mappákat változatlanul hagy."""
    roots, tmp = [], None
    for p in map(Path, inputs):
        if p.is_dir():
            roots.append(p.resolve())
        elif zipfile.is_zipfile(p):
            if tmp is None:
                tmp = Path(tempfile.mkdtemp(prefix="fbexport_"))
                roots.append(tmp)
            with zipfile.ZipFile(p) as z:
                for m in z.infolist():
                    target = (tmp / m.filename).resolve()
                    if not str(target).startswith(str(tmp.resolve())):
                        continue  # biztonság: nem írunk a mappán kívülre
                    z.extract(m, tmp)
        else:
            sys.exit(f"Nem található vagy nem ZIP/mappa: {p}")
    return roots, tmp


def iter_post_objects(o):
    """Bármilyen JSON szerkezetben megkeresi a bejegyzésszerű objektumokat."""
    if isinstance(o, list):
        for v in o:
            yield from iter_post_objects(v)
    elif isinstance(o, dict):
        if "timestamp" in o and ("data" in o or "attachments" in o or "title" in o):
            yield o
        else:
            for v in o.values():
                yield from iter_post_objects(v)


def find_media_file(uri, roots):
    if not uri or uri.startswith("http"):
        return None
    for r in roots:
        for base in [r, *[c for c in r.iterdir() if c.is_dir()]]:
            f = base / uri
            if f.is_file():
                return f
    return None


def parse_post(o, roots):
    texts, media, links = [], [], []
    for d in o.get("data", []) or []:
        if isinstance(d, dict):
            if d.get("post"):
                texts.append(d["post"])
            if d.get("external_context", {}).get("url"):
                links.append(d["external_context"]["url"])
    for a in o.get("attachments", []) or []:
        for d in a.get("data", []) or []:
            m = d.get("media")
            if m and m.get("uri"):
                media.append({"uri": m["uri"], "desc": m.get("description") or "",
                              "title": m.get("title") or ""})
                if m.get("description") and not texts:
                    texts.append(m["description"])
            ec = d.get("external_context")
            if ec and ec.get("url"):
                links.append(ec["url"])
            if d.get("text"):
                texts.append(d["text"])
    # Ugyanaz a szöveg ne ismétlődjön a képaláírásokban
    for m in media:
        if m["desc"] in texts:
            m["desc"] = ""
    return {
        "ts": int(o["timestamp"]),
        "title": o.get("title") or "",
        "text": "\n\n".join(dict.fromkeys(texts)),
        "media": media,
        "links": list(dict.fromkeys(links)),
    }


def load_posts(roots):
    posts, seen = [], set()
    files = []
    for r in roots:
        for f in r.rglob("*.json"):
            rel = str(f.relative_to(r)).lower()
            if "post" in rel or "album" in rel or "photo" in rel or "video" in rel:
                files.append(f)
    for f in sorted(files):
        try:
            data = fix_all(json.loads(f.read_text(encoding="utf-8")))
        except (json.JSONDecodeError, UnicodeDecodeError):
            continue
        for o in iter_post_objects(data):
            p = parse_post(o, roots)
            if not (p["text"] or p["media"] or p["links"]):
                continue
            key = (p["ts"], p["text"][:200], tuple(m["uri"] for m in p["media"]))
            if key in seen:
                continue
            seen.add(key)
            posts.append(p)
    # Album-képek, amik egy bejegyzésnél már szerepelnek: töröljük a duplikált, szöveg nélküli darabokat
    used = {m["uri"] for p in posts if p["text"] for m in p["media"]}
    posts = [p for p in posts if p["text"] or p["links"] or not all(m["uri"] in used for m in p["media"])]
    posts.sort(key=lambda p: p["ts"], reverse=True)
    for i, p in enumerate(posts):
        d = dt.datetime.fromtimestamp(p["ts"])
        p["slug"] = f"{d:%Y-%m-%d-%H%M%S}-{i}"
    return posts


def render_text(t):
    esc = html.escape(t)
    esc = re.sub(r"(https?://[^\s<]+)", r'<a href="\1" rel="noopener">\1</a>', esc)
    paras = [p.replace("\n", "<br>") for p in re.split(r"\n\s*\n", esc) if p.strip()]
    return "".join(f"<p>{p}</p>" for p in paras)


CSS = """
:root{--bg:#faf7f2;--fg:#2b2622;--muted:#7a6f66;--accent:#9b3d2e;--card:#fff;--line:#e6dfd6}
@media (prefers-color-scheme:dark){:root{--bg:#1d1a18;--fg:#ece6df;--muted:#a59a90;--accent:#e08a74;--card:#26221f;--line:#3a342f}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font:18px/1.65 Georgia,"Times New Roman",serif}
a{color:var(--accent)}
header.site{text-align:center;padding:2.5rem 1rem 1.5rem;border-bottom:1px solid var(--line)}
header.site h1{margin:0;font-size:2.4rem;letter-spacing:.02em}
header.site h1 a{color:inherit;text-decoration:none}
header.site p{margin:.3rem 0 0;color:var(--muted);font-style:italic}
nav.years{text-align:center;padding:.8rem 1rem;font:15px/1.8 system-ui,sans-serif}
nav.years a{margin:0 .4rem;white-space:nowrap}
main{max-width:720px;margin:0 auto;padding:1rem 16px 3rem}
article{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:1.4rem 1.6rem;margin:1.4rem 0}
article time{display:block;color:var(--muted);font:14px system-ui,sans-serif;margin-bottom:.6rem}
article time a{color:inherit;text-decoration:none}
article h2{font-size:1.1rem;margin:.2rem 0 .8rem;color:var(--muted);font-weight:normal}
.media{display:grid;gap:8px;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));margin-top:1rem}
.media.one{grid-template-columns:1fr}
.media img,.media video{width:100%;height:auto;border-radius:6px;display:block}
figure{margin:0}figcaption{font-size:14px;color:var(--muted);margin-top:.3rem}
.links{font:14px system-ui,sans-serif;word-break:break-all}
.pager{display:flex;justify-content:space-between;font:15px system-ui,sans-serif;margin-top:2rem}
footer{text-align:center;color:var(--muted);font:13px system-ui,sans-serif;padding:2rem 1rem}
"""


def media_html(p, prefix):
    if not p["media"]:
        return ""
    items = []
    for m in p["media"]:
        src = prefix + m["local"] if m.get("local") else None
        if not src:
            continue
        cap = f"<figcaption>{html.escape(m['desc'])}</figcaption>" if m["desc"] else ""
        if re.search(r"\.(mp4|mov|webm)$", src, re.I):
            el = f'<video controls preload="metadata" src="{html.escape(src)}"></video>'
        else:
            el = f'<a href="{html.escape(src)}"><img loading="lazy" src="{html.escape(src)}" alt="{html.escape(m["desc"] or m["title"])}"></a>'
        items.append(f"<figure>{el}{cap}</figure>")
    if not items:
        return ""
    cls = "media one" if len(items) == 1 else "media"
    return f'<div class="{cls}">{"".join(items)}</div>'


def post_html(p, prefix, link=True):
    t = hu_date(p["ts"])
    t = f'<a href="{prefix}bejegyzes/{p["slug"]}.html">{t}</a>' if link else t
    links = ""
    if p["links"]:
        links = '<div class="links">' + "<br>".join(
            f'🔗 <a href="{html.escape(u)}" rel="noopener">{html.escape(u)}</a>' for u in p["links"]) + "</div>"
    title = f"<h2>{html.escape(p['title'])}</h2>" if p["title"] and not p["text"] else ""
    return f"<article><time>{t}</time>{title}{render_text(p['text'])}{media_html(p, prefix)}{links}</article>"


def page(title, site_title, tagline, body, prefix, years):
    nav = " ".join(f'<a href="{prefix}ev/{y}.html">{y}</a>' for y in years)
    return f"""<!doctype html>
<html lang="hu"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{html.escape(title)}</title>
<link rel="stylesheet" href="{prefix}style.css"></head>
<body><header class="site"><h1><a href="{prefix}index.html">{html.escape(site_title)}</a></h1>
<p>{html.escape(tagline)}</p></header>
<nav class="years">{nav}</nav>
<main>{body}</main>
<footer>{html.escape(site_title)} · archívum</footer></body></html>"""


def build(posts, roots, out, site_title, tagline):
    if out.exists():
        shutil.rmtree(out)
    (out / "bejegyzes").mkdir(parents=True)
    (out / "ev").mkdir()
    (out / "media").mkdir()
    (out / "style.css").write_text(CSS, encoding="utf-8")

    missing = 0
    for p in posts:
        for m in p["media"]:
            f = find_media_file(m["uri"], roots)
            if f:
                rel = Path("media") / Path(m["uri"]).name
                n = 1
                while (out / rel).exists() and (out / rel).stat().st_size != f.stat().st_size:
                    rel = Path("media") / f"{f.stem}_{n}{f.suffix}"
                    n += 1
                if not (out / rel).exists():
                    shutil.copy2(f, out / rel)
                m["local"] = rel.as_posix()
            else:
                missing += 1

    years = sorted({dt.datetime.fromtimestamp(p["ts"]).year for p in posts}, reverse=True)

    pages = [posts[i:i + PER_PAGE] for i in range(0, len(posts), PER_PAGE)] or [[]]
    for n, chunk in enumerate(pages, 1):
        body = "".join(post_html(p, "") for p in chunk) or "<p>Nincs bejegyzés.</p>"
        prev_ = f'<a href="{"index.html" if n == 2 else f"oldal-{n - 1}.html"}">← Újabbak</a>' if n > 1 else "<span></span>"
        next_ = f'<a href="oldal-{n + 1}.html">Régebbiek →</a>' if n < len(pages) else "<span></span>"
        body += f'<div class="pager">{prev_}{next_}</div>'
        name = "index.html" if n == 1 else f"oldal-{n}.html"
        (out / name).write_text(page(site_title, site_title, tagline, body, "", years), encoding="utf-8")

    for y in years:
        ps = [p for p in posts if dt.datetime.fromtimestamp(p["ts"]).year == y]
        body = f"<h2>{y} ({len(ps)} bejegyzés)</h2>" + "".join(post_html(p, "../") for p in ps)
        (out / "ev" / f"{y}.html").write_text(page(f"{y} – {site_title}", site_title, tagline, body, "../", years), encoding="utf-8")

    for p in posts:
        title = (p["text"].split("\n")[0][:70] or p["title"] or hu_date(p["ts"])) + f" – {site_title}"
        (out / "bejegyzes" / f"{p['slug']}.html").write_text(
            page(title, site_title, tagline, post_html(p, "../", link=False), "../", years), encoding="utf-8")
    return missing


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("inputs", nargs="+", help="Kicsomagolt export mappa vagy a letöltött ZIP fájl(ok)")
    ap.add_argument("-o", "--out", default="site", help="Kimeneti mappa (alap: site)")
    ap.add_argument("--cim", default="Viszkető toll", help="A weblap címe")
    ap.add_argument("--alcim", default="Írások az évek során", help="Alcím a fejlécben")
    a = ap.parse_args()

    roots, tmp = prepare_inputs(a.inputs)
    try:
        posts = load_posts(roots)
        if not posts:
            sys.exit("Nem találtam bejegyzéseket. JSON formátumú exportot adtál meg?")
        missing = build(posts, roots, Path(a.out), a.cim, a.alcim)
    finally:
        if tmp:
            shutil.rmtree(tmp, ignore_errors=True)
    imgs = sum(len(p["media"]) for p in posts)
    print(f"Kész: {len(posts)} bejegyzés, {imgs} kép/videó → {a.out}/")
    if missing:
        print(f"Figyelem: {missing} médiafájl hiányzott az exportból.")


if __name__ == "__main__":
    main()
