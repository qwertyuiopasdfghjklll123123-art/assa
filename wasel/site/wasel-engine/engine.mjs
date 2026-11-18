// محرك واتساب لمنصة واصل (Node.js + Baileys)
// -------------------------------------------------------------------
// هذا الجزء الوحيد اللي ما ينفع يكون PHP: يبقى شغّال دائماً، يمسك جلسات واتساب لكل
// حساب، يعرض رمز QR، يرسل الحملات، ويرد آلياً على الرسائل الواردة. موقع PHP يتكلم معه
// عبر HTTP بمفتاح سري مشترك (ENGINE_SECRET)، والمحرك يبلّغ PHP بالإشعارات وسجل الحملات
// عبر رابط PHP_HOOK_URL بنفس المفتاح.
//
// على عكس النسخة القديمة (سيلينيوم + كروم) هذا يتصل بواتساب مباشرة بدون متصفح، فيستهلك
// ذاكرة قليلة جداً ويشتغل على استضافة DirectAdmin العادية، والرد الآلي صار يعتمد على
// حدث "رسالة جديدة" رسمي بدل مراقبة قائمة المحادثات.

import fs from 'node:fs';
import fsp from 'node:fs/promises';
import http from 'node:http';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import makeWASocket, {
  Browsers,
  DisconnectReason,
  fetchLatestBaileysVersion,
  useMultiFileAuthState,
} from '@whiskeysockets/baileys';
import pino from 'pino';
import QRCode from 'qrcode';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// ---------------------------------------------------------------- الإعدادات
function loadConfig() {
  let fileCfg = {};
  const cfgPath = path.join(__dirname, 'config.json');
  if (fs.existsSync(cfgPath)) {
    try { fileCfg = JSON.parse(fs.readFileSync(cfgPath, 'utf8')); } catch (e) {
      console.error('[إعدادات] تعذرت قراءة config.json:', e.message);
    }
  }
  return {
    secret: process.env.ENGINE_SECRET || fileCfg.ENGINE_SECRET || '',
    hookUrl: process.env.PHP_HOOK_URL || fileCfg.PHP_HOOK_URL || '',
    dataDir: process.env.DATA_DIR || fileCfg.DATA_DIR || path.join(__dirname, 'data'),
    port: Number(process.env.PORT || fileCfg.PORT || 3000),
    tz: process.env.APP_TIMEZONE || fileCfg.TIMEZONE || 'Asia/Baghdad',
  };
}

const CFG = loadConfig();
// نفس توقيت موقع PHP حتى تطابق أوقات الحملات المجدولة والسجل
process.env.TZ = CFG.tz;
if (!CFG.secret || CFG.secret.length < 16) {
  console.error('[تحذير] ENGINE_SECRET غير مضبوط أو قصير جداً - عدّل config.json قبل الاستخدام الفعلي');
}

const SESSIONS_DIR = path.join(CFG.dataDir, 'sessions');
const MEDIA_DIR = path.join(CFG.dataDir, 'media');
const CAMPAIGNS_DIR = path.join(CFG.dataDir, 'campaigns');
const ACCOUNTS_FILE = path.join(CFG.dataDir, 'accounts.json');
for (const d of [CFG.dataDir, SESSIONS_DIR, MEDIA_DIR, CAMPAIGNS_DIR]) fs.mkdirSync(d, { recursive: true });

const logger = pino({ level: 'silent' });
const DEFAULT_DELAY = 15;
const DEFAULT_MEDIA_DELAY = 2;
const ENGINE_VERSION = 10; // ترتفع مع كل تحديث للمحرك - موقع PHP ينبّه لو المحرك المرفوع قديم
const MAX_BODY = 60 * 1024 * 1024; // وسائط الحملات تجي base64 بجسم الطلب

const now = () => new Date();
const fmt = (d) => {
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const digits = (s) => String(s ?? '').replace(/\D/g, '');

// ---------------------------------------------------------------- التواصل مع PHP
async function hook(payload, timeoutMs = 30000) {
  if (!CFG.hookUrl) return null;
  try {
    const res = await fetch(CFG.hookUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Engine-Secret': CFG.secret },
      body: JSON.stringify(payload),
      signal: AbortSignal.timeout(timeoutMs),
    });
    if (!res.ok) {
      console.error(`[PHP] رد ${res.status} على ${payload.type}`);
      return null;
    }
    return await res.json().catch(() => null);
  } catch (e) {
    console.error(`[PHP] تعذر الاتصال (${payload.type}):`, e.message);
    return null;
  }
}

function addEvent(acc, title, body, kind = 'info') {
  if (acc.owner == null) return;
  hook({ type: 'event', owner: acc.owner, account: acc.name, title, body, kind });
}

// ---------------------------------------------------------------- سجل الحسابات (يبقى بعد إعادة التشغيل)
/** id -> {id, owner, name, auto_reply:{enabled, ai_enabled, rules}, otp_sender} */
let registry = {};
try { registry = JSON.parse(fs.readFileSync(ACCOUNTS_FILE, 'utf8')); } catch { registry = {}; }

let saveTimer = null;
function saveRegistry() {
  clearTimeout(saveTimer);
  saveTimer = setTimeout(() => {
    const tmp = ACCOUNTS_FILE + '.tmp';
    fs.writeFileSync(tmp, JSON.stringify(registry, null, 1));
    fs.renameSync(tmp, ACCOUNTS_FILE);
  }, 200);
}

