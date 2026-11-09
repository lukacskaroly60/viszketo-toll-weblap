import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/** Az adatbázis és a feltöltött képek helye (tartós tárhely kell neki!). */
export const DATA_DIR = path.resolve(process.env.DATA_DIR || path.join(ROOT, 'data'));
export const UPLOAD_DIR = path.join(DATA_DIR, 'feltoltesek');

/** Az importálandó írások és képek mappái. */
export const CONTENT_DIR = path.resolve(process.env.CONTENT_DIR || path.join(ROOT, 'tartalom'));
export const WRITINGS_DIR = path.join(CONTENT_DIR, 'irasok');
export const IMAGES_DIR = path.join(CONTENT_DIR, 'kepek');

export const PORT = Number(process.env.PORT || 3000);
export const IS_PROD = process.env.NODE_ENV === 'production';
export const SITE_NAME = process.env.SITE_NAME || 'Viszkető Toll';
export const AUTHOR = process.env.AUTHOR || 'Leopold Judit';

export const PAGE_SIZE = 12;
export const COMMENT_MAX = 1000;
export const SESSION_DAYS = 60;
