/*
 * سحب نتائج خرائط Google من داخل الصفحة نفسها (مشترك بين إضافة كروم ومتصفح السيرفر)
 * يعرّف window.waselScrape(opts) ويرسل التقدّم عبر window.__waselReport(data) لو موجودة.
 * opts: { max: عدد النتائج الأقصى, deep: يفتح كل محل ما ظهر رقمه بالقائمة حتى يجيب رقمه وعنوانه }
 */
(function () {
  if (window.waselScrape) return;
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const jitter = (a, b) => sleep(a + Math.random() * (b - a));
  const txt = (el) => (el ? (el.innerText || el.textContent || '').trim() : '');
  const report = (d) => { try { window.__waselReport && window.__waselReport(d); } catch (e) { /* تجاهل */ } };
  const PHONE_RE = /(?:\+|00)?\d[\d\s\-()]{6,}\d/;
  const END_RE = /(نهاية القائمة|end of the list|reached the end)/i;

  function blocked() {
    return location.pathname.startsWith('/sorry') || !!document.querySelector('iframe[src*="recaptcha"], form#captcha-form');
  }
  function cleanPhone(s) {
    const m = String(s || '').match(PHONE_RE);
    return m ? m[0].replace(/[^\d+]/g, '') : '';
  }
  function placeKey(href) {
    const m = String(href || '').match(/!1s(0x[0-9a-f]+:0x[0-9a-f]+)/i) || String(href || '').match(/\/place\/([^/]+)/);
    return m ? m[1] : href;
  }

  // بيانات بطاقة بالقائمة (الاسم، التقييم، النوع، العنوان، والرقم لو ظاهر)
  function readCard(a) {
    const card = a.closest('div.Nv2PK') || a.parentElement;
    const name = a.getAttribute('aria-label') || txt(card && card.querySelector('.qBF1Pd, .fontHeadlineSmall'));
    let phone = txt(card && card.querySelector('.UsdlK'));
    const lines = [];
    (card ? card.querySelectorAll('.W4Efsd') : []).forEach((w) => {
      if (w.querySelector('.W4Efsd')) return; // نأخذ الأسطر الداخلية فقط
      const t = txt(w);
      if (t) lines.push(t);
    });
    if (!phone) for (const l of lines) { const p = cleanPhone(l); if (p.replace(/\D/g, '').length >= 7) { phone = p; break; } }
    const parts = (lines[0] || '').split('·').map((s) => s.trim()).filter(Boolean);
    const rating = parseFloat(txt(card && card.querySelector('.MW4etd')).replace(',', '.')) || null;
    const reviews = parseInt(txt(card && card.querySelector('.UY7F9')).replace(/\D/g, ''), 10) || 0;
    return {
      key: placeKey(a.href), name, phone: cleanPhone(phone), rating, reviews,
      category: parts[0] || '', address: parts.slice(1).filter((p) => !PHONE_RE.test(p)).join(' · '),
      website: '', map: a.href,
    };
  }

  // تفاصيل المحل من اللوحة الجانبية بعد الضغط عليه
  function readDetails() {
    const root = document;
    const phoneBtn = root.querySelector('button[data-item-id^="phone:tel:"], a[data-item-id^="phone:tel:"]');
    const phone = phoneBtn ? cleanPhone(phoneBtn.getAttribute('data-item-id').replace('phone:tel:', '')) : '';
    const addrBtn = root.querySelector('button[data-item-id="address"]');
    const address = addrBtn ? (addrBtn.getAttribute('aria-label') || txt(addrBtn)).replace(/^[^:：]*[:：]\s*/, '').trim() : '';
    const web = root.querySelector('a[data-item-id="authority"]');
    const category = txt(root.querySelector('button.DkEaL, [jsaction*="category"]'));
    const name = txt(root.querySelector('h1.DUwDvf, h1'));
    const rating = parseFloat(txt(root.querySelector('div.F7nice span[aria-hidden="true"]')).replace(',', '.')) || null;
    return { name, phone, address, website: web ? web.href : '', category, rating };
  }

  async function waitFor(fn, ms) {
    const end = Date.now() + ms;
    while (Date.now() < end) { const v = fn(); if (v) return v; await sleep(250); }
    return null;
  }

  window.waselScrape = async function (opts) {
    const max = Math.max(1, Math.min(1000, opts && opts.max || 200));
    const deep = !(opts && opts.deep === false);
    const found = new Map();
    window.__waselStop = false;
    await waitFor(() => document.querySelector('div[role="feed"], h1.DUwDvf') || blocked(), 15000);
    if (blocked()) return { ok: false, error: 'captcha', results: [] };

    const feed = document.querySelector('div[role="feed"]');
    // البحث فتح محل واحد مباشرة
    if (!feed) {
      const d = readDetails();
      if (d.name) found.set(location.href, Object.assign({ key: location.href, reviews: 0, map: location.href }, d));
      report({ stage: 'done', found: found.size, results: [...found.values()] });
      return { ok: true, results: [...found.values()] };
    }

    // 1) التمرير بالقائمة لحد ما نوصل العدد أو نهاية القائمة
    let still = 0;
    while (found.size < max && !window.__waselStop) {
      const before = found.size;
      feed.querySelectorAll('a.hfpxzc').forEach((a) => {
        if (found.size >= max) return;
        const c = readCard(a);
        if (c.name && !found.has(c.key)) found.set(c.key, c);
      });
      report({ stage: 'list', found: found.size, current: '' });
      if (END_RE.test(txt(feed)) || document.querySelector('span.HlvSq')) break;
      if (blocked()) return { ok: false, error: 'captcha', results: [...found.values()] };
      feed.scrollTop = feed.scrollHeight;
      await jitter(1400, 2400);
      still = found.size === before ? still + 1 : 0;
      if (still >= 5) break;
    }

    // 2) فتح المحلات اللي ما بيّن رقمها بالقائمة
    if (deep) {
      const list = [...found.values()];
      let i = 0;
      for (const item of list) {
        i++;
        if (window.__waselStop) break;
        const a = [...feed.querySelectorAll('a.hfpxzc')].find((x) => placeKey(x.href) === item.key);
        if (!a) continue;
        a.scrollIntoView({ block: 'center' });
        a.click();
        report({ stage: 'details', found: found.size, done: i, total: list.length, current: item.name });
        const ok = await waitFor(() => { const h = txt(document.querySelector('h1.DUwDvf')); return h && (h === item.name || item.name.startsWith(h) || h.startsWith(item.name)); }, 6000);
        if (blocked()) return { ok: false, error: 'captcha', results: [...found.values()] };
        if (ok) {
          await sleep(500);
          const d = readDetails();
          item.phone = d.phone || item.phone;
          item.address = d.address || item.address;
          item.website = d.website || item.website;
          item.category = d.category || item.category;
          item.rating = d.rating || item.rating;
        }
        await jitter(700, 1500);
      }
    }
    const results = [...found.values()];
    report({ stage: 'done', found: results.length, results });
    return { ok: true, results, stopped: !!window.__waselStop };
  };
})();