/** id -> حالة تشغيل غير محفوظة: socket، رمز QR، الحالة، قفل الإرسال */
const runtime = new Map();

function rt(id) {
  if (!runtime.has(id)) {
    runtime.set(id, {
      sock: null, status: 'idle', qr: null, starting: false,
      retry: 0, reconnectTimer: null, sendChain: Promise.resolve(), stopped: false,
    });
  }
  return runtime.get(id);
}

// ---------------------------------------------------------------- الاتصال بواتساب
let waVersion = null;
async function getVersion() {
  if (waVersion) return waVersion;
  try {
    const { version } = await fetchLatestBaileysVersion();
    waVersion = version;
  } catch { /* نستخدم النسخة الافتراضية المدمجة بالمكتبة */ }
  return waVersion;
}

function sessionDir(id) { return path.join(SESSIONS_DIR, id.replace(/[^a-zA-Z0-9_-]/g, '')); }

function isRegistered(id) {
  try {
    const creds = JSON.parse(fs.readFileSync(path.join(sessionDir(id), 'creds.json'), 'utf8'));
    return Boolean(creds?.me?.id);
  } catch { return false; }
}

async function startAccount(id) {
  const acc = registry[id];
  if (!acc) return;
  const r = rt(id);
  if (r.starting || (r.sock && r.status !== 'closed' && r.status !== 'idle')) return;
  r.starting = true;
  r.stopped = false;
  clearTimeout(r.reconnectTimer);
  try {
    const { state, saveCreds } = await useMultiFileAuthState(sessionDir(id));
    const version = await getVersion();
    const sock = makeWASocket({
      ...(version ? { version } : {}),
      auth: state,
      logger,
      browser: Browsers.ubuntu('Chrome'),
      markOnlineOnConnect: false,
      syncFullHistory: false,
      generateHighQualityLinkPreview: false,
    });
    r.sock = sock;
    r.status = 'connecting';
    r.qr = null;

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', (u) => {
      if (r.sock !== sock) return; // socket قديم استُبدل
      if (u.qr) {
        r.qr = u.qr;
        r.status = 'qr';
      }
      if (u.connection === 'open') {
        r.status = 'connected';
        r.qr = null;
        r.retry = 0;
        console.log(`[اتصال] ${acc.name} (${id}) متصل`);
        resumePersistedCampaign(id);
      }
      if (u.connection === 'close') {
        const code = u.lastDisconnect?.error?.output?.statusCode;
        r.sock = null;
        r.qr = null;
        if (r.stopped) { r.status = 'idle'; return; }
        if (code === DisconnectReason.loggedOut) {
          // واتساب ألغى ربط الجهاز من الجوال - نمسح الجلسة ونرجع لحالة "يحتاج QR"
          console.log(`[اتصال] ${acc.name} (${id}) سجّل خروج من الجوال`);
          fs.rmSync(sessionDir(id), { recursive: true, force: true });
          r.status = 'idle';
          addEvent(acc, 'انفصل حساب واتساب', 'تم إلغاء ربط الجهاز من الجوال - امسح رمز QR من جديد', 'warning');
          return;
        }
        if (!isRegistered(id)) {
          // انتهت صلاحية رموز QR بدون ما أحد يمسحها - نوقف لحد ما يطلب أحد الرمز من جديد
          // بدل ما نضل نولّد رموز لحساب متروك
          r.status = 'idle';
          return;
        }
        r.status = 'closed';
        r.retry += 1;
        const wait = Math.min(60000, 2000 * r.retry);
        r.reconnectTimer = setTimeout(() => startAccount(id), wait);
      }
    });

    sock.ev.on('messages.upsert', (ev) => {
      if (ev.type !== 'notify') return;
      for (const m of ev.messages) {
        try { onIncomingForTracking(id, m); } catch { /* ignore */ }
        handleIncoming(id, m).catch((e) => console.error('[رد آلي]', e.message));
      }
    });
    sock.ev.on('messages.update', (u) => { try { onStatusUpdate(u); } catch { /* ignore */ } });
    sock.ev.on('message-receipt.update', (u) => { try { onReceipts(u); } catch { /* ignore */ } });
  } catch (e) {
    console.error(`[اتصال] فشل تشغيل ${id}:`, e.message);
    r.status = 'closed';
    r.reconnectTimer = setTimeout(() => startAccount(id), 15000);
  } finally {
    r.starting = false;
  }
}

function ensureStarted(id) {
  const r = rt(id);
  if (!r.sock && !r.starting) startAccount(id);
}

async function stopAccount(id, { logout = false } = {}) {
  const r = rt(id);
  r.stopped = true;
  clearTimeout(r.reconnectTimer);
  const sock = r.sock;
  r.sock = null;
  r.status = 'idle';
  if (sock) {
    try { if (logout) await sock.logout(); } catch { /* ignore */ }
    try { sock.end(undefined); } catch { /* ignore */ }
  }
}

const isConnected = (id) => rt(id).status === 'connected' && rt(id).sock;

