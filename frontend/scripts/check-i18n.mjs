// Verifies the translations against the code, not just against each other.
//
//   npm run check:i18n
//
// Two different failures, and only the second one is what key-parity catches:
//
//   1. A key used in a component that no locale defines. t() falls back to the
//      key itself, so the interface renders "botSettings.showing" verbatim.
//      Parity stays green because every locale is equally wrong — which is
//      exactly how a batch of these shipped once already.
//   2. A key present in some locales but missing from others, which leaves
//      those languages silently falling back to English.
//
// Run it before committing anything that touches src/locales.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'src');
const localesDir = path.join(root, 'locales');

// t('a.b') with no second argument renders the raw key when unresolved;
// t('a.b', 'Fallback') degrades to the fallback, which is survivable.
const RE_NO_FALLBACK = /\bt\(\s*'([A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)+)'\s*\)/g;
const RE_WITH_FALLBACK = /\bt\(\s*'([A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)+)'\s*,/g;

const usedHard = new Map();
const usedSoft = new Map();

function walk(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (entry.name !== 'locales') walk(p);
      continue;
    }
    if (!/\.jsx?$/.test(entry.name)) continue;
    const src = fs.readFileSync(p, 'utf8');
    for (const m of src.matchAll(RE_NO_FALLBACK)) if (!usedHard.has(m[1])) usedHard.set(m[1], entry.name);
    for (const m of src.matchAll(RE_WITH_FALLBACK)) if (!usedSoft.has(m[1])) usedSoft.set(m[1], entry.name);
  }
}
walk(root);

const locales = {};
for (const file of fs.readdirSync(localesDir).filter(f => f.endsWith('.js'))) {
  locales[file.replace('.js', '')] = (await import(pathToFileURL(path.join(localesDir, file)).href)).default;
}

const resolve = (dict, key) => key.split('.').reduce((acc, k) => (acc && typeof acc === 'object' ? acc[k] : undefined), dict);
const flatten = (obj, prefix = '') =>
  Object.entries(obj).flatMap(([k, v]) =>
    v && typeof v === 'object' ? flatten(v, `${prefix}${k}.`) : [`${prefix}${k}`]);

let failures = 0;

// 1. keys the UI would render literally
const raw = [];
for (const [key, file] of usedHard) {
  const missing = Object.entries(locales).filter(([, d]) => resolve(d, key) === undefined).map(([n]) => n);
  if (missing.length) raw.push({ key, file, missing });
}
if (raw.length) {
  failures += raw.length;
  console.error(`\n✗ ${raw.length} key(s) would render as raw text in the UI:`);
  for (const { key, file, missing } of raw.sort((a, b) => a.key.localeCompare(b.key)))
    console.error(`    ${key.padEnd(36)} ${file.padEnd(24)} missing in: ${missing.length === Object.keys(locales).length ? 'all locales' : missing.join(', ')}`);
}

// 2. keys some languages silently miss
const names = Object.keys(locales);
const base = new Set(flatten(locales.en));
const parity = [];
for (const name of names) {
  const set = new Set(flatten(locales[name]));
  const missing = [...base].filter(k => !set.has(k));
  const extra = [...set].filter(k => !base.has(k));
  if (missing.length || extra.length) parity.push({ name, missing, extra });
}
if (parity.length) {
  failures += parity.length;
  console.error('\n✗ locale key sets differ from en:');
  for (const { name, missing, extra } of parity) {
    if (missing.length) console.error(`    ${name}: missing ${missing.length} — ${missing.slice(0, 8).join(', ')}${missing.length > 8 ? ' …' : ''}`);
    if (extra.length) console.error(`    ${name}: extra ${extra.length} — ${extra.slice(0, 8).join(', ')}${extra.length > 8 ? ' …' : ''}`);
  }
}

// Soft references are reported but never fail the run: a fallback string is
// already in the source, so the worst case is an untranslated label.
const soft = [...usedSoft].filter(([key]) =>
  !usedHard.has(key) && Object.values(locales).every(d => resolve(d, key) === undefined));
if (soft.length) {
  console.warn(`\n  ${soft.length} key(s) used only with a fallback and defined nowhere (not fatal):`);
  for (const [key, file] of soft.slice(0, 20)) console.warn(`    ${key.padEnd(36)} ${file}`);
}

if (failures) {
  console.error(`\n✗ i18n check failed.\n`);
  process.exit(1);
}
console.log(`✔ i18n ok — ${usedHard.size} keys used without a fallback, ${base.size} keys per locale, ${names.length} locales in parity.`);
