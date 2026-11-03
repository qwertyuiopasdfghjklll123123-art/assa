// Renders film.html (or PAGE=reel.html) frame by frame. Usage: node render.cjs <outDir> [fps] [comma,separated,test,times]
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs');
(async () => {
  const out = process.argv[2] || 'frames', fps = Number(process.argv[3] || 60);
  const only = process.argv[4] ? process.argv[4].split(',').map(Number) : null;
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch();
  const PAGE = process.env.PAGE || 'film.html';
  const [VW, VH] = PAGE.startsWith('reel') ? [1080, 1920] : [1920, 1080];
  const page = await browser.newPage({ viewport: { width: VW, height: VH }, deviceScaleFactor: 1 });
  page.on('pageerror', (e) => console.error('[pageerror]', e.message));
  await page.goto('file://' + path.join(__dirname, PAGE), { waitUntil: 'networkidle' });
  await page.waitForFunction(() => window.__ready, null, { timeout: 60000 });
  const dur = await page.evaluate(() => window.DURATION);
  const times = only || Array.from({ length: Math.round(dur * fps) }, (_, i) => i / fps);
  for (let i = 0; i < times.length; i++) {
    await page.evaluate((t) => window.seek(t), times[i]);
    await page.screenshot({ path: path.join(out, only ? `t_${times[i]}.png` : `f_${String(i).padStart(5, '0')}.png`) });
    if (!only && i % 120 === 0) console.log('frame', i, '/', times.length);
  }
  await browser.close();
})();
