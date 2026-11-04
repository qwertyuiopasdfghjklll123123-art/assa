// Renders every .slide (1080x1350) and .story (1080x1920) of carousel.html to PNG.
const { chromium } = require('playwright');
const path = require('path');
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1200, height: 2000 }, deviceScaleFactor: 1 });
  await p.goto('file://' + path.join(__dirname, 'carousel.html'), { waitUntil: 'networkidle' });
  await p.waitForFunction(() => window.__ready);
  const els = await p.$$eval('.slide,.story', (s) => s.map((x) => [x.id, x.className]));
  let n = 0, st = 0;
  for (const [id, cls] of els) {
    const name = cls === 'story' ? `fast_crand-story-${++st}.png` : `fast_crand-slide-${String(++n).padStart(2, '0')}.png`;
    await (await p.$('#' + id)).screenshot({ path: path.join(__dirname, name) });
  }
  await b.close();
})();
