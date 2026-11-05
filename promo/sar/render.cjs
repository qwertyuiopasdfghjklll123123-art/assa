// Renders reel.html frame by frame (1080x1920). Usage: node render.cjs <outDir> [fps] [comma,separated,test,times]
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs');
(async () => {
  const out = process.argv[2] || 'frames', fps = Number(process.argv[3] || 60);
  const only = process.argv[4] ? process.argv[4].split(',').map(Number) : null;
  fs.mkdirSync(out, { recursive: true });
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1080, height: 1920 }, deviceScaleFactor: 1 });
  p.on('pageerror', (e) => console.error('[pageerror]', e.message));
  await p.goto('file://' + path.join(__dirname, 'reel.html'), { waitUntil: 'networkidle' });
  await p.waitForFunction(() => window.__ready, null, { timeout: 60000 });
  const dur = await p.evaluate(() => window.DURATION);
  const times = only || Array.from({ length: Math.round(dur * fps) }, (_, i) => i / fps);
  for (let i = Number(process.env.START || 0); i < times.length; i++) {  // START lets an interrupted render resume
    await p.evaluate((t) => window.seek(t), times[i]);
    await p.screenshot({ path: path.join(out, only ? `t_${times[i]}.png` : `f_${String(i).padStart(5, '0')}.png`) });
    if (!only && i % 120 === 0) console.log('frame', i, '/', times.length);
  }
  await b.close();
})();
