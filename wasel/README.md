# منصة واصل — نسخة PHP + JSON + تسجيل دخول كوكل

الموقع صار جزئين:

| المجلد | شنو هو | وين ينرفع |
|---|---|---|
| `site/` | الموقع كامل بلغة **PHP**، والبيانات تنحفظ بملفات **JSON** داخل `site/data/` (بدون MySQL) | داخل `public_html` |
| `engine/` | **محرك واتساب** صغير بـ Node.js (مكتبة Baileys): رمز QR، الإرسال، الحملات، الرد الآلي | تطبيق Node.js من لوحة DirectAdmin |

> ليش جزئين؟ PHP يشتغل لحظة الطلب وبعدين يتوقف، أما جلسة واتساب فلازم تبقى مفتوحة دائماً.
> المحرك الجديد يتصل بواتساب **مباشرة بدون كروم**، فيستهلك ذاكرة قليلة جداً ويشتغل على استضافة DirectAdmin العادية.

---

## 1) رفع الموقع (PHP)

1. ارفع **محتويات** مجلد `site/` إلى `public_html` (لازم ينرفع ملف `.htaccess` وملف `.user.ini` كمان؛ هذي ملفات مخفية).
2. تأكد أن مجلد `public_html/data` قابل للكتابة (صلاحية 755 أو 775).
3. نسخة PHP لازم تكون **8.0 أو أحدث** (من DirectAdmin ← Select PHP Version)، ومفعّل فيها: `curl`، `zip`، `mbstring`، `fileinfo`.
4. افتح `config.php` وعدّل:
   - `ENGINE_SECRET`: اكتب نص عشوائي طويل (32 حرف أو أكثر). **نفس النص** يُكتب بإعدادات المحرك.
   - `ENGINE_URL`: رابط تطبيق Node.js (الخطوة 2).
   - `GOOGLE_CLIENT_ID`: من الخطوة 3.
   - `CRON_KEY`: نص عشوائي ثاني.
   - `MASTER_ADMIN_PHONE` و`MASTER_ADMIN_CODE`: رقم ورمز دخول الأدمن الثابت (غيّرهم، أو اتركهم فارغين لتعطيلهم).

## 2) تشغيل محرك واتساب (Node.js) من DirectAdmin

1. ارفع مجلد `engine/` **خارج** `public_html`، مثلاً: `/home/USER/wasel-engine`.
2. انسخ `config.example.json` باسم `config.json` وعدّل:
   ```json
   {
     "ENGINE_SECRET": "نفس المفتاح اللي بـ config.php",
     "PHP_HOOK_URL": "https://دومينك/engine_hook",
     "TIMEZONE": "Asia/Baghdad"
   }
   ```
3. من DirectAdmin ← **Setup Node.js App** ← **Create Application**:
   - Node.js version: **20 أو أحدث**
   - Application mode: Production
   - Application root: `wasel-engine`
   - Application URL: الأفضل دومين فرعي مثل `engine.دومينك` (أو مسار مثل `دومينك/engine`)
   - Application startup file: `app.js`
4. اضغط **Run NPM Install**، وبعدها **Start / Restart**.
5. للتأكد: افتح رابط التطبيق بالمتصفح، لازم يطلع: `{"ok":true,"name":"wasel-engine"}`.
6. حط نفس الرابط في `ENGINE_URL` داخل `config.php` (بدون `/` بالنهاية).

> لو استخدمت مسار فرعي (`دومينك/engine`) وطلع خطأ `not found`، أضف متغير بيئة بنفس صفحة التطبيق: `BASE_PATH` = `/engine`.

## 3) تسجيل الدخول بكوكل

1. ادخل https://console.cloud.google.com ← اختر/أنشئ مشروع.
2. **APIs & Services ← OAuth consent screen**: اختر External، اكتب اسم الموقع وبريدك، واحفظ (وانشره Publish).
3. **APIs & Services ← Credentials ← Create Credentials ← OAuth client ID**:
   - Application type: **Web application**
   - Authorized JavaScript origins: `https://دومينك`
4. انسخ **Client ID** (ينتهي بـ `.apps.googleusercontent.com`) وحطه بـ `GOOGLE_CLIENT_ID` داخل `config.php`.

صفحة الدخول صار بيها زر **Continue with Google**، وتحته تسجيل الدخول برقم واتساب كالسابق.
- أول شخص يسجل بالموقع (بكوكل أو بواتساب) يصير **أدمن المنصة**.
- مستخدم كوكل بنفس بريد حساب قديم ينربط بنفس الحساب.

## 4) المهام المجدولة (Cron) — مهم

من DirectAdmin ← **Cron Jobs**، أضف مهمة كل 5 دقائق (`*/5 * * * *`):
```
/usr/local/bin/php /home/USER/domains/دومينك/public_html/cron.php
```
(أو بدلها: `curl -s "https://دومينك/cron?key=CRON_KEY"`)

هذا يرسل تذكيرات وانتهاء اشتراكات عملائك، ويوقف خطط المستخدمين المنتهية، **ويبقي محرك واتساب صاحي** (بعض الاستضافات توقف تطبيقات Node.js لما تكون خاملة).

## 5) أول تشغيل

1. افتح الموقع ← سجّل دخول بكوكل (تصير أدمن).
2. من «حساباتي» ← أضف حساب ← امسح رمز QR من واتساب جوالك (الأجهزة المرتبطة).
3. من إعدادات الحساب فعّل «مرسل رموز التحقق» حتى يقدر الناس يدخلون برقم واتساب.

---

## نقل بياناتك من النسخة القديمة (app.db)

بعد رفع الموقع وتشغيل المحرك، شغّل مرة وحدة من Terminal/SSH:
```
cd ~/domains/دومينك/public_html
php tools/import_sqlite.php /المسار/إلى/app.db
```
ينقل: المستخدمين، الاشتراكات والدفعات، مشتركي كل مستخدم، الإعدادات والشعار، مفاتيح API، وحسابات واتساب.
جلسات واتساب القديمة (كروم) ما تنتقل، فكل حساب يحتاج مسح QR مرة وحدة.

## ملفات البيانات (`site/data/`)

`users.json`، `wa_accounts.json`، `subscribers.json`، `payments.json`، `api_keys.json`، `events.json`، `history.json`، `settings.json`… كلها محمية من الوصول عبر المتصفح بملف `.htaccess`.
**خذ نسخة احتياطية** من مجلد `data/` (ومجلد `data/` داخل المحرك) بشكل دوري.

## الفروقات عن النسخة القديمة

- قاعدة البيانات: SQLite ← ملفات JSON.
- واتساب: سيلينيوم + كروم ← Baileys (اتصال مباشر، أخف بكثير).
- **الرد الآلي** صار يشتغل على كل رسالة جديدة مباشرة، بدل مراقبة قائمة المحادثات كل 6 ثواني.
- الصورة/الفيديو/الملف بالحملة ينرسل **مع النص كتعليق** برسالة وحدة.
- سجل الحملات صار ينحفظ بشكل دائم (سابقاً كان يضيع عند إعادة التشغيل)، والحملة الشغالة تكمل تلقائياً لو انعاد تشغيل المحرك.
- **أمان**: رقم الأدمن الثابت كان يدخل مباشرة بدون أي رمز (أي أحد يكتب الرقم يصير أدمن!)، هسه لازم يكتب `MASTER_ADMIN_CODE`. رموز التحقق صارت محدودة بـ 5 محاولات، وفيه انتظار 45 ثانية بين كل طلب رمز.
- ملفات الأرقام: يدعم `.xlsx` و`.csv` (ملفات `.xls` القديمة جداً لا).
