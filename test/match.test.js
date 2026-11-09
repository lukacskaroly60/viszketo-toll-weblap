import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import os from 'node:os';
import fs from 'node:fs';

// külön, ideiglenes adatbázissal
process.env.DATA_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'vt-test-'));
const { matchImage } = await import('../src/import.js');

const keys = ['oszi-seta', 'oszi-seta-a-parkban', 'macska-a-padlason', 'tel'];

test('kép párosítása az íráshoz a fájlnév alapján', () => {
  assert.equal(matchImage('Őszi séta.jpg', keys), 'oszi-seta');
  assert.equal(matchImage('oszi_seta_a_parkban_2.png', keys), 'oszi-seta-a-parkban');
  assert.equal(matchImage('kep-macska-a-padlason.jpg', keys), 'macska-a-padlason');
  assert.equal(matchImage('macska.jpg', keys), 'macska-a-padlason');
  assert.equal(matchImage('Tél.jpg', keys), 'tel');
  assert.equal(matchImage('valami.jpg', keys), null);
});
