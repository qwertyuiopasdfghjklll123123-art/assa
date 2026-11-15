// ملف التشغيل لـ DirectAdmin (Setup Node.js App -> Application startup file: app.js)
// المحرك مكتوب كـ ES Module، وهذا الملف يحمّله بطريقة تشتغل مع Passenger.
import('./engine.mjs').catch((e) => {
  console.error('تعذر تشغيل المحرك:', e);
  process.exit(1);
});
