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
  BufferJSON,
  downloadMediaMessage,
  decryptPollVote,
  jidNormalizedUser,
  Browsers,
  DisconnectReason,
  fetchLatestBaileysVersion,
  useMultiFileAuthState,
} from '@whiskeysockets/baileys';
import pino from 'pino';
import QRCode from 'qrcode';
import { setupInstagram } from './ig.mjs';

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
const ENGINE_VERSION = 15; // ترتفع مع كل تحديث للمحرك - موقع PHP ينبّه لو المحرك المرفوع قديم
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
      for (const m of ev.messages) {
        try { inboxAdd(id, m, { unread: ev.type === 'notify' }); } catch (e) { console.error('[محادثات]', e.message); }
        if (m.message?.pollUpdateMessage && !m.key?.fromMe) onPollVote(id, m).catch((e) => console.error('[استبيان]', e.message));
        else if (ev.type === 'notify') { try { activityIncoming(id, m); } catch { /* ignore */ } }
      }
      if (ev.type !== 'notify') return;
      for (const m of ev.messages) {
        try { onIncomingForTracking(id, m); } catch { /* ignore */ }
        handleIncoming(id, m).catch((e) => console.error('[رد آلي]', e.message));
      }
    });
    sock.ev.on('messages.update', (u) => { try { onStatusUpdate(u); } catch { /* ignore */ } try { inboxStatus(id, u); } catch { /* ignore */ } });
    // سجل المحادثات أول ربط + أسماء جهات الاتصال + تحديثات المحادثات (غير مقروءة...)
    sock.ev.on('messaging-history.set', ({ chats, contacts, messages }) => {
      try { inboxNames(id, contacts); inboxChats(id, chats); for (const m of messages || []) inboxAdd(id, m); } catch (e) { console.error('[محادثات] سجل:', e.message); }
    });
    sock.ev.on('contacts.upsert', (c) => { try { inboxNames(id, c); } catch { /* ignore */ } });
    sock.ev.on('contacts.update', (c) => { try { inboxNames(id, c); } catch { /* ignore */ } });
    sock.ev.on('chats.upsert', (c) => { try { inboxChats(id, c); } catch { /* ignore */ } });
    sock.ev.on('chats.update', (c) => { try { inboxChats(id, c); } catch { /* ignore */ } });
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
    const direct = String(number).includes('@') && isPersonalJid(number);
    const phone = digits(number);
    if (!direct && phone.length < 8) throw new Error('رقم غير صالح');
    const c = direct ? null : waCached(phone);
    let jid = direct ? String(number) : (c?.e ? c.j : null);
    if (!jid) {
      const [res] = (await sock.onWhatsApp(phone)) || [];
      waCache.set(phone, { e: Boolean(res?.exists), j: res?.jid || null, t: Date.now() });
      if (!res?.exists) throw new Error('الرقم لا يستخدم واتساب');
      jid = res.jid;
    }
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

// ---------------------------------------------------------------- المحادثات (صندوق الوارد لكل حساب)
// نحفظ آخر المحادثات الفردية (بدون مجموعات/قنوات/حالات) وآخر رسائل كل محادثة، حتى صاحب الحساب
// يشوفها من الموقع ويرد عليها مباشرة أو يرسل لمجموعة منها دفعة وحدة.
const INBOX_DIR = path.join(CFG.dataDir, 'inbox');
fs.mkdirSync(INBOX_DIR, { recursive: true });
const INBOX_MAX_CHATS = 3000, INBOX_MAX_MSGS = 120;
const inboxes = new Map(); // id -> {chats:{key:{...}}, msgs:{key:[...]}, alias:{lid:pnJid}, names:{jid:name}}
const inboxFile = (id) => path.join(INBOX_DIR, `${id.replace(/[^a-zA-Z0-9_-]/g, '')}.json`);
function inboxOf(id) {
  if (!inboxes.has(id)) {
    let d = { chats: {}, msgs: {}, alias: {}, names: {} };
    try { d = { ...d, ...JSON.parse(fs.readFileSync(inboxFile(id), 'utf8')) }; } catch { /* جديد */ }
    inboxes.set(id, d);
  }
  return inboxes.get(id);
}
const inboxDirty = new Set();
function inboxTouch(id) { inboxDirty.add(id); }
setInterval(() => {
  for (const id of inboxDirty) {
    const d = inboxes.get(id);
    if (!d || !registry[id]) continue;
    // نحدد الحجم: أقدم المحادثات تنحذف لو تجاوزنا الحد
    const keys = Object.keys(d.chats);
    if (keys.length > INBOX_MAX_CHATS) {
      keys.sort((a, b) => (d.chats[b].ts || 0) - (d.chats[a].ts || 0)).slice(INBOX_MAX_CHATS).forEach((k) => { delete d.chats[k]; delete d.msgs[k]; });
    }
    try { fs.writeFileSync(inboxFile(id) + '.tmp', JSON.stringify(d)); fs.renameSync(inboxFile(id) + '.tmp', inboxFile(id)); } catch (e) { console.error('[محادثات] حفظ:', e.message); }
  }
  inboxDirty.clear();
}, 3000);

