# Viszkető toll weblap

A Viszkető toll Facebook-blog archívuma statikus weblapként. A `build.py` a Facebook
„Adataid letöltése” exportjából (JSON formátum) böngészhető blogot készít: főoldal
lapozással, évenkénti archívum, minden bejegyzésnek saját oldal, képek és videók.

## 1. Az export letöltése (a lap adminjának kell csinálnia)

1. Facebookon váltson át a lap profiljára.
2. **Beállítások és adatvédelem → Beállítások → Fiókközpont → Saját adataid és engedélyeid → Adataid letöltése**.
3. **Adatok letöltése vagy átvitele**, válassza ki a lap profilját.
4. **Összes elérhető adat → Letöltés eszközre**.
5. Időtartomány: **Teljes időszak**, Formátum: **JSON**, Média minősége: **Kiváló** → **Fájlok létrehozása**.
6. Az e-mailben kapott linkről töltse le az összes ZIP-et.

## 2. A weblap elkészítése

Csak Python 3 kell (semmit nem kell telepíteni):

```
python3 build.py facebook-export-1.zip facebook-export-2.zip
```

A ZIP-ek helyett a kicsomagolt mappát is megadhatod. Az eredmény a `site/` mappába kerül.
Megnézheted a gépeden is: nyisd meg a `site/index.html` fájlt a böngészőben.

Cím és alcím módosítása: `python3 build.py export.zip --cim "Viszkető toll" --alcim "Írások az évek során"`

## 3. Feltöltés a tárhelyre (ABPlusz, Total Commander)

1. Total Commanderben: **Hálózat → FTP-kapcsolódás** (Ctrl+F), és kapcsolódj az ABPlusz tárhelyhez.
2. A távoli oldalon lépj be a webgyökérbe (általában `public_html` vagy `www`).
3. A helyi oldalon nyisd meg a `site/` mappát, jelöld ki a **tartalmát** (`index.html`, `style.css`, `bejegyzes`, `ev`, `media`, `oldal-*.html`), és másold át (F5).
4. Nyisd meg a domainedet a böngészőben.

Ha képek nem jelennek meg, ellenőrizd, hogy bináris módban ment-e az átvitel (a Total Commander alapból automatikusan jól választ).

Az exportot (ZIP-eket) őrizd meg biztonsági mentésként is, mert abból a weblap bármikor újra elkészíthető.
