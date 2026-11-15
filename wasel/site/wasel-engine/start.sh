#!/bin/bash
# تشغيل محرك واتساب بدون "Setup Node.js App" - يُستدعى من Cron كل 5 دقائق:
#   */5 * * * * /bin/bash /home/USER/wasel-engine/start.sh
# لو المحرك شغّال أصلاً ما يسوي شي، ولو متوقف يشغّله من جديد.
cd "$(dirname "$0")" || exit 1

# البحث عن node (nvm بمجلد المستخدم، أو نسخ CloudLinux، أو المثبت بالنظام)
for d in "$HOME"/.nvm/versions/node/*/bin /opt/alt/alt-nodejs2*/root/usr/bin /opt/alt/alt-nodejs*/root/usr/bin /usr/local/bin /usr/bin; do
  [ -x "$d/node" ] && PATH="$d:$PATH"
done
command -v node >/dev/null 2>&1 || { echo "node غير موجود على السيرفر" >> engine.log; exit 1; }

if [ -f engine.pid ] && kill -0 "$(cat engine.pid)" 2>/dev/null; then
  exit 0
fi
[ -d node_modules ] || npm install --omit=dev >> engine.log 2>&1
nohup node app.js >> engine.log 2>&1 &
echo $! > engine.pid
echo "$(date) started pid $!" >> engine.log