const isPersonalJid = (j) => /@(s\.whatsapp\.net|lid)$/.test(String(j || ''));
const jidUser = (j) => String(j || '').split('@')[0].split(':')[0];
/** مفتاح المحادثة: نفضّل jid الرقم (@s.whatsapp.net) لو نعرفه، حتى ما تتكرر المحادثة بصيغتين */
function chatKey(d, jid, alt) {
  jid = String(jid || '');
  if (alt && /@s\.whatsapp\.net$/.test(alt) && /@lid$/.test(jid)) d.alias[jid] = alt;
  if (/@lid$/.test(jid) && d.alias[jid]) return d.alias[jid];
  return jid;
}
/** نص مختصر للرسالة + نوعها (للعرض) */
function describeMsg(msg) {
  const inner = msg?.ephemeralMessage?.message || msg?.viewOnceMessage?.message || msg?.viewOnceMessageV2?.message || msg?.documentWithCaptionMessage?.message || msg || {};
  const cap = (x) => (x?.caption || '').trim();
  if (inner.conversation || inner.extendedTextMessage) return { type: 'text', text: (inner.conversation || inner.extendedTextMessage?.text || '').trim() };
  if (inner.imageMessage) return { type: 'image', text: cap(inner.imageMessage), media: 1, thumb: inner.imageMessage.jpegThumbnail };
  if (inner.videoMessage) return { type: 'video', text: cap(inner.videoMessage), media: 1, thumb: inner.videoMessage.jpegThumbnail };
  if (inner.audioMessage) return { type: inner.audioMessage.ptt ? 'voice' : 'audio', text: '', media: 1, secs: inner.audioMessage.seconds || 0 };
  if (inner.documentMessage) return { type: 'document', text: cap(inner.documentMessage), media: 1, file: inner.documentMessage.fileName || 'ملف' };
  if (inner.stickerMessage) return { type: 'sticker', text: '', media: 1 };
  if (inner.locationMessage || inner.liveLocationMessage) { const l = inner.locationMessage || inner.liveLocationMessage; return { type: 'location', text: l.name || '', lat: l.degreesLatitude, lng: l.degreesLongitude }; }
  if (inner.contactMessage || inner.contactsArrayMessage) return { type: 'contact', text: inner.contactMessage?.displayName || 'جهات اتصال' };
  if (inner.pollCreationMessage || inner.pollCreationMessageV3) return { type: 'poll', text: (inner.pollCreationMessage || inner.pollCreationMessageV3).name || '' };
  if (inner.buttonsResponseMessage || inner.listResponseMessage || inner.templateButtonReplyMessage) return { type: 'text', text: inner.buttonsResponseMessage?.selectedDisplayText || inner.listResponseMessage?.title || inner.templateButtonReplyMessage?.selectedDisplayText || '' };
  return null; // بروتوكول/تفاعل/مفاتيح - ما نعرضه
}
const toB64 = (u8) => (u8 ? Buffer.from(u8).toString('base64') : undefined);
const tsOf = (m) => Number(m.messageTimestamp?.low ?? m.messageTimestamp ?? 0) || Math.floor(Date.now() / 1000);

/** يسجل رسالة (واردة أو صادرة) بصندوق الحساب */
function inboxAdd(id, m, { unread = false } = {}) {
  const jid = m?.key?.remoteJid;
  if (!isPersonalJid(jid) || !m.message) return;
  const info = describeMsg(m.message);
  if (!info) {
    // تفاعل (إيموجي) على رسالة: نحفظه على الرسالة نفسها
    const re = m.message.reactionMessage;
    if (re?.key?.id) {
      const d = inboxOf(id), k = chatKey(d, jid, m.key.remoteJidAlt);
      const t = (d.msgs[k] || []).find((x) => x.id === re.key.id);
      if (t) { t.react = re.text || ''; inboxTouch(id); }
    }
    return;
  }
  const d = inboxOf(id);
  const k = chatKey(d, jid, m.key.remoteJidAlt || m.key.senderPn);
  const ts = tsOf(m);
  const list = d.msgs[k] || (d.msgs[k] = []);
  if (list.some((x) => x.id === m.key.id)) return; // مكررة
  const e = { id: m.key.id, me: Boolean(m.key.fromMe), ts, type: info.type, text: String(info.text || '').slice(0, 4000) };
  if (jid !== k) e.rj = jid;
  if (e.me) e.st = Number(m.status) || 1;
  if (info.thumb) e.thumb = toB64(info.thumb);
  if (info.file) e.file = String(info.file).slice(0, 120);
  if (info.secs) e.secs = info.secs;
  if (info.lat != null) { e.lat = info.lat; e.lng = info.lng; }
  if (info.media) { try { e.raw = JSON.stringify({ key: m.key, message: m.message }, BufferJSON.replacer); } catch { /* ignore */ } }
  list.push(e);
  list.sort((a, b) => a.ts - b.ts);
  if (list.length > INBOX_MAX_MSGS) list.splice(0, list.length - INBOX_MAX_MSGS);
  const c = d.chats[k] || (d.chats[k] = { jid: k, name: '', ts: 0, unread: 0, incoming: false });
  if (/@s\.whatsapp\.net$/.test(k)) c.phone = jidUser(k);
  else if (m.key.remoteJidAlt && /@s\.whatsapp\.net$/.test(m.key.remoteJidAlt)) c.phone = jidUser(m.key.remoteJidAlt);
  if (!e.me && m.pushName) { c.name = String(m.pushName).slice(0, 60); d.names[k] = c.name; }
  if (!e.me) c.incoming = true;
  if (ts >= (c.ts || 0)) { c.ts = ts; c.last = { id: e.id, me: e.me, st: e.st, type: e.type, text: e.text.slice(0, 120), file: e.file }; }
  if (unread && !e.me) c.unread = (c.unread || 0) + 1;
  if (e.me) c.unread = 0; // رد من الجوال = انقرت
  inboxTouch(id);
}

