import { test } from 'node:test';
import assert from 'node:assert/strict';
import { slugify, parseFileName, matchKey, renderBody, htmlToMarkup } from '../src/text.js';

test('slugify kezeli a magyar ékezeteket', () => {
  assert.equal(slugify('Őszi séta – a parkban!'), 'oszi-seta-a-parkban');
  assert.equal(slugify('Űrhajó és tükörfúrógép'), 'urhajo-es-tukorfurogep');
});

test('fájlnévből cím és dátum', () => {
  assert.deepEqual(parseFileName('2019-05-03 Őszi séta.docx'), { title: 'Őszi séta', date: '2019-05-03' });
  assert.deepEqual(parseFileName('2021_3_5_Tavaszi_levél.md'), { title: 'Tavaszi levél', date: '2021-03-05' });
  assert.deepEqual(parseFileName('Macska.txt'), { title: 'Macska', date: null });
  assert.equal(matchKey('oszi_seta.JPG'), matchKey('2019-05-03 Őszi séta.docx'));
});

test('renderBody escapel és formáz', () => {
  assert.equal(renderBody('a *b* **c**\nd\n\n<x>'), '<p>a <em>b</em> <strong>c</strong><br>\nd</p>\n<p>&lt;x&gt;</p>');
});

test('htmlToMarkup (docx import)', () => {
  assert.equal(htmlToMarkup('<p>Egy <em>dőlt</em> &amp; <strong>vastag</strong></p><p>Kettő</p>'), 'Egy *dőlt* & **vastag**\n\nKettő');
});