// ---------------------------------------------------------------- الإرسال
function mediaContent(media, caption) {
  const mime = media.mime || 'application/octet-stream';
  const buf = fs.readFileSync(media.path);
  if (mime.startsWith('image/') && !mime.includes('gif')) return { image: buf, caption: caption || undefined, mimetype: mime };
  if (mime.startsWith('video/') || mime.includes('gif')) return { video: buf, caption: caption || undefined, mimetype: mime, gifPlayback: mime.includes('gif') };
  if (mime.startsWith('audio/')) return { audio: buf, mimetype: mime, _separateText: true };
  return { document: buf, mimetype: mime, fileName: media.name || 'file', caption: caption || undefined };
}

/** إرسال رسالة (ومعها وسائط اختيارياً) لرقم - عمليات الإرسال لنفس الحساب تمشي بالتسلسل */
function sendTo(id, number, text, media = null, mediaDelay = DEFAULT_MEDIA_DELAY) {
  const r = rt(id);
  const job = r.sendChain.then(async () => {
    const sock = r.sock;
    if (!sock || r.status !== 'connected') throw new Error('الحساب غير متصل بواتساب');
    const phone = digits(number);
    if (phone.length < 8) throw new Error('رقم غير صالح');
    const [res] = (await sock.onWhatsApp(phone)) || [];
    if (!res?.exists) throw new Error('الرقم لا يستخدم واتساب');
    const jid = res.jid;
    let sent;
    if (media && media.path && fs.existsSync(media.path)) {
      const content = mediaContent(media, text);
      const separate = content._separateText;
      delete content._separateText;
      sent = await sock.sendMessage(jid, content);
      if (separate && text) {
        await sleep(Math.max(1, mediaDelay) * 1000);
        await sock.sendMessage(jid, { text });
      }
    } else {
      if (!text || !text.trim()) throw new Error('الرسالة فارغة');
      sent = await sock.sendMessage(jid, { text });
    }
    return { id: sent?.key?.id || null, jid };
  });
  r.sendChain = job.catch(() => {});
  return job;
}

// ---------------------------------------------------------------- الرد الآلي
function messageText(m) {
  const msg = m.message || {};
  const inner = msg.ephemeralMessage?.message || msg.viewOnceMessage?.message || msg;
  return (
    inner.conversation ||
    inner.extendedTextMessage?.text ||
    inner.imageMessage?.caption ||
    inner.videoMessage?.caption ||
    inner.documentMessage?.caption ||
    ''
  ).trim();
}

const lastReply = new Map(); // `${id}|${jid}` -> وقت آخر رد (يمنع حلقات الرد على بوت ثاني)
/** ذاكرة المحادثة للرد الذكي: آخر 12 رسالة لكل محادثة (تنمسح بعد 6 ساعات سكوت) */
const convo = new Map();
function convoGet(key) {
  const c = convo.get(key);
  if (!c || Date.now() - c.t > 6 * 3600 * 1000) return [];
  return c.msgs;
}
function convoPush(key, role, content) {
  const msgs = convoGet(key).concat([{ role, content: String(content).slice(0, 1500) }]).slice(-12);
  convo.set(key, { t: Date.now(), msgs });
  if (convo.size > 5000) convo.delete(convo.keys().next().value);
}

async function handleIncoming(id, m) {
  const acc = registry[id];
  const r = rt(id);
  if (!acc || !r.sock || !m?.key || m.key.fromMe) return;
  const jid = m.key.remoteJid || '';
  if (!jid || jid.endsWith('@g.us') || jid === 'status@broadcast' || jid.endsWith('@newsletter') || jid.endsWith('@broadcast')) return;
  const ar = acc.auto_reply || {};
  if (!ar.enabled) return;
  const text = messageText(m);
  if (!text) return;

  const key = `${id}|${jid}`;
  const last = lastReply.get(key) || 0;
  if (Date.now() - last < 3000) return;

  let reply = null;
  for (const rule of ar.rules || []) {
    if (rule.keyword && text.includes(rule.keyword)) { reply = rule.reply; break; }
  }
  const history = convoGet(key);
  if (!reply && ar.ai_enabled) {
    const res = await hook({ type: 'ai_reply', owner: acc.owner, account_id: id, message: text, history, customer: m.pushName || '' }, 40000);
    reply = res?.reply || null;
  }
  if (!reply) return;
  convoPush(key, 'user', text);
  convoPush(key, 'assistant', reply);
  const charge = await hook({ type: 'charge', owner: acc.owner, note: 'رد آلي' });
  if (!charge || !charge.ok) return;
  lastReply.set(key, Date.now());
  const chatName = m.pushName || digits(m.key.remoteJidAlt || jid.split('@')[0]);
  const job = r.sendChain.then(() => r.sock?.sendMessage(jid, { text: reply }));
  r.sendChain = job.catch(() => {});
  await job;
  addEvent(acc, 'رد تلقائي', `${chatName}: ${reply.slice(0, 60)}`, 'info');
}

