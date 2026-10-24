// One-off: generate photographic popup images via Kie.ai (nano-banana-pro)
// for the Conversion popups plugin. The tracker ships with built-in SVG
// illustrations, so this is OPTIONAL polish: run it, then upload the files
// from ./popup-images/ into the panel's Gallery and pick one in
// Settings → Plugins → Conversion popups → Image.
//
// Usage:  KIE_KEY=... node scripts/popup_images_gen.mjs [--only gambling]
//
// No build tools needed: results are saved exactly as the API returns them
// (the popup scales whatever it gets; square-ish source images look best).
import fs from 'node:fs';
import path from 'node:path';

const KEY = process.env.KIE_KEY;
if (!KEY) { console.error('KIE_KEY env required'); process.exit(1); }
const BASE = 'https://api.kie.ai/api/v1';
const OUT = path.resolve(import.meta.dirname, '..', 'popup-images');
fs.mkdirSync(OUT, { recursive: true });

const onlyIdx = process.argv.indexOf('--only');
const only = onlyIdx > -1 ? process.argv[onlyIdx + 1] : null;

// One prompt per vertical, tuned for a 1:1 popup illustration slot.
// "No text, no letters, no logos" in every prompt — the popup carries its
// own localized copy; baked-in words would collide with 11 languages.
const ITEMS = [
  ['neutral', 'Minimal matte 3D illustration, soft studio lighting, centered composition on a clean light background with generous margins. A neat gift box with a slightly open lid and a warm glow inside, a few small confetti pieces floating; friendly premium look, calm colors (indigo, white, one amber accent). No text, no letters, no logos, no numbers. 1:1.'],
  ['gambling', 'Minimal matte 3D illustration, soft studio lighting, centered on a clean dark-navy background with generous margins. A small stack of glossy casino chips with a couple of golden coins leaning against it and two playing cards fanned behind; premium, celebratory, restrained colors (navy, gold, red accent). No text, no letters, no card faces with symbols, no logos. 1:1.'],
  ['betting', 'Minimal matte 3D illustration, soft studio lighting, centered on a clean light background with generous margins. A classic soccer ball on a small grass-green podium ring with a slim odds-chart line rising behind it; fresh sporty look (green, white, one yellow accent). No text, no letters, no numbers, no logos. 1:1.'],
  ['nutra', 'Minimal matte 3D illustration, soft studio lighting, centered on a clean light background with generous margins. A white supplement jar with a blank matte label, a single capsule and a green leaf leaning against it; clean pharma-calm palette (white, mint green, soft grey). No text, no letters, no logos. 1:1.'],
  ['ecom', 'Minimal matte 3D illustration, soft studio lighting, centered on a clean light background with generous margins. A small delivery parcel with an open lid, one product box peeking out and a floating discount tag shape (blank); tidy commerce look (sky blue, white, one coral accent). No text, no letters, no numbers, no logos. 1:1.'],
  ['finance', 'Minimal matte 3D illustration, soft studio lighting, centered on a clean light background with generous margins. A neat stack of coins with a slim upward arrow line and a soft bank-card shape leaning behind; trustworthy calm palette (deep blue, gold, white). No text, no letters, no numbers, no real card brands. 1:1.'],
  ['crypto', 'Minimal matte 3D illustration, soft studio lighting, centered on a clean dark background with generous margins. A single large golden coin with a plain geometric "C"-like circuit emblem (generic, not any real currency logo), a faint hexagon pattern behind; modern fintech look (dark navy, gold). No text, no letters, no real cryptocurrency logos. 1:1.'],
];

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function api(pathname, opts = {}) {
  const r = await fetch(BASE + pathname, { ...opts, headers: { Authorization: `Bearer ${KEY}`, 'Content-Type': 'application/json' } });
  const j = await r.json();
  if (j.code === 429) { const e = new Error('rate-limit'); e.rl = true; throw e; }
  if (j.code !== 200) throw new Error(`API ${j.code}: ${j.msg}`);
  return j.data;
}

async function createTask(prompt) {
  for (let a = 0; a < 8; a++) {
    try {
      return await api('/jobs/createTask', {
        method: 'POST',
        body: JSON.stringify({ model: 'nano-banana-pro', input: { prompt, aspectRatio: '1:1', batchSize: 1, responseFormat: 'url' } }),
      });
    } catch (e) {
      if (e.rl) { await sleep(12000); continue; }
      throw e;
    }
  }
  throw new Error('createTask: rate-limited too long');
}

async function waitTask(taskId) {
  const t0 = Date.now();
  while (Date.now() - t0 < 6 * 60_000) {
    await sleep(6000);
    const d = await api(`/jobs/recordInfo?taskId=${taskId}`);
    if (d.successFlag === 1 || d.state === 'success') {
      const rj = JSON.parse(d.resultJson);
      const url = (rj.resultUrls && rj.resultUrls[0]) || rj.resultBaseUrl;
      if (!url) throw new Error('no url in result: ' + String(d.resultJson).slice(0, 200));
      return url;
    }
    if (d.state === 'fail' || d.failCode) throw new Error(`task failed: ${d.failMsg || d.failCode}`);
  }
  throw new Error('timeout 6min');
}

for (const [name, prompt] of ITEMS) {
  if (only && !name.includes(only)) continue;
  const file = path.join(OUT, `popup-${name}.jpg`);
  if (fs.existsSync(file)) { console.log(`${name}: exists, skipping (delete to regen)`); continue; }
  process.stdout.write(`${name}: creating… `);
  try {
    const task = await createTask(prompt);
    const url = await waitTask(task.taskId ?? task.result?.task_id ?? task);
    const buf = Buffer.from(await (await fetch(url)).arrayBuffer());
    fs.writeFileSync(file, buf);
    console.log(`saved popup-images/popup-${name}.jpg (${Math.round(buf.length / 1024)} KB)`);
  } catch (e) {
    console.error('FAILED: ' + e.message);
  }
  await sleep(3000);
}
console.log('done → upload popup-images/*.jpg to the Gallery, then Settings → Plugins → Image');
