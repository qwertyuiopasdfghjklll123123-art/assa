// Renders promo.html frame-by-frame to PNGs using Playwright.
// Usage: node render.js <outDir> [fps] [duration] [onlyTimes,comma,separated]
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

(async () => {
  const out = process.argv[2] || 'frames';
  const fps = Number(process.argv[3] || 30);
  const dur = Number(process.argv[4] || 16.5);
  const only = process.argv[5] ? process.argv[5].split(',').map(Number) : null;
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1080, height: 1920 }, deviceScaleFactor: 1 });
  await page.goto('file://' + path.join(__dirname, 'promo.html'));
  await page.evaluate(() => document.fonts.ready);
  const times = only || Array.from({ length: Math.round(fps * dur) }, (_, i) => i / fps);
  for (let i = 0; i < times.length; i++) {
    await page.evaluate((t) => window.seek(t), times[i]);
    const name = only ? `t_${times[i]}.png` : `f_${String(i).padStart(5, '0')}.png`;
    await page.screenshot({ path: path.join(out, name) });
  }
  await browser.close();
})();
