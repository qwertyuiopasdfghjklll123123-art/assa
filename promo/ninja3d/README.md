# Ninja Mascot 3D (Three.js)

A 3D cartoon ninja mascot with a baked GLB animation and a cinematic player.

- `src/ninja-model.js`: procedural 3D model (big head, brown eyes, thick brows, black mask, dark-brown hood, charcoal suit, turquoise headband + belt, gloves, boots, katana)
- `src/ninja-anim.js`: animation (keyed poses, blinking, breathing, aimed limbs, rope physics for the headband tails), baked to a glTF clip
- `assets/ninja.glb`: exported model + `NinjaSequence` animation (load it with `GLTFLoader` + `AnimationMixer`)
- `player.html`: scene (cyan background, geometric shapes, soft shadows, PBR lighting), camera moves, slash trail, motion blur, star transition
- `ninja-mascot-3d.mp4`: rendered clip, 1080×1920 at 30fps, 7.4s, with SFX

## Usage
```bash
npm install
npm run serve                      # open the printed URL to watch it live
npm run export-glb                 # rebuild assets/ninja.glb after editing src/
node scripts/render.cjs /tmp/frames 30 && python3 scripts/audio.py /tmp/a.wav
ffmpeg -framerate 30 -i /tmp/frames/f_%05d.png -i /tmp/a.wav -c:v libx264 -pix_fmt yuv420p -crf 18 -c:a aac -shortest ninja-mascot-3d.mp4
```
