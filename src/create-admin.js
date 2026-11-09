// Szerkesztői (admin) fiók létrehozása vagy egy meglévő fiók előléptetése.
//
//   npm run admin -- judit@example.com "Leopold Judit" titkosjelszo
//
// Ha a fiók már létezik, adminná teszi (és ha megadtál jelszót, azt beállítja).
import { db } from './db.js';
import { hashPassword, normalizeEmail, isValidEmail } from './auth.js';

const [emailArg, name, password] = process.argv.slice(2);
const email = normalizeEmail(emailArg);

if (!isValidEmail(email)) {
  console.error('Használat: npm run admin -- <email> "<név>" <jelszó>');
  process.exit(1);
}

const existing = db.prepare('SELECT id FROM users WHERE email = ?').get(email);
if (existing) {
  db.prepare(`UPDATE users SET role = 'admin' WHERE id = ?`).run(existing.id);
  if (password) db.prepare('UPDATE users SET password_hash = ? WHERE id = ?').run(hashPassword(password), existing.id);
  if (name) db.prepare('UPDATE users SET name = ? WHERE id = ?').run(name, existing.id);
  console.log(`${email} mostantól szerkesztő (admin).`);
} else {
  if (!name || !password) {
    console.error('Új fiókhoz név és jelszó is kell: npm run admin -- <email> "<név>" <jelszó>');
    process.exit(1);
  }
  db.prepare(`INSERT INTO users (email, name, password_hash, role) VALUES (?, ?, ?, 'admin')`)
    .run(email, name, hashPassword(password));
  console.log(`Szerkesztői fiók létrehozva: ${email}`);
}
