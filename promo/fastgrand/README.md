# Fast Grand — weekly offer film (16:9)

- `fast-grand-offer-16x9.mp4`: 1920×1080, 60fps, 15.0s, H.264 + AAC, Arabic voice-over, loudness ≈ −14 LUFS
- `film.html`: every frame is a pure function of time `seek(t)` (no timers, no CSS transitions, no randomness)
- `voice.sh`: Arabic voice-over (offline espeak-ng); `audio.py`: synthesized music bed + SFX + ducked VO
- `render.cjs`: Playwright frame renderer; `contact-sheet.jpg`, `poster.jpg`

## Storyboard
| Time | Shot | Transition |
|---|---|---|
| 0–2.8s | Blue haze clears; the FG icon rises, "Fast Grand" unmasks from behind it, tagline + "بأفضل جودة · وبسعر مناسب" pill | the icon travels to centre as the text drops away |
| 2.8–5.6s | "جودة لا تُقارن… بسعر مناسب"; six feature tiles orbit in and lock around the icon | tiles spiral back into the icon |
| 5.6–6.1s | the icon grows to fill the frame, revealing the blue world | shape-driven reveal (no crossfade) |
| 5.95–12.7s | carousel of offer cards: app $99 (−50%), management & sales site $55 (−50%), landing site $35 (special price); each price lands on the spoken number | camera glides card to card with directional motion blur |
| 12.7–13.45s | pull back to all three cards | cards converge into the CTA button |
| 13.45–15s | "مشروعك يبدأ برسالة" + "تواصل معنا الآن" button, cursor click, brand line | final hold |

## Rebuild
```bash
sudo apt-get install -y espeak-ng
sh voice.sh /tmp/vo && python3 audio.py /tmp/vo /tmp/a.wav
node render.cjs /tmp/frames 60
ffmpeg -framerate 60 -i /tmp/frames/f_%05d.png -i /tmp/a.wav -af loudnorm=I=-14:TP=-1 -c:v libx264 -pix_fmt yuv420p -crf 17 -c:a aac -shortest fast-grand-offer-16x9.mp4
```
