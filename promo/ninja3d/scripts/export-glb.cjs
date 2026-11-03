// Builds the ninja model + baked animation in the browser and saves assets/ninja.glb
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');
const { serve } = require('./serve.cjs');
(async () => {
  const srv = await serve();
  const browser = await chromium.launch({ args: ['--use-angle=swiftshader', '--enable-unsafe-swiftshader'] });
  const page = await browser.newPage();
  page.on('console', (m) => console.log('[page]', m.text()));
  page.on('pageerror', (e) => console.error('[pageerror]', e.message));
  await page.goto(`http://127.0.0.1:${srv.address().port}/build.html`);
  const b64 = await page.waitForFunction(() => window.__glb, null, { timeout: 120000 }).then((h) => h.jsonValue());
  const out = path.join(__dirname, '..', 'assets', 'ninja.glb');
  fs.writeFileSync(out, Buffer.from(b64, 'base64'));
  console.log('wrote', out, fs.statSync(out).size, 'bytes');
  await browser.close(); srv.close();
})();
