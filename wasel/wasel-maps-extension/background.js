// يفتح خرائط Google بنافذة صغيرة، يشغّل السحب، ويرجع التقدّم والنتائج لصفحة واصل
// حالة المهمة تنحفظ بـ storage.session حتى لو انطفى الـ service worker وانبعث من جديد

const getJob = async () => (await chrome.storage.session.get('job')).job || null;
const setJob = (job) => (job ? chrome.storage.session.set({ job }) : chrome.storage.session.remove('job'));

async function allowedOrigin(origin) {
  const { site } = await chrome.storage.local.get('site');
  if (!site) { await chrome.storage.local.set({ site: origin }); return true; } // أول موقع يستخدمها يصير هو المسموح
  return site === origin;
}
async function toSite(msg) {
  const job = await getJob();
  if (job) chrome.tabs.sendMessage(job.siteTab, { source: 'wasel-ext', job: job.id, ...msg }).catch(() => {});
}
async function finish(msg) {
  const job = await getJob();
  if (!job) return;
  await toSite({ type: 'done', ...msg });
  if (!msg.error && job.winId) chrome.windows.remove(job.winId).catch(() => {});
  await setJob(null);
}

function waitComplete(tabId, ms = 45000) {
  return new Promise((res) => {
    const t = setTimeout(() => { chrome.tabs.onUpdated.removeListener(fn); res(false); }, ms);
    function fn(id, info) { if (id === tabId && info.status === 'complete') { clearTimeout(t); chrome.tabs.onUpdated.removeListener(fn); res(true); } }
    chrome.tabs.onUpdated.addListener(fn);
  });
}

async function run(j) {
  const win = await chrome.windows.create({ url: j.url, type: 'popup', width: 520, height: 760, focused: false });
  const tabId = win.tabs[0].id;
  await setJob({ ...(await getJob()), winId: win.id, tabId });
  await toSite({ type: 'progress', stage: 'open', found: 0 });
  await waitComplete(tabId);
  // صفحة الموافقة على الكوكيز (أوروبا)
  const tab = await chrome.tabs.get(tabId);
  if (/consent\./.test(tab.url || '')) {
    await chrome.scripting.executeScript({ target: { tabId }, func: () => {
      const b = [...document.querySelectorAll('form button, button')].find((x) => /accept|agree|قبول|موافق/i.test(x.innerText + ' ' + (x.getAttribute('aria-label') || '')));
      (b || document.querySelector('form[action*="consent"] button'))?.click();
    } });
    await new Promise((r) => setTimeout(r, 1500));
    await waitComplete(tabId, 20000);
  }
  await chrome.scripting.executeScript({ target: { tabId }, files: ['maps-scraper.js'] });
  // السحب يشتغل داخل الصفحة ويبلّغ بالرسائل (ما ننتظره هنا)
  await chrome.scripting.executeScript({
    target: { tabId },
    args: [{ max: j.max, deep: j.deep }],
    func: (o) => {
      window.__waselReport = (d) => { if (d.stage !== 'done') chrome.runtime.sendMessage({ source: 'wasel-scraper', ...d }); };
      window.waselScrape(o)
        .then((r) => chrome.runtime.sendMessage({ source: 'wasel-scraper', stage: 'final', ...r }))
        .catch((e) => chrome.runtime.sendMessage({ source: 'wasel-scraper', stage: 'final', ok: false, error: String(e && e.message || e), results: [] }));
    },
  });
}

chrome.runtime.onMessage.addListener((msg, sender, reply) => {
  if (msg.source === 'wasel-scraper') {
    if (msg.stage === 'final') {
      const err = msg.ok ? '' : (msg.error === 'captcha' ? 'Google طلبت تحقق «لست روبوت» - حلّه بنافذة الخرائط وأعد البحث' : msg.error);
      finish({ results: msg.results || [], error: err, stopped: !!msg.stopped });
    } else toSite({ type: 'progress', stage: msg.stage, found: msg.found, current: msg.current, done: msg.done, total: msg.total });
    return;
  }
  if (msg.source !== 'wasel-page') return;
  (async () => {
    if (!(await allowedOrigin(msg.origin))) return reply({ ok: false, error: 'الإضافة مربوطة بموقع ثاني - غيّره من أيقونة الإضافة' });
    const job = await getJob();
    if (msg.type === 'ping') return reply({ ok: true, version: chrome.runtime.getManifest().version, busy: !!job });
    if (msg.type === 'stop') {
      if (job?.tabId) await chrome.scripting.executeScript({ target: { tabId: job.tabId }, func: () => { window.__waselStop = true; } }).catch(() => {});
      else await setJob(null);
      return reply({ ok: true });
    }
    if (msg.type === 'start') {
      if (job) {
        // مهمة قديمة نافذتها انسدّت؟ نلغيها
        const alive = job.tabId ? await chrome.tabs.get(job.tabId).then(() => true).catch(() => false) : false;
        if (alive) return reply({ ok: false, error: 'فيه بحث شغال حالياً' });
        await setJob(null);
      }
      const u = String(msg.job?.url || '');
      if (!/^https:\/\/(www\.google\.[a-z.]+|maps\.google\.com)\/maps\//.test(u)) return reply({ ok: false, error: 'رابط غير صالح' });
      await setJob({ id: msg.job.id, siteTab: sender.tab.id });
      reply({ ok: true });
      try { await run({ url: u, max: msg.job.max, deep: msg.job.deep }); }
      catch (e) { finish({ results: [], error: String(e.message || e) }); }
    }
  })();
  return true;
});

// لو المستخدم سدّ نافذة الخرائط أثناء البحث
chrome.windows.onRemoved.addListener(async (winId) => {
  const job = await getJob();
  if (job && job.winId === winId) finish({ results: [], error: 'انسدّت نافذة الخرائط قبل ما يخلص البحث' });
});