// ---------------------------------------------------------------- تتبع الحملات (وصلت / قُرئت / رد)
const TRACK_FILE = path.join(CFG.dataDir, 'tracking.json');
/** campaign_id -> {owner, account_id, started, nums:{digits:1}, delivered:{digits:1}, read:{}, replied:{}} */
let tracking = {};
try { tracking = JSON.parse(fs.readFileSync(TRACK_FILE, 'utf8')); } catch { tracking = {}; }
const msgIndex = new Map(); // معرف الرسالة -> [campaign_id, digits]
for (const [cid, t] of Object.entries(tracking)) for (const [mid, d] of Object.entries(t.msgs || {})) msgIndex.set(mid, [cid, d]);
let trackDirty = new Set();
function trackSave() {
  const cutoff = Date.now() - 10 * 86400 * 1000;
  for (const [cid, t] of Object.entries(tracking)) if (t.started < cutoff) delete tracking[cid];
  try { fs.writeFileSync(TRACK_FILE + '.tmp', JSON.stringify(tracking)); fs.renameSync(TRACK_FILE + '.tmp', TRACK_FILE); } catch { /* ignore */ }
}
/** قوائم حصرية: كل رقم بقائمة وحدة بس (اللي رد ينشال من «شاهد بدون رد» و«ما شاهد») */
function trackStats(cid) {
  const t = tracking[cid];
  const nums = Object.keys(t.nums);
  const lists = {
    replied: nums.filter((d) => t.replied[d]),
    read_only: nums.filter((d) => t.read[d] && !t.replied[d]),
    unread: nums.filter((d) => t.delivered[d] && !t.read[d] && !t.replied[d]),
    pending: nums.filter((d) => !t.delivered[d] && !t.read[d] && !t.replied[d]),
  };
  return { campaign_id: cid, delivered: Object.keys(t.delivered).length, read: Object.keys(t.read).length, replied: Object.keys(t.replied).length, lists };
}
/** الرقم الأصلي اللي انرسل له (واتساب أحياناً يرجع صيغة ثانية للرقم) */
function trackCanon(t, d) {
  if (t.nums[d]) return d;
  return (t.alias && t.alias[d]) || null;
}
function trackMark(cid, kind, raw) {
  const t = tracking[cid];
  const d = t && trackCanon(t, raw);
  if (!d || t[kind][d]) return;
  t[kind][d] = 1;
  if (kind === 'read' || kind === 'replied') t.delivered[d] = 1;
  if (kind === 'replied') t.read[d] = 1;
  trackDirty.add(cid);
}
// نرسل التحديثات لـ PHP كل 20 ثانية (مجمّعة)
setInterval(() => {
  if (!trackDirty.size) return;
  const ids = [...trackDirty]; trackDirty = new Set();
  trackSave();
  for (const cid of ids) if (tracking[cid]) hook({ type: 'history_update', owner: tracking[cid].owner, ...trackStats(cid) });
}, 20000);
function onStatusUpdate(updates) {
  for (const u of updates) {
    const hit = msgIndex.get(u.key?.id);
    if (!hit) continue;
    const st = u.update?.status ?? 0;
    if (st >= 3) trackMark(hit[0], 'delivered', hit[1]);
    if (st >= 4) trackMark(hit[0], 'read', hit[1]);
  }
}
function onReceipts(list) {
  for (const r of list) {
    const hit = msgIndex.get(r.key?.id);
    if (!hit) continue;
    trackMark(hit[0], 'delivered', hit[1]);
    if (r.receipt?.readTimestamp || r.receipt?.playedTimestamp) trackMark(hit[0], 'read', hit[1]);
  }
}
/** رسالة واردة: لو المرسل من أرقام حملة حديثة لنفس الحساب نحسبه «رد» */
function onIncomingForTracking(id, m) {
  if (m.key?.fromMe) return;
  // الحالات (ستوري) والمجموعات والقنوات مو رد على الحملة
  const rj = String(m.key?.remoteJid || '');
  if (!rj || rj === 'status@broadcast' || /@(g\.us|broadcast|newsletter)$/.test(rj)) return;
  const msg = m.message || {};
  if (!Object.keys(msg).length || msg.protocolMessage || msg.senderKeyDistributionMessage && Object.keys(msg).length === 1) return;
  const cands = [m.key?.remoteJid, m.key?.remoteJidAlt, m.key?.participantAlt].filter(Boolean).map((j) => digits(String(j).split('@')[0].split(':')[0]));
  const cutoff = Date.now() - 7 * 86400 * 1000;
  for (const [cid, t] of Object.entries(tracking)) {
    if (t.account_id !== id || t.started < cutoff) continue;
    for (const d of cands) if (trackCanon(t, d)) trackMark(cid, 'replied', d);
  }
}

// ---------------------------------------------------------------- الحملات
/** id -> حالة الحملة (نفس شكل النسخة القديمة حتى الواجهة تشتغل بدون تعديل) */
const campaigns = new Map();

function blankCampaign() {
  return {
    total: 0, sent: 0, failed: 0, running: false, failed_numbers: [], scheduled_for: null,
    pending_numbers: [], stop_requested: false, cancel_requested: false, paused: false,
    text: '', delay: DEFAULT_DELAY, media_path: null, media_mime: null, media_name: null,
    media_delay: DEFAULT_MEDIA_DELAY, started_at: null, campaign_id: null, tag: null, failed_reasons: {},
  };
}

function campaignFile(id) { return path.join(CAMPAIGNS_DIR, `${id.replace(/[^a-zA-Z0-9_-]/g, '')}.json`); }

function getCampaign(id) {
  if (!campaigns.has(id)) {
    let st = blankCampaign();
    try { st = { ...st, ...JSON.parse(fs.readFileSync(campaignFile(id), 'utf8')) }; } catch { /* لا يوجد */ }
    campaigns.set(id, st);
  }
  return campaigns.get(id);
}

