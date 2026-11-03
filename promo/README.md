# فيديو موشن جرافيك — عرض نينجا الأسبوعي

- الفيديو النهائي: `ninja-weekly-offer.mp4` (عمودي 1080×1920، 30fps، 16.5 ثانية، مع صوت)
- `promo.html`: المشاهد والحركة (CSS animations)
- `render.cjs`: يصوّر الإطارات بـ Playwright
- `audio.py`: يولّد الموسيقى والمؤثرات الصوتية (numpy)

## إعادة البناء
```bash
python3 audio.py /tmp/audio.wav
node render.cjs /tmp/frames 30 16.5
ffmpeg -framerate 30 -i /tmp/frames/f_%05d.png -i /tmp/audio.wav -c:v libx264 -pix_fmt yuv420p -crf 20 -c:a aac -b:a 192k -shortest ninja-weekly-offer.mp4
```
لتعديل الأسعار أو النصوص: عدّل `promo.html` ثم أعد البناء.
