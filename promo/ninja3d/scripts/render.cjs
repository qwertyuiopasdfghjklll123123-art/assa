// Renders player.html frame-by-frame (with motion blur) to PNGs.
// Usage: node scripts/render.cjs <outDir> [fps] [comma,separated,times for test stills]
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const { serve } = require('./serve.cjs');
(async () => {
  const out = process.argv[2] || 'frames', fps = Number(process.argv[3] || 30);
  const only = process.argv[4] ? process.argv[4].split(',').map(Number) : null;
  fs.mkdirSync(out, { recursive: true });
  const srv = await serve();
  const browser = await chromium.launch({ args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader'] });
  const page = await browser.newPage({ viewport: { width: 1080, height: 1920 } });
  page.on('pageerror', (e) => console.error('[pageerror]', e.message));
  page.on('console', (m) => m.type() === 'error' && console.error('[console]', m.text()));
  await page.goto(`http://127.0.0.1:${srv.address().port}/player.html?capture`);
  await page.waitForFunction(() => window.__ready, null, { timeout: 180000 });
  const dur = await page.evaluate(() => window.DURATION);
  const times = only || Array.from({ length: Math.round(dur * fps) }, (_, i) => i / fps);
  const canvas = await page.$('#out');
  for (let i = 0; i < times.length; i++) {
    await page.evaluate(([t, f]) => window.renderAt(t, undefined, f), [times[i], fps]);
    const name = only ? `t_${times[i]}.png` : `f_${String(i).padStart(5, '0')}.png`;
    await canvas.screenshot({ path: path.join(out, name) });
    if (!only && i % 30 === 0) console.log('frame', i, '/', times.length);
  }
  await browser.close(); srv.close();
})();
