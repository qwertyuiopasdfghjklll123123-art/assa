"""Synthesizes the promo soundtrack: 128 BPM beat + SFX synced to promo.html timeline."""
import sys, wave
import numpy as np

SR = 44100
DUR = 16.5
rng = np.random.default_rng(7)
mix = np.zeros(int(SR * DUR) + SR)

def add(sig, t, gain=1.0):
    i = int(max(t, 0) * SR)
    mix[i:i + len(sig)] += sig[: len(mix) - i] * gain

def env(n, a=0.002, d=0.2):
    t = np.arange(n) / SR
    return np.minimum(t / a, 1) * np.exp(-t / d)

def kick():
    n = int(.35 * SR); t = np.arange(n) / SR
    f = 50 + 120 * np.exp(-t * 30)
    return np.sin(2 * np.pi * np.cumsum(f) / SR) * env(n, .001, .12)

def hat():
    n = int(.06 * SR)
    s = rng.standard_normal(n); s = np.diff(s, prepend=0)
    return s * env(n, .0005, .015) * .25

def clap():
    n = int(.2 * SR)
    s = rng.standard_normal(n)
    e = env(n, .001, .05)
    return np.convolve(s, np.ones(8) / 8, 'same') * e * .5

def bass(freq, length):
    n = int(length * SR); t = np.arange(n) / SR
    saw = 2 * ((t * freq) % 1) - 1
    s = np.tanh(2.5 * (saw * .6 + np.sin(2 * np.pi * freq / 2 * t)))
    return s * env(n, .005, length * .6) * .35

def whoosh(length=.45, up=True):
    n = int(length * SR); t = np.linspace(0, 1, n)
    s = rng.standard_normal(n)
    # sweep a simple one-pole low-pass cutoff
    cut = (t if up else 1 - t) * .5 + .02
    y = np.zeros(n); acc = 0
    for i in range(n):
        acc += cut[i] * (s[i] - acc); y[i] = acc
    return y * np.sin(np.pi * t) ** 2 * 1.4

def impact():
    n = int(1.2 * SR); t = np.arange(n) / SR
    boom = np.sin(2 * np.pi * np.cumsum(40 + 80 * np.exp(-t * 12)) / SR) * env(n, .001, .45)
    noise = rng.standard_normal(n) * env(n, .001, .08) * .5
    return np.tanh((boom + noise) * 1.8) * .9

def ding():
    n = int(1.0 * SR); t = np.arange(n) / SR
    s = sum(a * np.sin(2 * np.pi * f * t) for f, a in [(1760, 1), (2637, .6), (3520, .35), (5274, .2)])
    s2 = sum(a * np.sin(2 * np.pi * f * t) for f, a in [(2349, .8), (3136, .5)])
    out = s * env(n, .001, .25)
    out[int(.07 * SR):] += (s2 * env(n, .001, .3))[: n - int(.07 * SR)]
    return out * .22

def pop():
    n = int(.12 * SR); t = np.arange(n) / SR
    return np.sin(2 * np.pi * np.cumsum(300 + 900 * np.exp(-t * 40)) / SR) * env(n, .001, .03) * .5

def swish():
    return whoosh(.18, up=False) * .7

def riser(length):
    n = int(length * SR); t = np.arange(n) / SR
    f = 200 * 2 ** (t / length * 3)
    tone = np.sin(2 * np.pi * np.cumsum(f) / SR) * .15
    noise = rng.standard_normal(n) * .12
    return (tone + noise) * (t / length) ** 2

# --- music: 128 BPM ---
beat = 60 / 128
notes = [55, 55, 65.4, 49]  # A, A, C, G (bass roots per bar)
k, h, c, b = kick(), hat(), clap(), None
for i in range(int(DUR / beat)):
    t = i * beat
    if 2.15 < t < 2.4:  # tiny gap before the 50% drop
        continue
    add(k, t, .9)
    add(h, t + beat / 2)
    if i % 2 == 1:
        add(c, t, .6)
    root = notes[(i // 4) % 4]
    add(bass(root, beat * .45), t + beat / 2, 1)
add(riser(1.0), 1.25, .9)
add(riser(.9), 12.5, .7)

# --- SFX synced to the visuals ---
for t in [0.0, 2.25, 3.85, 7.05, 10.25, 13.35]:
    add(whoosh(), t - .1, .9)
for t in [0.8, 2.4, 13.7]:
    add(impact(), t, 1.0)
for t in [4.95, 8.15]:
    add(swish(), t, 1)
for t in [5.1, 8.3, 11.1]:
    add(impact() * .45, t)
    add(ding(), t + .05)
for t in [4.05, 4.5, 7.25, 7.7, 10.45, 10.9, 14.3]:
    add(pop(), t, .8)

mix = mix[: int(SR * DUR)]
fade = int(.8 * SR)
mix[-fade:] *= np.linspace(1, 0, fade)
mix = np.tanh(mix * 1.1)
mix /= np.max(np.abs(mix)) * 1.05
pcm = (mix * 32767).astype(np.int16)
stereo = np.column_stack([pcm, pcm]).ravel()
with wave.open(sys.argv[1] if len(sys.argv) > 1 else 'audio.wav', 'wb') as w:
    w.setnchannels(2); w.setsampwidth(2); w.setframerate(SR)
    w.writeframes(stereo.tobytes())
