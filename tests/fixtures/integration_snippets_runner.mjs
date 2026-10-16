// Parity runner: feeds a battery of snippet cases (JSON produced by the PHP
// test) through the REAL panel generator and prints the results as JSON.
//
//   node tests/fixtures/integration_snippets_runner.mjs <cases.json> <module-path>
//
// The module path is passed in (and copied to a .mjs by the test) because
// frontend/src/utils/integrationSnippets.js uses ESM syntax while
// frontend/package.json does not declare "type": "module" — importing the
// .js directly makes Node parse it as CommonJS and fail.
//
// Browser globals the generator touches at call time (cid/obfuscateBase64)
// are shimmed; crypto stays undefined so the deterministic Math.random path
// runs — the PHP test normalizes generated DOM ids before comparing anyway.

import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const [,, casesPath, modulePath] = process.argv;
const cases = JSON.parse(readFileSync(casesPath, 'utf8'));
const mod = await import(pathToFileURL(modulePath).href);

globalThis.window = globalThis.window || {};
if (!globalThis.window.btoa) {
  globalThis.window.btoa = (s) => Buffer.from(s, 'binary').toString('base64');
}
if (globalThis.window.crypto) {
  globalThis.window.crypto = undefined; // force the non-random-free path shape both sides share
}

const ctx = {
  trackerUrl: 'https://track.example.com',
  campaign: {
    id: 42,
    alias: 'mycamp',
    token: 'tok123abc',
    url: 'https://track.example.com/mycamp?utm_source=fb',
  },
};

const out = {};
for (const c of cases) {
  if (c.fn) {
    out[c.name] = mod[c.fn](ctx, c.opts || {});
  } else {
    out[c.name] = mod.buildSnippet(c.method, ctx, c.opts || {});
  }
}

process.stdout.write(JSON.stringify(out));