/** أسماء جهات الاتصال (من الجوال) */
function inboxNames(id, contacts) {
  const d = inboxOf(id);
  for (const c of contacts || []) {
    const name = c.name || c.notify || c.verifiedName;
    if (!c.id || !name) continue;
    if (c.lid && /@s\.whatsapp\.net$/.test(c.id)) d.alias[c.lid] = c.id;
    const k = chatKey(d, c.id);
    d.names[k] = String(name).slice(0, 60);
    if (d.chats[k] && (!d.chats[k].name || c.name)) d.chats[k].name = d.names[k];
  }
  inboxTouch(id);
}
function inboxChats(id, chats) {
  const d = inboxOf(id);
  for (const ch of chats || []) {
    if (!isPersonalJid(ch.id)) continue;
    const k = chatKey(d, ch.id, ch.pnJid);
    const c = d.chats[k] || (d.chats[k] = { jid: k, name: '', ts: 0, unread: 0, incoming: false });
    if (ch.name) c.name = String(ch.name).slice(0, 60);
    if (typeof ch.unreadCount === 'number') c.unread = Math.max(0, ch.unreadCount);
    const ts = Number(ch.conversationTimestamp?.low ?? ch.conversationTimestamp ?? 0);
    if (ts > (c.ts || 0)) c.ts = ts;
    if (/@s\.whatsapp\.net$/.test(k)) c.phone = jidUser(k);
  }
  inboxTouch(id);
}
function inboxPublicChat(d, c) {
  return { jid: c.jid, name: c.name || d.names[c.jid] || '', phone: c.phone || (/@s\.whatsapp\.net$/.test(c.jid) ? jidUser(c.jid) : ''), ts: c.ts || 0, unread: c.unread || 0, incoming: Boolean(c.incoming), last: c.last || null };
}
function inboxPublicMsg(e) {
  const { raw, ...rest } = e;
  return rest;
}

// ---------------------------------------------------------------- الصور الشخصية (تنحفظ 3 أيام)
const AVATAR_DIR = path.join(CFG.dataDir, 'avatars');
fs.mkdirSync(AVATAR_DIR, { recursive: true });
const avatarBusy = new Map();
async function avatarOf(id, jid) {
  const r = rt(id);
  if (jid === 'me') jid = r.sock?.user?.id ? jidNormalizedUser(r.sock.user.id) : '';
  if (!jid) return null;
  const f = path.join(AVATAR_DIR, crypto.createHash('sha1').update(id + '|' + jid).digest('hex'));
  try {
    const st = fs.statSync(f);
    const age = Date.now() - st.mtimeMs;
    if (st.size === 0 && age < 86400000) return null; // ما عنده صورة (أو مخفية) - نعيد نسأل بعد يوم
    if (st.size > 0 && age < 3 * 86400000) return fs.readFileSync(f);
  } catch { /* مو محفوظة */ }
  if (!isConnected(id)) { try { const b = fs.readFileSync(f); return b.length ? b : null; } catch { return null; } }
  if (avatarBusy.has(f)) return avatarBusy.get(f);
  const job = (async () => {
    try {
      const url = await r.sock.profilePictureUrl(jid, 'preview', 8000);
      if (!url) throw new Error('none');
      const res = await fetch(url, { signal: AbortSignal.timeout(10000) });
      if (!res.ok) throw new Error('http ' + res.status);
      const buf = Buffer.from(await res.arrayBuffer());
      fs.writeFileSync(f, buf);
      return buf;
    } catch {
      fs.writeFileSync(f, '');
      return null;
    } finally { setTimeout(() => avatarBusy.delete(f), 0); }
  })();
  avatarBusy.set(f, job);
  return job;
}

