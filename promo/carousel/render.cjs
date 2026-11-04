// Renders each .slide of carousel.html to a 1080x1350 PNG.
const { chromium } = require('playwright');
const path = require('path');
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1200, height: 1400 }, deviceScaleFactor: Number(process.env.DSF || 1) });
  await p.goto('file://' + path.join(__dirname, 'carousel.html'), { waitUntil: 'networkidle' });
  await p.waitForFunction(() => window.__ready);
  const ids = await p.$$eval('.slide', (s) => s.map((x) => x.id));
  for (const [i, id] of ids.entries()) {
    await (await p.$('#' + id)).screenshot({ path: path.join(__dirname, `fast_crand-slide-${String(i + 1).padStart(2, '0')}.png`) });
  }
  await b.close();
})();
