# Viszkető Toll – Leopold Judit írásai

Ez Leopold Judit írásainak weboldala. Az írások korábban a Facebookon, a *Viszkető Toll* blogon jelentek meg. Az oldal **PHP**-ban készült, és egyszerű FTP-feltöltéssel telepíthető egy átlagos tárhelyre, például az ABPluszra.

**Mit tud az oldal?**

- **Írások illusztrációval**, külön **Próza** és **Versek** fülön, kereséssel és lapozással.
- **Lájkolás bejelentkezés nélkül.** Egy böngészőből egy írás egyszer lájkolható, újabb kattintással visszavonható.
- **Egyszerű regisztráció**: név, e-mail-cím (ez a felhasználónév) és egy legalább 6 karakteres jelszó.
- **Hozzászólás csak regisztrált olvasóknak.** A hozzászólás **csak Judit jóváhagyása után jelenik meg**.
- **Szerkesztőfelület Juditnak** (Szerkesztés menü):
  - hozzászólások jóváhagyása és elutasítása (a fejlécben egy szám mutatja, hány vár döntésre),
  - írások szerkesztése, közzététele vagy elrejtése (a rejtett írás piszkozat, az olvasók nem látják),
  - képek hozzárendelése a képtárból vagy feltöltés a gépről,
  - új írás felvétele,
  - a regisztrált olvasók listája,
  - a `tartalom/` mappa importálása.
- Mobilbarát, és van sötét módja is.

## Mi van a mappában?

```
www/                ← EZT kell feltölteni a tárhelyre (a tartalmát a public_html-be)
  index.php           az oldal programja
  .htaccess           szép linkek és a védett mappák tiltása
  app/                a program részei (kívülről nem elérhető)
  static/             megjelenés (CSS), lájk gomb
  data/               az adatbázis kerül ide magától (kívülről nem elérhető)
  feltoltesek/        a szerkesztőfelületen feltöltött képek
  tartalom/
    tartalom.json     az írások listája: cím, dátum, fajta, látható-e, képek
    irasok/proza/     prózai írások (.docx) – kívülről nem olvasható
    irasok/versek/    versek (.docx) – kívülről nem olvasható
    kepek/            illusztrációk (képtár)
tools/dev-router.php  csak saját gépen való kipróbáláshoz kell
```

## Feltöltés az ABPluszra (Total Commanderrel)

**Követelmény:** PHP 8.1 vagy újabb, a `pdo_sqlite`, `zip`, `dom` és `mbstring` bővítményekkel. Ezek az ABPluszon általában alapból be vannak kapcsolva. Ha valami hiányzik, az oldal kiírja, mit kell bekapcsolni (cPanel → *PHP verzió kiválasztása* → *Bővítmények*). MySQL-adatbázist nem kell létrehozni.

1. Total Commanderben: **Hálózat → FTP-kapcsolódás** (Ctrl+F), és kapcsolódj az ABPlusz tárhelyhez.
2. A távoli oldalon lépj be a webgyökérbe (`public_html`).
3. A helyi oldalon nyisd meg a `www` mappát, jelöld ki a **teljes tartalmát**, és másold át (F5).
   - A `.htaccess` fájlok is kellenek! Ha nem látszanak: *Beállítások → Megjelenítés → Rejtett fájlok mutatása*.
4. Nyisd meg a böngészőben: `https://a-te-domained.hu/telepites`. Itt **azonnal** hozd létre Judit szerkesztői fiókját (név, e-mail, jelszó). Ez az oldal csak addig működik, amíg nincs szerkesztő, ezért ne halogasd.
   - Ekkor az oldal magától beolvassa a `tartalom/` mappát (írások, képek).
5. Kész. Judit a fejlécben a **Szerkesztés** menüben éri el a felületét.

**Ha a linkekre „Not Found” hiba jön** (nincs URL-átírás a tárhelyen): az `app/config.php` fájlban írd át a `'szep_url' => true` sort `false`-ra, és töltsd fel újra ezt az egy fájlt.

**HTTPS:** az ABPlusz cPanelben az *SSL/TLS Status* menüben ingyenes tanúsítvány kérhető. Jelszavas belépés miatt érdemes bekapcsolni.

## Frissítés, mentés

- **Kódfrissítéskor** a `data/` és a `feltoltesek/` mappát **ne írd felül és ne töröld**: ezekben vannak az olvasók, a lájkok, a hozzászólások és a feltöltött képek.
- **Mentés:** időnként másold le FTP-vel a `data/` és a `feltoltesek/` mappát a saját gépedre.
- **Új írások fájlból:** a `.docx` fájlt töltsd fel a `tartalom/irasok/proza/` vagy a `tartalom/irasok/versek/` mappába, majd a szerkesztőfelületen nyomd meg az **Importálás** gombot. Ha van `tartalom.json`, az új fájlt abba is fel kell venni. Egyszerűbb, ha az új írást a **„+ Új írás”** menüben veszed fel. Az import nem írja felül azt, amit Judit a felületen már szerkesztett.

## A jelenlegi tartalomról

- A `tartalom.json` alapja Judit Drive-mappája és a *Blog_tartalomjegyzék.docx*:
  - A tartalomjegyzékben szereplő 26 írás 2020-as dátummal **látható**.
  - A többi (vázlatok, változatok, későbbi írások) **rejtett piszkozat**, Judit egy kattintással közzéteheti őket.
- Nem került be:
  - a zárolófájlok (`~$…`) és az üres fájl,
  - Buda Ferenc verse (nem Judit írása),
  - a személyes levelek (*Dear Mr Brandauer*, *Mészáros Saga* levelek, blogmeghívó, *A magány egyik magyarázata*, *A Toll, Ami Még Mindig Ír*),
  - a jegyzetek és vázlatgyűjtemények (*AI kritikáim*, *FELDOLGOZNI*, *VÁZLATOK*, *gyermekdalok*).

## Kipróbálás saját gépen

PHP 8.1+ kell hozzá:

```bash
php -S localhost:8080 -t www tools/dev-router.php
```

Ezután: <http://localhost:8080/telepites>

## Biztonság

- A jelszavak `password_hash`-sel tárolódnak.
- Minden POST-kérés CSRF-tokennel és eredet-ellenőrzéssel védett.
- A munkamenet-süti `HttpOnly` és `SameSite=Lax`.
- A beküldött szövegek escapelve jelennek meg.
- A belépés, a regisztráció, a lájk és a hozzászólás sebességkorlátos.
- A feltöltött fájlokról a szerver ellenőrzi, hogy valóban képek-e, és a képmappákban szkriptet nem lehet futtatni.
- Az `app/`, a `data/`, a `tartalom/irasok/` mappa és a `tartalom.json` kívülről nem érhető el.