// ---------------------------------------------------------------- نشاط المحادثات (رد / شاف وما رد) -> إشعار لصاحب الحساب
const activity = new Map(); // id -> {replies:[], reads:[]}
function actPush(id, kind, item) {
  const a = activity.get(id) || { replies: [], reads: [] };
  if (a[kind].length < 60) a[kind].push(item);
  activity.set(id, a);
}
setInterval(() => {
  for (const [id, a] of activity) {
    const acc = registry[id];
    if (acc && acc.owner != null && (a.replies.length || a.reads.length)) hook({ type: 'activity', owner: acc.owner, account_id: id, account_name: acc.name, replies: a.replies, reads: a.reads });
  }
  activity.clear();
}, 20000);
/** رسالة واردة بمحادثة فردية: نسجلها كـ«رد» لو إحنا راسلناه آخر 7 أيام */
function activityIncoming(id, m) {
  if (m.key?.fromMe || !isPersonalJid(m.key?.remoteJid) || !m.message) return;
  const info = describeMsg(m.message);
  if (!info) return;
  const d = inboxOf(id), k = chatKey(d, m.key.remoteJid, m.key.remoteJidAlt), c = d.chats[k] || {};
  const since = tsOf(m) - 7 * 86400;
  const ours = (d.msgs[k] || []).some((x) => x.me && x.ts >= since);
  actPush(id, 'replies', { jid: k, name: c.name || m.pushName || '', phone: c.phone || '', text: String(info.text || (IB_LABEL[info.type] || '')).slice(0, 120), reply: ours });
}
const IB_LABEL = { image: '📷 صورة', video: '🎥 فيديو', voice: '🎤 رسالة صوتية', audio: '🎵 صوت', document: '📄 ملف', sticker: 'ملصق', location: '📍 موقع', contact: '👤 جهة اتصال', poll: '📊 استبيان' };
/** حالة رسائلنا: 2 وصلت للسيرفر، 3 وصلت للجوال، 4 قرأها، 5 شغّل الصوت */
function inboxStatus(id, updates) {
  for (const u of updates) {
    const st = u.update?.status;
    if (!u.key?.fromMe || !st || !isPersonalJid(u.key.remoteJid)) continue;
    const d = inboxOf(id), k = chatKey(d, u.key.remoteJid, u.key.remoteJidAlt);
    const e = (d.msgs[k] || []).find((x) => x.id === u.key.id);
    if (!e || (e.st || 0) >= st) continue;
    e.st = st;
    const c = d.chats[k];
    if (c?.last && c.last.id === e.id) c.last.st = st;
    inboxTouch(id);
    // «شاف وما رد»: قرأ رسالة حديثة منّا وما كتب بعدها شي (إشعار وحدة لكل محادثة كل 6 ساعات)
    if (st >= 4 && c && Date.now() / 1000 - e.ts < 3 * 86400 && !(d.msgs[k] || []).some((x) => !x.me && x.ts >= e.ts) && Date.now() - (c.readAt || 0) > 6 * 3600000) {
      c.readAt = Date.now();
      actPush(id, 'reads', { jid: k, name: c.name || '', phone: c.phone || '', text: String(e.text || IB_LABEL[e.type] || '').slice(0, 80) });
    }
  }
}

