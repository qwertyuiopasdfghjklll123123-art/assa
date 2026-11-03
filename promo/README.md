# فيديو موشن جرافيك — عرض نينجا الأسبوعي (ستايل نينجا)

- الفيديو النهائي: `ninja-weekly-offer.mp4` (عمودي 1080×1920، 30fps، 24.5 ثانية، معلق صوتي + موسيقى + مؤثرات)
- `promo.html`: المشاهد والحركة (شخصية نينجا تتقلب، ضربات كاتانا، شوريكن، دخان) بخط IBM Plex Sans Arabic
- `voice.sh`: يولّد المعلق الصوتي العربي (espeak-ng)
- `audio.py`: يركّب الموسيقى والمؤثرات والمعلق مع خفض الموسيقى تحت الصوت
- `render.cjs`: يصوّر الإطارات بـ Playwright

## إعادة البناء
```bash
sudo apt-get install -y espeak-ng
sh voice.sh /tmp/vo
python3 audio.py /tmp/vo /tmp/audio.wav
node render.cjs /tmp/frames 30 24.5
ffmpeg -framerate 30 -i /tmp/frames/f_%05d.png -i /tmp/audio.wav -c:v libx264 -pix_fmt yuv420p -crf 20 -c:a aac -b:a 192k -shortest ninja-weekly-offer.mp4
```
