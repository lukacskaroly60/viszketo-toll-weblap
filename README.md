# Viszkető Toll – Leopold Judit írásai

Ez a weboldal Leopold Judit írásainak ad otthont. Korábban a Facebookon jelentek meg, a *Viszkető Toll* blogon.

**Mit tud az oldal?**

- **Írások illusztrációval.** A képek a fájlnevük alapján kerülnek a megfelelő írás mellé.
- **Lájkolás bejelentkezés nélkül.** A böngészőben egy süti jegyzi meg a kedvelést, így ugyanaz a látogató egy írást csak egyszer lájkolhat. Újabb kattintásra visszavonja.
- **Egyszerű regisztráció.** Név, e-mail-cím (ez a felhasználónév) és egy legalább 6 karakteres jelszó kell hozzá.
- **Hozzászólás csak regisztrált olvasóknak.** Max. 1000 karakter, és **csak Judit jóváhagyása után jelenik meg**. A szerzője addig „Jóváhagyásra vár” jelzéssel látja.
- **Szerkesztői felület Juditnak** (`/admin`):
  - hozzászólások jóváhagyása, elutasítása és törlése (a fejlécben egy szám mutatja, hány vár döntésre),
  - írások szerkesztése és elrejtése, képek cseréje,
  - **új írás közzététele** a böngészőből, képfeltöltéssel,
  - a regisztrált olvasók listája.
- Keresés az írásokban, lapozás, mobilbarát megjelenés és sötét mód.

## Gyors indítás (saját gépen)

Node.js 22.13 vagy újabb kell hozzá (<https://nodejs.org>).

```bash
npm install
```

1. Másold az írásokat a `tartalom/irasok/`, a képeket a `tartalom/kepek/` mappába (lásd lent).
2. Importáld őket:

```bash
npm run import
```

3. Hozd létre Judit szerkesztői fiókját:

```bash
npm run admin -- judit@email.hu "Leopold Judit" egyjelszo
```

4. Indítsd el az oldalt:

```bash
npm start
```

Ezután nyisd meg: <http://localhost:3000>

## Az írások és a képek elhelyezése

```
tartalom/
  irasok/   ← .docx, .txt vagy .md – egy fájl = egy írás
  kepek/    ← .jpg, .png, .webp, .gif
```

- **Cím:** a fájl neve lesz, pl. `Őszi séta a parkban.docx` → „Őszi séta a parkban”.
- **Dátum:** ha a fájlnév dátummal kezdődik (`2019-10-12 Őszi séta a parkban.docx`), az lesz a megjelenés napja. Ha nem, a fájl módosítási dátumát veszi. A dátum később a szerkesztőfelületen is átírható.
- **Formázás:** a Word-fájlokból a bekezdések, a *dőlt* és a **félkövér** szöveg megmarad.
- **Kép párosítása:** a kép neve egyezzen az írás címével, vagy azzal kezdődjön. Az ékezet, a kis- és nagybetű és az írásjel nem számít. Például ezek mind az *Őszi séta a parkban* íráshoz kerülnek:
  - `Őszi séta a parkban.jpg`
  - `oszi_seta_a_parkban_2.png`
  - `kep - Őszi séta a parkban.jpg`

  Egy íráshoz több kép is tartozhat. Az első a szöveg fölé, a többi a szöveg alá kerül.
- Az import végén a program kiírja, **melyik képhez nem talált írást**, és melyik írás maradt kép nélkül. Ezeket érdemes átnevezni, majd újra lefuttatni az `npm run import` parancsot.
- Az importot bármikor újra lehet futtatni. Új fájl hozzáadásakor csak az új írás kerül be. Amit Judit már kézzel szerkesztett az oldalon, azt az import nem írja felül.

## Feltöltés a netre

Az oldalnak szerver kell, mert a lájkokat, a felhasználókat és a hozzászólásokat adatbázisban tárolja. Ezért a GitHub Pages **nem** elég.

Bármilyen tárhely jó, amelyik **Node.js 22**-t futtat, és van **tartós lemeze** az adatbázisnak. Ilyen például egy kis VPS, vagy a Fly.io, a Railway, illetve a Render lemezzel.

- Az adatbázis és a feltöltött képek a `DATA_DIR` mappába kerülnek (alapból `./data`). **Ezt a mappát rendszeresen menteni kell.** Egyetlen SQLite-fájl és a `feltoltesek/` mappa van benne.
- Élesben állítsd be: `NODE_ENV=production`. Ekkor a sütik csak HTTPS-en mennek.

Dockerrel:

```bash
docker build -t viszketo-toll .
```

```bash
docker run -d -p 3000:3000 -v viszketo-adat:/data --name viszketo-toll viszketo-toll
```

```bash
docker exec viszketo-toll npm run admin -- judit@email.hu "Leopold Judit" egyjelszo
```

Ha a konténer üres adatbázissal indul, magától importálja a `tartalom/` mappát.

### Beállítások (környezeti változók)

| Változó | Alapérték | Mire való |
|---|---|---|
| `PORT` | `3000` | Ezen a porton fut az oldal |
| `DATA_DIR` | `./data` | Az adatbázis és a feltöltött képek helye |
| `CONTENT_DIR` | `./tartalom` | Az importálandó írások és képek helye |
| `NODE_ENV` | – | Élesben: `production` |
| `SITE_NAME` | `Viszkető Toll` | Az oldal neve |
| `AUTHOR` | `Leopold Judit` | A szerző neve |

## Fejlesztőknek

- `npm run dev`: újraindul, ha változik a kód.
- `npm test`: egységtesztek.
- Felépítés: Express, a beépített `node:sqlite`, a Word-fájlokhoz `mammoth`, a képfeltöltéshez `multer`. Nincs build-lépés, a HTML-t a szerver állítja elő (`src/views.js`).
- Biztonság:
  - A jelszavak scrypt-hash-sel tárolódnak.
  - A munkamenet-süti `HttpOnly` és `SameSite=Lax`, és a szerver minden POST-nál ellenőrzi, hogy a kérés a saját oldalról jön-e.
  - A beküldött szövegek escapelve jelennek meg.
  - A belépés, a regisztráció, a lájk és a hozzászólás sebességkorlátos.
