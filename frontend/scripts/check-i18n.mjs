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
// t('a.b', { from: x, to: y }) — interpolation vars; the capture stops at the
// first '}' so nested objects are out of scope (none of the call sites use them).
const RE_WITH_VARS = /\bt\(\s*'([A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)+)'\s*,\s*\{([^}]*)/g;

const usedHard = new Map();
const usedSoft = new Map();
// key -> Set of variable names the code passes for it (union across call sites).
const usedVars = new Map();

function extractVarNames(body) {
  // Top-level comma split; values may contain commas inside strings, but the
  // pieces without a 'key:' head are not identifiers and are skipped below.
  const names = new Set();
  for (const part of body.split(',')) {
    const m = part.trim().match(/^([A-Za-z_][A-Za-z0-9_]*)\s*:/) || part.trim().match(/^([A-Za-z_][A-Za-z0-9_]*)$/);
    if (m) names.add(m[1]);
  }
  return names;
}

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
    for (const m of src.matchAll(RE_WITH_VARS)) {
      const vars = extractVarNames(m[2]);
      if (vars.size) {
        const acc = usedVars.get(m[1]) || new Set();
        for (const v of vars) acc.add(v);
        usedVars.set(m[1], acc);
      }
    }
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

// 3. placeholder parity between the code and every locale string.
//    t() substitutes ONLY the keys a call passes, so:
//      - a placeholder in the translation the code never passes stays in the
//        UI as a literal "{x}" — the failure class that once shipped a whole
//        tester panel full of {from}/{filter} soup. Failure, except for the
//        allowlist below (texts that deliberately show macro examples).
//      - a var the code passes but the string lacks just drops the value —
//        degraded text, not literal garbage. Warning.
const PLACEHOLDER_ALLOWLIST = {
  // 'key.name': ['subid'],  — placeholder names the text shows on purpose
};
const placeholdersIn = (str) =>
  [...String(str).matchAll(/\{\{(\w+)\}\}|\{(\w+)\}/g)].map((m) => m[1] || m[2]);
const varIssues = [];
const varExtra = [];
for (const [key, vars] of usedVars) {
  const allowed = new Set(PLACEHOLDER_ALLOWLIST[key] || []);
  for (const [name, dict] of Object.entries(locales)) {
    const str = resolve(dict, key);
    if (str === undefined) continue; // rule 1 already covers a missing key
    const have = new Set(placeholdersIn(str));
    const extra = [...have].filter((v) => !vars.has(v) && !allowed.has(v));
    if (extra.length) varIssues.push({ key, name, extra });
    const missing = [...vars].filter((v) => !have.has(v));
    if (missing.length) varExtra.push({ key, name, missing });
  }
}
if (varIssues.length) {
  failures += varIssues.length;
  console.error(`\n✗ ${varIssues.length} locale string(s) carry placeholders the code never passes (they render as literal "{x}"):`);
  for (const { key, name, extra } of varIssues)
    console.error(`    ${key.padEnd(36)} ${name.padEnd(4)} literal {${extra.join('}, {')}}`);
}
if (varExtra.length) {
  console.warn(`\n  ${varExtra.length} locale string(s) miss placeholders the code passes (the value is dropped, not rendered):`);
  for (const { key, name, missing } of varExtra.slice(0, 20))
    console.warn(`    ${key.padEnd(36)} ${name.padEnd(4)} drops {${missing.join('}, {')}}`);
}

if (failures) {
  console.error(`\n✗ i18n check failed.\n`);
  process.exit(1);
}
console.log(`✔ i18n ok — ${usedHard.size} keys used without a fallback, ${base.size} keys per locale, ${names.length} locales in parity.`);