function persistCampaign(id) {
  const st = getCampaign(id);
  const { _timer, _loop, ...plain } = st;
  try { fs.writeFileSync(campaignFile(id), JSON.stringify(plain)); } catch { /* ignore */ }
}

function publicCampaign(id) {
  const { _timer, _loop, media_path, ...plain } = getCampaign(id);
  return plain;
}

async function runCampaign(id) {
  const acc = registry[id];
  const st = getCampaign(id);
  if (!acc || st._loop) return;
  st._loop = true;
  st.running = true;
  st.paused = false;
  st.scheduled_for = null;
  st.stop_requested = false;
  st.cancel_requested = false;
  st.started_at = st.started_at || fmt(now());
  if (!st.campaign_id) st.campaign_id = crypto.randomBytes(6).toString('hex');
  if (!tracking[st.campaign_id]) tracking[st.campaign_id] = { owner: acc.owner, account_id: id, started: Date.now(), nums: {}, msgs: {}, delivered: {}, read: {}, replied: {} };
  persistCampaign(id);
  addEvent(acc, 'بدأت حملة جديدة', `جارِ إرسال ${st.pending_numbers.length} رسالة`, 'info');

  const media = st.media_path ? { path: st.media_path, mime: st.media_mime, name: st.media_name } : null;
  try {
    while (!st.stop_requested && st.pending_numbers.length) {
      if (!isConnected(id)) {
        // الحساب انفصل مؤقتاً: ننتظر رجوعه بدل ما نحسب كل الأرقام فاشلة
        await sleep(5000);
        if (!registry[id]) break;
        continue;
      }
      // خصم سعر الرسالة من رصيد صاحب الحساب (لو ما عنده اشتراك) قبل الإرسال
      const charge = await hook({ type: 'charge', owner: acc.owner, note: `حملة: ${acc.name}` });
      if (!charge || !charge.ok) {
        st.stop_requested = true;
        addEvent(acc, 'توقفت الحملة', charge?.error || 'تعذر التحقق من الرصيد - تأكد أن الموقع شغّال', 'warning');
        break;
      }
      const number = st.pending_numbers.shift();
      try {
        const res = await sendTo(id, number, st.text, media, st.media_delay);
        st.sent += 1;
        const t = tracking[st.campaign_id];
        if (t) {
          const d = digits(number); // نخزن كل شي على الرقم الأصلي
          const jd = digits(String(res?.jid || number).split('@')[0]);
          t.nums[d] = 1;
          if (jd && jd !== d) { t.alias = t.alias || {}; t.alias[jd] = d; }
          if (res?.id) { t.msgs[res.id] = d; msgIndex.set(res.id, [st.campaign_id, d]); }
        }
      } catch (e) {
        console.log(`[حملة] فشل الإرسال لـ ${number} (${acc.name}): ${e.message}`);
        st.failed += 1;
        st.failed_numbers.push(number);
        // سبب الفشل: الرقم ما عليه واتساب (ما يفيد نعيد) أو خطأ مؤقت (نعيد المحاولة بالدفعة الجاية)
        st.failed_reasons = st.failed_reasons || {};
        st.failed_reasons[digits(number)] = /لا يستخدم واتساب|غير صالح/.test(e.message) ? 'invalid' : 'error';
        // الرسالة الفاشلة ما تنحسب: نرجع سعرها ومكانها من الحد اليومي
        hook({ type: 'refund', owner: acc.owner, amount: charge.charged || 0, failed: 1 });
      }
      persistCampaign(id);
      if (st.pending_numbers.length && !st.stop_requested) {
        // انتظار الفاصل على شكل خطوات قصيرة حتى يستجيب الإيقاف بسرعة
        const until = Date.now() + Math.max(1, st.delay) * 1000;
        while (Date.now() < until && !st.stop_requested) await sleep(300);
      }
    }
  } finally {
    st._loop = false;
  }
  if (!registry[id]) return;

  st.running = false;
  st.scheduled_for = null;
  const paused = st.stop_requested && !st.cancel_requested && st.pending_numbers.length > 0;
  st.paused = paused;
  if (paused) {
    persistCampaign(id);
    addEvent(acc, 'أُوقفت الحملة مؤقتاً', `نجح ${st.sent} لحد الآن، تبقى ${st.pending_numbers.length} - تكدر تكمّلها لاحقاً`, 'warning');
    return;
  }
  const cancelled = st.cancel_requested;
  trackSave();
  const entry = { time: st.started_at, total: st.total, sent: st.sent, failed: st.failed, text: st.text || (st.media_name ? '📎 ' + st.media_name : ''),
    campaign_id: st.campaign_id, failed_numbers: (st.failed_numbers || []).slice(0, 20000), text_full: st.text || '', media_name: st.media_name || '', delay: st.delay || 0, tag: st.tag || null, failed_reasons: st.failed_reasons || {}, ...(tracking[st.campaign_id] ? trackStats(st.campaign_id) : {}) };
  hook({ type: 'history', owner: acc.owner, account_id: id, account_name: acc.name, entry });
  if (cancelled) {
    const skipped = st.total - st.sent - st.failed;
    addEvent(acc, 'أُلغيت الحملة نهائياً', `نجح ${st.sent}، أُلغي ${skipped} بدون إرسال`, 'warning');
  } else {
    addEvent(acc, 'اكتملت الحملة', `نجح ${st.sent} من ${st.total}، فشل ${st.failed}`, st.failed === 0 ? 'success' : 'warning');
  }
  if (st.media_path) fs.rm(st.media_path, { force: true }, () => {});
  st.started_at = null;
  st.pending_numbers = [];
  st.media_path = null;
  st.campaign_id = null;
  persistCampaign(id);
}