// ---------------------------------------------------------------- الأزرار والاستبيانات (استبيان واتساب = أزرار حقيقية تنضغط)
// واتساب ما يعرض الأزرار التقليدية للحسابات العادية، فنرسل «استبيان» وخياراته تشتغل كأزرار:
// لما الشخص يختار خيار، نفك تشفير صوته ونبلّغ الموقع، والموقع يحدد شنو نرسله (رسالة/استبيان ثاني/شكر).
const POLLS_DIR = path.join(CFG.dataDir, 'polls');
fs.mkdirSync(POLLS_DIR, { recursive: true });
const polls = new Map(); // id -> {msgId: {tpl, k, ts, raw, opts:{sha256hex: label}, votes:{voter:[labels]}}}
const pollFile = (id) => path.join(POLLS_DIR, `${id.replace(/[^a-zA-Z0-9_-]/g, '')}.json`);
function pollsOf(id) {
  if (!polls.has(id)) { let d = {}; try { d = JSON.parse(fs.readFileSync(pollFile(id), 'utf8')); } catch { /* جديد */ } polls.set(id, d); }
  return polls.get(id);
}
function pollsSave(id) {
  const d = pollsOf(id), cut = Date.now() / 1000 - 45 * 86400;
  for (const [k, v] of Object.entries(d)) if (v.ts < cut) delete d[k];
  const keys = Object.keys(d);
  if (keys.length > 8000) keys.sort((a, b) => d[a].ts - d[b].ts).slice(0, keys.length - 8000).forEach((k) => delete d[k]);
  try { fs.writeFileSync(pollFile(id) + '.tmp', JSON.stringify(d)); fs.renameSync(pollFile(id) + '.tmp', pollFile(id)); } catch { /* ignore */ }
}
const sha256hex = (s) => crypto.createHash('sha256').update(Buffer.from(s)).digest('hex');
/** يرسل استبيان (أزرار) لشخص. poll = {question, options[], multi, tpl} */
function sendPoll(id, target, poll) {
  const r = rt(id);
  const job = r.sendChain.then(async () => {
    const sock = r.sock;
    if (!sock || r.status !== 'connected') throw new Error('الحساب غير متصل بواتساب');
    let jid = String(target);
    if (!jid.includes('@')) {
      const [res] = (await sock.onWhatsApp(digits(jid))) || [];
      if (!res?.exists) throw new Error('الرقم لا يستخدم واتساب');
      jid = res.jid;
    }
    const values = (poll.options || []).map((o) => String(o).slice(0, 100)).filter(Boolean).slice(0, 12);
    if (values.length < 2) throw new Error('الاستبيان يحتاج خيارين على الأقل');
    const sent = await sock.sendMessage(jid, { poll: { name: String(poll.question || '').slice(0, 255) || '؟', values, selectableCount: poll.multi ? 0 : 1 } });
    if (sent?.key?.id) {
      const d = pollsOf(id);
      d[sent.key.id] = { tpl: poll.tpl || null, k: chatKey(inboxOf(id), jid), ts: Math.floor(Date.now() / 1000), raw: JSON.stringify(sent.message, BufferJSON.replacer), opts: Object.fromEntries(values.map((v) => [sha256hex(v), v])), votes: {} };
      pollsSave(id);
    }
    return { id: sent?.key?.id || null, jid };
  });
  r.sendChain = job.catch(() => {});
  return job;
}
/** صوت على استبيان: نفكه (نجرب صيغ الـ jid: رقم/LID) ونشغّل الإجراء للخيارات الجديدة */
async function onPollVote(id, m) {
  const pu = m.message?.pollUpdateMessage;
  if (!pu?.vote || !pu.pollCreationMessageKey?.id) return;
  const p = pollsOf(id)[pu.pollCreationMessageKey.id];
  if (!p) return;
  const r = rt(id), acc = registry[id];
  const creation = JSON.parse(p.raw, BufferJSON.reviver);
  const encKey = creation?.messageContextInfo?.messageSecret;
  if (!encKey || !r.sock?.user) return;
  const mePn = jidNormalizedUser(r.sock.user.id), meLid = r.sock.user.lid ? jidNormalizedUser(r.sock.user.lid) : null;
  const voters = [...new Set([m.key.participantAlt, m.key.remoteJidAlt, m.key.participant, m.key.remoteJid].filter(Boolean).map((j) => jidNormalizedUser(j)))];
  let vote = null, voter = null;
  for (const creator of [mePn, meLid].filter(Boolean)) {
    for (const v of voters) {
      try { vote = decryptPollVote(pu.vote, { pollEncKey: encKey, pollCreatorJid: creator, pollMsgId: pu.pollCreationMessageKey.id, voterJid: v }); voter = v; break; } catch { /* نجرب غيرها */ }
    }
    if (vote) break;
  }
  if (!vote) { console.log('[استبيان] تعذر فك الصوت'); return; }
  const cur = (vote.selectedOptions || []).map((h) => p.opts[Buffer.from(h).toString('hex')]).filter(Boolean);
  const vk = chatKey(inboxOf(id), voter, m.key.remoteJidAlt);
  const prev = p.votes[vk] || [];
  p.votes[vk] = cur;
  pollsSave(id);
  const fresh = cur.filter((x) => !prev.includes(x));
  const d = inboxOf(id), c = d.chats[vk] || {};
  for (const option of fresh) {
    const res = await hook({ type: 'poll_vote', owner: acc?.owner, account_id: id, tpl: p.tpl, jid: vk, name: c.name || m.pushName || '', phone: c.phone || (/@s\.whatsapp\.net$/.test(vk) ? jidUser(vk) : ''), option, options: Object.values(p.opts) });
    // الموقع يرجّع شنو نرسله: رسالة و/أو استبيان ثاني
    if (res?.reply && (res.reply.text || res.reply.poll)) {
      const charge = await hook({ type: 'charge', owner: acc.owner, note: `رد على زر «${option}»`, quiet: 1 });
      if (!charge?.ok) continue;
      try {
        if (res.reply.text) await sendTo(id, m.key.remoteJid, res.reply.text);
        if (res.reply.poll) await sendPoll(id, m.key.remoteJid, res.reply.poll);
      } catch (e) { console.log('[استبيان] فشل الرد:', e.message); hook({ type: 'refund', owner: acc.owner, amount: charge.charged || 0, failed: 1 }); }
    }
  }
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

// ---------------------------------------------------------------- فحص الأرقام: عليها واتساب لو لا؟
// نتيجة كل رقم تنحفظ 7 أيام (حتى ما نسأل واتساب عن نفس الرقم كل مرة)، والفحص على دفعات صغيرة مع فاصل (أأمن للحساب)
const WA_CACHE_TTL = 7 * 86400000, WA_CHUNK = 20, WA_GAP = 900;
const waCache = new Map(); // رقم -> { e: موجود؟, j: jid, t: وقت الفحص }
function waCached(n) { const c = waCache.get(n); return c && Date.now() - c.t < WA_CACHE_TTL ? c : null; }

/** يفحص الأرقام ويرجع { on, off } - onProgress(تم, الكل) */
async function checkNumbers(id, numbers, onProgress = () => {}, shouldStop = () => false) {
  const list = [...new Set(numbers.map(digits).filter((n) => n.length >= 8))];
  const on = [], off = [], todo = [];
  for (const n of list) { const c = waCached(n); if (c) (c.e ? on : off).push(n); else todo.push(n); }
  let done = list.length - todo.length;
  onProgress(done, list.length);
  for (let i = 0; i < todo.length; i += WA_CHUNK) {
    if (shouldStop()) break;
    while (!isConnected(id)) { if (!registry[id] || shouldStop()) return { on, off, unknown: todo.slice(i) }; await sleep(3000); }
    const chunk = todo.slice(i, i + WA_CHUNK);
    let res = null;
    for (let tries = 0; tries < 3 && !res; tries++) {
      try { res = (await rt(id).sock.onWhatsApp(...chunk)) || []; } catch (e) { console.log('[فحص] خطأ مؤقت:', e.message); await sleep(3000); }
    }
    if (!res) { return { on, off, unknown: todo.slice(i) }; }
    const found = new Map();
    for (const r of res) { if (r?.exists) found.set(digits(String(r.jid || '').split('@')[0]), r.jid); }
    for (const n of chunk) {
      // واتساب أحياناً يرجع الـ jid بصيغة ثانية للرقم (مثل بدون صفر) - نطابق بآخر 9 أرقام
      let jid = found.get(n);
      if (!jid) for (const [k, v] of found) if (k.slice(-9) === n.slice(-9)) { jid = v; break; }
      waCache.set(n, { e: Boolean(jid), j: jid || null, t: Date.now() });
      (jid ? on : off).push(n);
    }
    done += chunk.length;
    onProgress(done, list.length);
    if (i + WA_CHUNK < todo.length) await sleep(WA_GAP);
  }
  if (waCache.size > 200000) for (const [k, v] of waCache) if (Date.now() - v.t > WA_CACHE_TTL) waCache.delete(k);
  return { on, off, unknown: [] };
}

/** فحص يدوي من صفحة الحملة (بالخلفية، والواجهة تسأل عن التقدم) */
const checkJobs = new Map(); // حساب -> { running, done, total, on, off, unknown, started }

// ---------------------------------------------------------------- الحملات
/** id -> حالة الحملة (نفس شكل النسخة القديمة حتى الواجهة تشتغل بدون تعديل) */
const campaigns = new Map();

function blankCampaign() {
  return {
    total: 0, sent: 0, failed: 0, running: false, failed_numbers: [], scheduled_for: null,
    pending_numbers: [], stop_requested: false, cancel_requested: false, paused: false,
    text: '', delay: DEFAULT_DELAY, media_path: null, media_mime: null, media_name: null,
    media_delay: DEFAULT_MEDIA_DELAY, started_at: null, campaign_id: null, tag: null, failed_reasons: {},
    pre_check: false, prechecked: false, checking: null, skipped_invalid: 0,
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
  // فلترة قبل البدء: نشيل الأرقام اللي ما عليها واتساب (ما تنخصم ولا تاخذ وقت فاصل)
  if (st.pre_check && !st.prechecked && st.pending_numbers.length) {
    st.checking = { done: 0, total: st.pending_numbers.length };
    persistCampaign(id);
    const r = await checkNumbers(id, st.pending_numbers.filter((n) => !String(n).includes('@')), (d, t) => { st.checking = { done: d, total: t }; }, () => st.stop_requested || !registry[id]);
    if (!registry[id]) { st._loop = false; return; }
    const off = new Set(r.off);
    if (off.size) {
      st.pending_numbers = st.pending_numbers.filter((n) => !off.has(digits(n)));
      st.failed_reasons = st.failed_reasons || {};
      for (const n of off) { st.failed += 1; st.failed_numbers.push(n); st.failed_reasons[n] = 'invalid'; }
      st.skipped_invalid = off.size;
    }
    st.checking = null;
    if (!st.stop_requested) st.prechecked = true;
    persistCampaign(id);
    if (off.size) addEvent(acc, 'فلترة الأرقام', `استُبعد ${off.size} رقم ما عليه واتساب قبل البدء`, 'info');
  }
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
        if (st.poll) { try { await sleep(1200); await sendPoll(id, res.jid, st.poll); } catch (e) { console.log('[حملة] فشل إرسال الأزرار:', e.message); } }
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
  const entry = { skipped_invalid: st.skipped_invalid || 0, time: st.started_at, total: st.total, sent: st.sent, failed: st.failed, text: st.text || (st.media_name ? '📎 ' + st.media_name : ''),
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
    status: r.status, logged_in: r.status === 'connected', phone: me, wa_name: r.sock?.user?.name || r.sock?.user?.verifiedName || null,
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
  inboxes.delete(id);
  await fsp.rm(inboxFile(id), { force: true });
  polls.delete(id);
  await fsp.rm(pollFile(id), { force: true });
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

  // فحص يدوي: يبدأ بالخلفية ويرجع فوراً
  ['POST', /^\/accounts\/([\w-]+)\/check$/, async (m, body) => {
    const id = m[1];
    if (!registry[id]) return [404, { ok: false, error: 'حساب غير موجود' }];
    if (!isConnected(id)) return [400, { ok: false, error: 'الرقم غير متصل بواتساب - اربطه من قسم أرقامي أولاً' }];
    const cur = checkJobs.get(id);
    if (cur?.running) return [200, { ok: true, ...cur }];
    const nums = (body.numbers || []).slice(0, 5000);
    if (!nums.length) return [400, { ok: false, error: 'ما فيه أرقام للفحص' }];
    const job = { running: true, done: 0, total: nums.length, on: [], off: [], unknown: [], started: Date.now() };
    checkJobs.set(id, job);
    checkNumbers(id, nums, (d, t) => { job.done = d; job.total = t; })
      .then((r) => Object.assign(job, r, { running: false }))
      .catch((e) => Object.assign(job, { running: false, error: e.message }));
    return [200, { ok: true, ...job }];
  }],

  ['GET', /^\/accounts\/([\w-]+)\/check$/, async (m) => {
    const job = checkJobs.get(m[1]);
    return [200, job ? { ok: true, ...job } : { ok: true, running: false, done: 0, total: 0, on: [], off: [], unknown: [] }];
  }],

  // ---------------------------------------------------------------- المحادثات
  ['GET', /^\/accounts\/([\w-]+)\/inbox$/, async (m) => {
    if (!registry[m[1]]) return [404, { ok: false, error: 'حساب غير موجود' }];
    const d = inboxOf(m[1]);
    const chats = Object.values(d.chats).filter((c) => c.ts || c.last).sort((a, b) => (b.ts || 0) - (a.ts || 0)).slice(0, 1500).map((c) => inboxPublicChat(d, c));
    return [200, { ok: true, connected: Boolean(isConnected(m[1])), chats }];
  }],
  ['GET', /^\/accounts\/([\w-]+)\/inbox\/chat$/, async (m, _b, q) => {
    const id = m[1];
    if (!registry[id]) return [404, { ok: false, error: 'حساب غير موجود' }];
    const d = inboxOf(id), k = chatKey(d, String(q.get('jid') || ''));
    const c = d.chats[k];
    if (!c) return [404, { ok: false, error: 'المحادثة غير موجودة' }];
    const msgs = d.msgs[k] || [];
    const since = Number(q.get('since') || 0);
    // فتح المحادثة = نعلّمها مقروءة (وبالجوال كذلك)
    if (q.get('read') === '1' && c.unread) {
      const keys = msgs.filter((x) => !x.me).slice(-Math.min(c.unread, 30)).map((x) => ({ remoteJid: x.rj || k, id: x.id, fromMe: false }));
      c.unread = 0; inboxTouch(id);
      if (isConnected(id) && keys.length) rt(id).sock.readMessages(keys).catch(() => {});
    }
    return [200, { ok: true, chat: inboxPublicChat(d, c), messages: msgs.filter((x) => x.ts >= since).map(inboxPublicMsg) }];
  }],
  // رد/رسالة جديدة: jid محادثة موجودة أو رقم جديد
  ['POST', /^\/accounts\/([\w-]+)\/inbox\/send$/, async (m, body) => {
    const id = m[1], acc = registry[id];
    if (!acc) return [404, { ok: false, error: 'حساب غير موجود' }];
    if (!isConnected(id)) return [400, { ok: false, error: 'الرقم غير متصل بواتساب' }];
    const text = String(body.text || '').slice(0, 4000);
    if (!text.trim() && !body.media?.data) return [400, { ok: false, error: 'اكتب رسالة أو أرفق ملف' }];
    const d = inboxOf(id);
    let target = body.jid ? chatKey(d, String(body.jid)) : digits(body.phone);
    if (body.jid && !isPersonalJid(target)) return [400, { ok: false, error: 'محادثة غير صالحة' }];
    if (!body.jid && target.length < 8) return [400, { ok: false, error: 'رقم غير صالح' }];
    const charge = await hook({ type: 'charge', owner: acc.owner, note: `رسالة من المحادثات: ${acc.name}` });
    if (!charge || !charge.ok) return [402, { ok: false, error: charge?.error || 'تعذر التحقق من الرصيد' }];
    const media = saveMedia(body.media);
    try {
      const res = await sendTo(id, target, text, media);
      const jid = chatKey(d, res.jid);
      // نسجلها فوراً (حتى لو حدث الإرسال تأخر)
      inboxAdd(id, { key: { remoteJid: res.jid, id: res.id || crypto.randomBytes(8).toString('hex'), fromMe: true }, messageTimestamp: Math.floor(Date.now() / 1000), message: media ? { documentMessage: { fileName: media.name, caption: text } } : { conversation: text } });
      return [200, { ok: true, jid, id: res.id }];
    } catch (e) {
      hook({ type: 'refund', owner: acc.owner, amount: charge.charged || 0, failed: 1 });
      return [400, { ok: false, error: e.message }];
    } finally {
      if (media) fs.rm(media.path, { force: true }, () => {});
    }
  }],
  // تحميل صورة/صوت/فيديو/ملف من رسالة (عند الطلب)
  ['GET', /^\/accounts\/([\w-]+)\/inbox\/media$/, async (m, _b, q) => {
    const id = m[1];
    if (!registry[id]) return [404, { ok: false, error: 'حساب غير موجود' }];
    const d = inboxOf(id), k = chatKey(d, String(q.get('jid') || ''));
    const e = (d.msgs[k] || []).find((x) => x.id === q.get('mid'));
    if (!e?.raw) return [404, { ok: false, error: 'غير موجود' }];
    if (!isConnected(id)) return [400, { ok: false, error: 'الرقم غير متصل' }];
    const msg = JSON.parse(e.raw, BufferJSON.reviver);
    const buf = await downloadMediaMessage(msg, 'buffer', {}, { logger, reuploadRequest: rt(id).sock.updateMediaMessage });
    const inner = msg.message?.ephemeralMessage?.message || msg.message?.viewOnceMessage?.message || msg.message?.viewOnceMessageV2?.message || msg.message?.documentWithCaptionMessage?.message || msg.message || {};
    const part = inner.imageMessage || inner.videoMessage || inner.audioMessage || inner.documentMessage || inner.stickerMessage || {};
    return [200, buf, { 'Content-Type': part.mimetype || 'application/octet-stream', 'Cache-Control': 'private, max-age=86400' }];
  }],
  // الصورة الشخصية لمحادثة (jid) أو للرقم نفسه (jid=me)
  ['GET', /^\/accounts\/([\w-]+)\/avatar$/, async (m, _b, q) => {
    if (!registry[m[1]]) return [404, { ok: false, error: 'حساب غير موجود' }];
    const j = String(q.get('jid') || '');
    if (j !== 'me' && !isPersonalJid(j)) return [400, { ok: false, error: 'jid' }];
    const buf = await avatarOf(m[1], j === 'me' ? 'me' : chatKey(inboxOf(m[1]), j));
    if (!buf) return [404, { ok: false, error: 'لا توجد صورة' }];
    return [200, buf, { 'Content-Type': 'image/jpeg', 'Cache-Control': 'private, max-age=86400' }];
  }],
  // إرسال أزرار/استبيان لشخص (jid محادثة أو رقم)
  ['POST', /^\/accounts\/([\w-]+)\/poll\/send$/, async (m, body) => {
    const id = m[1], acc = registry[id];
    if (!acc) return [404, { ok: false, error: 'حساب غير موجود' }];
    if (!isConnected(id)) return [400, { ok: false, error: 'الرقم غير متصل بواتساب' }];
    const target = body.jid ? chatKey(inboxOf(id), String(body.jid)) : digits(body.phone);
    if (!target || (!body.jid && target.length < 8)) return [400, { ok: false, error: 'رقم غير صالح' }];
    const charge = await hook({ type: 'charge', owner: acc.owner, note: `أزرار/استبيان: ${acc.name}` });
    if (!charge || !charge.ok) return [402, { ok: false, error: charge?.error || 'تعذر التحقق من الرصيد' }];
    try {
      if (body.text) await sendTo(id, target, String(body.text).slice(0, 4000));
      const res = await sendPoll(id, target, body.poll || {});
      return [200, { ok: true, jid: chatKey(inboxOf(id), res.jid), id: res.id }];
    } catch (e) {
      hook({ type: 'refund', owner: acc.owner, amount: charge.charged || 0, failed: 1 });
      return [400, { ok: false, error: e.message }];
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
    // أرقام، أو jid محادثة موجودة (من «المحادثات» - للأشخاص اللي ما نعرف رقمهم)
    const numbers = [...new Set((body.numbers || []).map((n) => (String(n).includes('@') ? String(n) : digits(n))).filter((n) => (n.includes('@') ? isPersonalJid(n) : n.length >= 8)))];
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
      pre_check: Boolean(body.pre_check),
      poll: body.poll && Array.isArray(body.poll.options) ? { question: String(body.poll.question || ''), options: body.poll.options.map(String), multi: Boolean(body.poll.multi), tpl: body.poll.tpl || null } : null,
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

// إنستغرام (تسجيل دخول بالحساب) - يضيف مساراته /ig/...
setupInstagram({ CFG, hook, routes, sleep });

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
      const [status, payload, headers] = await fn(m, body, url.searchParams);
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
