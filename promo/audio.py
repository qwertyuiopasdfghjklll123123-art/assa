"""Builds the promo soundtrack: taiko-style beat + ninja SFX + Arabic voice-over (ducked music).
Usage: python3 audio.py <voDir> <out.wav>   (voDir from voice.sh)"""
import sys, wave
import numpy as np

SR = 44100
DUR = 24.5
VO_DIR, OUT = sys.argv[1], sys.argv[2]
VO_AT = [0.3, 2.5, 7.0, 11.0, 14.7, 18.5]  # start of each voice line (matches promo.html scenes)
rng = np.random.default_rng(7)
N = int(SR * DUR)
music = np.zeros(N + SR); sfx = np.zeros(N + SR); vo = np.zeros(N + SR)

def add(buf, sig, t, gain=1.0):
    i = int(max(t, 0) * SR)
    buf[i:i + len(sig)] += sig[: len(buf) - i] * gain

def env(n, a=0.002, d=0.2):
    t = np.arange(n) / SR
    return np.minimum(t / a, 1) * np.exp(-t / d)

def sweep(f0, f1, k, length):
    n = int(length * SR); t = np.arange(n) / SR
    return np.sin(2 * np.pi * np.cumsum(f1 + (f0 - f1) * np.exp(-t * k)) / SR)

def lowpass(x, a):
    y = np.zeros_like(x); acc = 0.0
    for i in range(len(x)):
        acc += a * (x[i] - acc); y[i] = acc
    return y

def taiko(big=False):
    L = .7 if big else .4; n = int(L * SR)
    body = sweep(140 if big else 180, 55 if big else 80, 18, L) * env(n, .001, .25 if big else .12)
    skin = lowpass(rng.standard_normal(n), .25) * env(n, .001, .03)
    return np.tanh((body + skin * .8) * 1.6)

def shaker():
    n = int(.08 * SR); s = np.diff(rng.standard_normal(n), prepend=0)
    return s * env(n, .002, .02) * .2

def koto(freq, length=.5):
    n = int(length * SR); t = np.arange(n) / SR
    s = sum(a * np.sin(2 * np.pi * freq * h * t) for h, a in [(1, 1), (2, .5), (3, .25), (4, .12)])
    return s * env(n, .002, .18) * .18

def whoosh(length=.45, up=True):
    n = int(length * SR); t = np.linspace(0, 1, n)
    cut = (t if up else 1 - t) * .45 + .02
    y = np.zeros(n); acc = 0.0; s = rng.standard_normal(n)
    for i in range(n):
        acc += cut[i] * (s[i] - acc); y[i] = acc
    return y * np.sin(np.pi * t) ** 2 * 1.5

def katana():
    n = int(.9 * SR); t = np.arange(n) / SR
    ring = sum(a * np.sin(2 * np.pi * f * t) for f, a in [(3200, .5), (4700, .35), (6100, .2)]) * env(n, .001, .25)
    hiss = np.diff(rng.standard_normal(n), prepend=0) * env(n, .001, .05)
    return (ring * .35 + hiss * .5)

def impact():
    n = int(1.1 * SR)
    boom = sweep(120, 38, 12, 1.1) * env(n, .001, .4)
    return np.tanh((boom + rng.standard_normal(n) * env(n, .001, .06) * .6) * 2) * .9

def smoke():
    n = int(.5 * SR)
    return lowpass(rng.standard_normal(n), .08) * env(n, .01, .15) * 2.5

def thud():
    n = int(.3 * SR)
    return np.tanh(sweep(200, 70, 30, .3) * env(n, .001, .07) * 2) * .7

def ding():
    n = int(.8 * SR); t = np.arange(n) / SR
    s = sum(a * np.sin(2 * np.pi * f * t) for f, a in [(1760, 1), (2637, .6), (3520, .3)])
    return s * env(n, .001, .22) * .18

# --- music (128 BPM, A minor pentatonic koto + taiko) ---
beat = 60 / 128
scale = [220, 261.6, 293.7, 329.6, 392, 440]
pattern = [0, 2, 3, 5, 4, 3, 2, 1]
for i in range(int(DUR / beat) + 1):
    t = i * beat
    add(music, taiko(i % 4 == 0), t, .9 if i % 4 == 0 else .55)
    add(music, shaker(), t + beat / 2)
    if i % 8 in (3, 7): add(music, taiko(), t + beat * .75, .4)
    add(music, koto(scale[pattern[i % 8]]), t, .9)
    add(music, koto(scale[pattern[(i + 3) % 8]] * 2, .25), t + beat / 2, .5)

# --- SFX synced with promo.html ---
for t in [0.0, 7.0, 11.0, 14.7, 18.5]: add(sfx, whoosh(.6), t, 1.0)          # ninja flips
for t in [0.75, 7.55, 11.55, 15.25, 19.0]: add(sfx, smoke(), t, 1.0)          # smoke puffs
for t in [1.0, 8.45, 12.15, 15.95, 18.65]: add(sfx, katana(), t, 1.0)         # katana slashes
add(sfx, whoosh(.35, up=False), 2.72, .9)                                      # shuriken throw
for t in [3.08, 8.55, 12.25, 16.0, 20.3]: add(sfx, impact(), t, .9)          # slams
for t in [8.6, 12.3, 16.05]: add(sfx, ding(), t)                               # prices
for t in [9.1, 12.8, 16.5]: add(sfx, thud(), t)                                # stamps

# --- voice-over ---
def read_wav(path):
    with wave.open(path) as w:
        sr, ch = w.getframerate(), w.getnchannels()
        x = np.frombuffer(w.readframes(w.getnframes()), dtype=np.int16).astype(float) / 32768
    if ch > 1: x = x.reshape(-1, ch).mean(1)
    if sr != SR: x = np.interp(np.arange(int(len(x) * SR / sr)) * sr / SR, np.arange(len(x)), x)
    nz = np.nonzero(np.abs(x) > .01)[0]
    return x[nz[0]:nz[-1] + 1] if len(nz) else x

active = np.zeros(N + SR)
for k, t in enumerate(VO_AT, 1):
    x = read_wav(f"{VO_DIR}/{k}.wav")
    x = np.tanh(x / np.max(np.abs(x)) * 1.6)  # loud, slightly saturated "announcer" voice
    add(vo, x, t)
    i = int(t * SR); active[i:i + len(x)] = 1

# duck music under the voice (smoothed sidechain)
k = int(.08 * SR)
duck = np.convolve(active, np.ones(k) / k, 'same')
music *= 1 - .6 * duck

mix = music * .55 + sfx * .8 + vo * 1.0
mix = mix[:N]
fade = int(.7 * SR); mix[-fade:] *= np.linspace(1, 0, fade)
mix = np.tanh(mix * 1.1); mix /= np.max(np.abs(mix)) * 1.05
pcm = (mix * 32767).astype(np.int16)
with wave.open(OUT, 'wb') as w:
    w.setnchannels(2); w.setsampwidth(2); w.setframerate(SR)
    w.writeframes(np.column_stack([pcm, pcm]).ravel().tobytes())