function scheduleCampaign(id) {
  const st = getCampaign(id);
  clearTimeout(st._timer);
  const at = st.scheduled_for ? new Date(st.scheduled_for.replace(' ', 'T')) : null;
  const wait = at ? at.getTime() - Date.now() : 0;
  if (wait > 0) {
    // setTimeout يتحمل حد أقصى ~24 يوم - نعيد الجدولة على مراحل للمدد الأطول
    st._timer = setTimeout(() => (wait > 2 ** 31 - 1 ? scheduleCampaign(id) : runCampaign(id)), Math.min(wait, 2 ** 31 - 1));
  } else {
    runCampaign(id);
  }
}

/** بعد إعادة تشغيل المحرك: كمّل الحملات اللي كانت شغالة أو مجدولة (مو الموقوفة يدوياً) */
function resumePersistedCampaign(id) {
  const st = getCampaign(id);
  if (st.running && !st._loop && !st.paused && st.pending_numbers.length) {
    scheduleCampaign(id);
  }
}

// ---------------------------------------------------------------- خادم HTTP
function send(res, status, body, headers = {}) {
  if (Buffer.isBuffer(body)) {
    res.writeHead(status, headers);
    res.end(body);
    return;
  }
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', ...headers });
  res.end(body === undefined ? '' : JSON.stringify(body));
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on('data', (c) => {
      size += c.length;
      if (size > MAX_BODY) { reject(new Error('الطلب كبير جداً')); req.destroy(); return; }
      chunks.push(c);
    });
    req.on('end', () => {
      const raw = Buffer.concat(chunks).toString('utf8');
      if (!raw) return resolve({});
      try { resolve(JSON.parse(raw)); } catch { reject(new Error('JSON غير صالح')); }
    });
    req.on('error', reject);
  });
}

function authOk(req) {
  const got = String(req.headers['x-engine-secret'] || '');
  if (!CFG.secret || got.length !== CFG.secret.length) return false;
  return crypto.timingSafeEqual(Buffer.from(got), Buffer.from(CFG.secret));
}

function accountInfo(id) {
  const a = registry[id];
  const r = rt(id);
  const me = r.sock?.user?.id ? digits(r.sock.user.id.split(':')[0].split('@')[0]) : null;
  return {
    id, owner: a.owner, name: a.name, otp_sender: Boolean(a.otp_sender),
    status: r.status, logged_in: r.status === 'connected', phone: me,
    campaign_running: getCampaign(id).running, campaign_paused: getCampaign(id).paused,
  };
}

function saveMedia(media) {
  if (!media || !media.data) return null;
  const safeName = String(media.name || 'file').replace(/[^\w.\-؀-ۿ]/g, '_').slice(0, 80);
  const p = path.join(MEDIA_DIR, `${crypto.randomUUID()}_${safeName}`);
  fs.writeFileSync(p, Buffer.from(media.data, 'base64'));
  return { path: p, mime: media.mime || 'application/octet-stream', name: media.name || safeName };
}

async function deleteAccount(id) {
  const st = getCampaign(id);
  st.stop_requested = true;
  st.cancel_requested = true;
  clearTimeout(st._timer);
  await stopAccount(id, { logout: true });
  delete registry[id];
  saveRegistry();
  runtime.delete(id);
  campaigns.delete(id);
  if (st.media_path) fs.rm(st.media_path, { force: true }, () => {});
  await fsp.rm(sessionDir(id), { recursive: true, force: true });
  await fsp.rm(campaignFile(id), { force: true });
}

const routes = [
  ['GET', /^\/health$/, async () => [200, { ok: true, version: ENGINE_VERSION, accounts: Object.keys(registry).length, time: fmt(now()) }]],

  ['GET', /^\/accounts$/, async () => [200, Object.keys(registry).map(accountInfo)]],

  ['POST', /^\/accounts$/, async (_m, body) => {
    const id = /^[a-zA-Z0-9_-]{4,40}$/.test(body.id || '') ? body.id : crypto.randomBytes(4).toString('hex');
    if (!registry[id]) {
      registry[id] = {
        id,
        owner: body.owner ?? null,
        name: String(body.name || 'حساب').slice(0, 60),
        auto_reply: body.auto_reply || { enabled: false, ai_enabled: false, rules: [] },
        otp_sender: Boolean(body.otp_sender),
      };
      saveRegistry();
    }
    ensureStarted(id);
    return [200, { ok: true, id }];
  }],

  ['POST', /^\/accounts\/([\w-]+)\/config$/, async (m, body) => {
    const a = registry[m[1]];
    if (!a) return [404, { ok: false, error: 'حساب غير موجود' }];
    if ('owner' in body) a.owner = body.owner;
    if ('name' in body) a.name = String(body.name || a.name).slice(0, 60);
    if ('otp_sender' in body) {
      if (body.otp_sender) for (const x of Object.values(registry)) x.otp_sender = false;
      a.otp_sender = Boolean(body.otp_sender);
    }
    if (body.auto_reply) {
      a.auto_reply = {
        enabled: Boolean(body.auto_reply.enabled),
        ai_enabled: Boolean(body.auto_reply.ai_enabled),
        rules: (body.auto_reply.rules || [])
          .map((r) => ({ keyword: String(r.keyword || '').trim(), reply: String(r.reply || '').trim() }))
          .filter((r) => r.keyword),
      };
    }
    saveRegistry();
    return [200, { ok: true }];
  }],

  ['DELETE', /^\/accounts\/([\w-]+)$/, async (m) => {
    if (registry[m[1]]) await deleteAccount(m[1]);
    return [200, { ok: true }];
  }],

  ['GET', /^\/accounts\/([\w-]+)\/status$/, async (m) => {
    if (!registry[m[1]]) return [200, { logged_in: false, status: 'missing' }];
    ensureStarted(m[1]);
    return [200, accountInfo(m[1])];
  }],

  ['POST', /^\/accounts\/([\w-]+)\/pair$/, async (m, body) => {
    const id = m[1];
    if (!registry[id]) return [404, { ok: false, error: 'حساب غير موجود' }];
    const phone = digits(body.phone);
    if (phone.length < 10) return [400, { ok: false, error: 'رقم غير صالح' }];
    const r = rt(id);
    if (r.status === 'connected') return [400, { ok: false, error: 'الحساب متصل أصلاً' }];
    if (isRegistered(id)) return [400, { ok: false, error: 'الحساب مربوط - انتظر الاتصال' }];
    ensureStarted(id);
    // ننتظر لحد ما يجهز الاتصال (أول رمز QR يعني جاهز لطلب رمز الربط)
    for (let i = 0; i < 40 && !(r.sock && r.status === 'qr'); i++) await sleep(500);
    if (!r.sock) return [503, { ok: false, error: 'تعذر الاتصال بواتساب، حاول مرة ثانية' }];
    try {
      const code = await r.sock.requestPairingCode(phone);
      const pretty = String(code).replace(/[^A-Z0-9]/gi, '').toUpperCase().replace(/^(.{4})(.{4})$/, '$1-$2');
      return [200, { ok: true, code: pretty }];
    } catch (e) {
      return [502, { ok: false, error: 'تعذر توليد الرمز: ' + e.message }];
    }
  }],

  ['GET', /^\/accounts\/([\w-]+)\/qr$/, async (m) => {
    const id = m[1];
    if (!registry[id]) return [204, undefined];
    const r = rt(id);
    ensureStarted(id);
    if (!r.qr || r.status === 'connected') return [204, undefined];
    const png = await QRCode.toBuffer(r.qr, { type: 'png', width: 300, margin: 1 });
    return [200, png, { 'Content-Type': 'image/png', 'Cache-Control': 'no-store' }];
  }],

  ['POST', /^\/accounts\/([\w-]+)\/send$/, async (m, body) => {
    const id = m[1];
    if (!registry[id]) return [404, { ok: false, error: 'حساب غير موجود' }];
    if (!isConnected(id)) return [400, { ok: false, error: 'الحساب غير متصل بواتساب' }];
    const media = saveMedia(body.media);
    try {
      await sendTo(id, body.phone, String(body.text || ''), media, Number(body.media_delay) || DEFAULT_MEDIA_DELAY);
      return [200, { ok: true }];
    } catch (e) {
      return [502, { ok: false, error: e.message }];
    } finally {
      if (media) fs.rm(media.path, { force: true }, () => {});
    }
  }],

  ['GET', /^\/accounts\/([\w-]+)\/campaign$/, async (m) => {
    if (!registry[m[1]]) return [200, blankCampaign()];
    return [200, publicCampaign(m[1])];
  }],

  ['POST', /^\/accounts\/([\w-]+)\/campaign$/, async (m, body) => {
    const id = m[1];
    if (!registry[id]) return [404, { ok: false, error: 'حساب غير موجود' }];
    if (!isConnected(id)) return [400, { ok: false, error: 'الرقم غير متصل بواتساب - اربطه من قسم أرقامي أولاً' }];
    const st = getCampaign(id);
    if (st.running) return [400, { ok: false, error: 'فيه حملة شغالة أو مجدولة حالياً على هذا الحساب' }];
    const numbers = [...new Set((body.numbers || []).map(digits).filter((n) => n.length >= 8))];
    if (!numbers.length) return [400, { ok: false, error: 'ما لقيت أي رقم صالح' }];
    if (!String(body.text || '').trim() && !body.media?.data) return [400, { ok: false, error: 'اكتب رسالة أو أرفق صورة/ملف' }];
    if (st.media_path) fs.rm(st.media_path, { force: true }, () => {});
    const media = saveMedia(body.media);
    Object.assign(st, blankCampaign(), {
      total: numbers.length,
      pending_numbers: [...numbers],
      running: true,
      text: String(body.text || ''),
      delay: Math.max(1, Number(body.delay) || DEFAULT_DELAY),
      media_delay: Math.max(1, Number(body.media_delay) || DEFAULT_MEDIA_DELAY),
      media_path: media?.path || null,
      media_mime: media?.mime || null,
      media_name: media?.name || null,
      tag: body.tag ? String(body.tag).slice(0, 40) : null,
    });
    const at = body.send_at ? new Date(String(body.send_at)) : null;
    if (at && !Number.isNaN(at.getTime()) && at.getTime() > Date.now()) st.scheduled_for = fmt(at);
    persistCampaign(id);
    scheduleCampaign(id);
    return [200, { ok: true, total: numbers.length, scheduled_for: st.scheduled_for }];
  }],

  ['POST', /^\/accounts\/([\w-]+)\/campaign\/stop$/, async (m) => {
    if (!registry[m[1]]) return [400, { ok: false, error: 'حساب غير موجود' }];
    const st = getCampaign(m[1]);
    st.stop_requested = true;
    if (st.scheduled_for && !st._loop) {
      // حملة مجدولة لسا ما بدأت: الإيقاف المؤقت يحولها لموقوفة قابلة للاستئناف
      clearTimeout(st._timer);
      st.running = false;
      st.paused = true;
      st.scheduled_for = null;
    }
    persistCampaign(m[1]);
    return [200, { ok: true }];
  }],

  ['POST', /^\/accounts\/([\w-]+)\/campaign\/cancel$/, async (m) => {
    if (!registry[m[1]]) return [400, { ok: false, error: 'حساب غير موجود' }];
    const st = getCampaign(m[1]);
    st.stop_requested = true;
    st.cancel_requested = true;
    if (!st._loop) {
      clearTimeout(st._timer);
      if (st.media_path) fs.rm(st.media_path, { force: true }, () => {});
      Object.assign(st, blankCampaign());
    }
    persistCampaign(m[1]);
    return [200, { ok: true }];
  }],

  ['POST', /^\/accounts\/([\w-]+)\/campaign\/resume$/, async (m) => {
    const id = m[1];
    if (!registry[id]) return [400, { ok: false, error: 'حساب غير موجود' }];
    if (!isConnected(id)) return [400, { ok: false, error: 'تأكد من تسجيل الدخول لهذا الحساب أولاً' }];
    const st = getCampaign(id);
    if (st.running) return [400, { ok: false, error: 'فيه حملة شغالة أصلاً على هذا الحساب' }];
    if (!st.paused || !st.pending_numbers.length) return [400, { ok: false, error: 'ما فيه حملة موقوفة مؤقتاً قابلة للاستئناف على هذا الحساب' }];
    const remaining = st.pending_numbers.length;
    st.running = true;
    st.paused = false;
    runCampaign(id);
    return [200, { ok: true, total: remaining }];
  }],

  ['POST', /^\/accounts\/([\w-]+)\/campaign\/exclude$/, async (m, body) => {
    if (!registry[m[1]]) return [400, { ok: false, error: 'حساب غير موجود' }];
    const n = digits(body.number);
    if (!n) return [400, { ok: false, error: 'رقم غير صالح' }];
    const st = getCampaign(m[1]);
    st.pending_numbers = st.pending_numbers.filter((x) => x !== n);
    persistCampaign(m[1]);
    return [200, { ok: true }];
  }],
];

const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url, 'http://x');
    // DirectAdmin قد يشغّل التطبيق تحت مسار فرعي (مثل /engine) - نشيله لو موجود
    let pathname = url.pathname.replace(/\/+$/, '') || '/';
    const base = process.env.BASE_PATH || '';
    if (base && pathname.startsWith(base)) pathname = pathname.slice(base.length) || '/';
    // Setup Node.js App يمرر المسار كامل (مثل /engine/health) - نشيل البادئة تلقائياً
    pathname = pathname.replace(/^(\/[\w.-]+)*\/engine(?=\/|$)/, '') || '/';
    if (pathname === '/' && req.method === 'GET') return send(res, 200, { ok: true, name: 'wasel-engine', version: ENGINE_VERSION });
    if (!authOk(req)) return send(res, 401, { ok: false, error: 'unauthorized' });
    for (const [method, re, fn] of routes) {
      if (method !== req.method) continue;
      const m = pathname.match(re);
      if (!m) continue;
      const body = ['POST', 'PUT'].includes(req.method) ? await readBody(req) : {};
      const [status, payload, headers] = await fn(m, body);
      return send(res, status, payload, headers);
    }
    return send(res, 404, { ok: false, error: 'not found' });
  } catch (e) {
    console.error('[HTTP]', e);
    return send(res, 500, { ok: false, error: e.message });
  }
});

// ---------------------------------------------------------------- الإقلاع
function restoreAccounts() {
  const ids = Object.keys(registry);
  ids.forEach((id, i) => {
    // الحسابات المربوطة مسبقاً ترجع تتصل تلقائياً، بفاصل بسيط حتى ما تنضرب كلها سوا
    if (isRegistered(id)) setTimeout(() => startAccount(id), i * 1500);
  });
  if (ids.length) console.log(`[استعادة] ${ids.length} حساب واتساب`);
}

// نبلّغ PHP كل 10 دقائق حتى تشتغل تذكيرات الاشتراكات حتى لو ما انضبط Cron
setInterval(() => hook({ type: 'tick' }, 120000), 10 * 60 * 1000);

server.listen(CFG.port, () => {
  console.log(`[محرك واصل] يعمل على المنفذ ${CFG.port}`);
  restoreAccounts();
});

process.on('unhandledRejection', (e) => console.error('[unhandledRejection]', e?.message || e));
process.on('uncaughtException', (e) => console.error('[uncaughtException]', e?.message || e));
