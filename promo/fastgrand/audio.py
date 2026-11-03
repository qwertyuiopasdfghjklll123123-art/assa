"""Fast Grand film soundtrack: soft 120 BPM synth bed + SFX + Arabic voice-over (ducked).
Usage: python3 audio.py <voDir> <out.wav>"""
import sys, wave
import numpy as np
SR, DUR = 48000, 15.0
VO_DIR, OUT = sys.argv[1], sys.argv[2]
VO_AT = [0.2, 3.1, 5.95, 9.0, 11.38, 13.5]
N = int(SR * DUR); rng = np.random.default_rng(11)
mus = np.zeros(N + SR); sfx = np.zeros(N + SR); vo = np.zeros(N + SR)
def add(b, s, t, g=1.0):
    i = int(max(t, 0) * SR); b[i:i + len(s)] += s[:len(b) - i] * g
def env(n, a=.003, d=.2):
    t = np.arange(n) / SR; return np.minimum(t / a, 1) * np.exp(-t / d)
def sweep(f0, f1, k, L):
    n = int(L * SR); t = np.arange(n) / SR
    return np.sin(2 * np.pi * np.cumsum(f1 + (f0 - f1) * np.exp(-t * k)) / SR)
def lp(x, a):
    y = np.zeros_like(x); acc = 0.
    for i in range(len(x)): acc += a * (x[i] - acc); y[i] = acc
    return y
def tone(f, L, d, harm=((1, 1), (2, .35), (3, .12))):
    n = int(L * SR); t = np.arange(n) / SR
    return sum(a * np.sin(2 * np.pi * f * h * t) for h, a in harm) * env(n, .004, d)
def whoosh(L, peak=.6, bright=.5):
    n = int(L * SR); t = np.linspace(0, 1, n); s = rng.standard_normal(n)
    sh = np.where(t < peak, (t / peak) ** 2, ((1 - t) / (1 - peak)) ** 1.5)
    cut = .02 + bright * sh; y = np.zeros(n); acc = 0.
    for i in range(n): acc += cut[i] * (s[i] - acc); y[i] = acc
    return y * sh * 1.2
def impact():
    n = int(.9 * SR); return np.tanh((sweep(110, 42, 10, .9) * env(n, .002, .3) + lp(rng.standard_normal(n), .12) * env(n, .001, .05)) * 1.6) * .8
def glass(f=2100):
    n = int(1.0 * SR); t = np.arange(n) / SR
    return sum(a * np.sin(2 * np.pi * f * h * t) * env(n, .001, d) for h, a, d in [(1, 1, .35), (2.76, .5, .2), (5.4, .25, .1)]) * .25
def click():
    n = int(.05 * SR); return (np.diff(rng.standard_normal(n), prepend=0) * env(n, .0003, .006) + sweep(3000, 1200, 200, .05) * env(n, .0005, .01) * .5) * .6
def shimmer():
    n = int(1.6 * SR); out = np.zeros(n)
    for k, f in enumerate([1568, 1976, 2349, 2637, 3136]):
        s = tone(f, 1.6 - k * .06, .5, ((1, 1), (2, .2))) * .12; out[int(k * .06 * SR):] += s[:n - int(k * .06 * SR)]
    return out
# --- music: 120 BPM, A major-ish soft pluck arpeggio + pad + soft kick ---
beat = .5
chords = [[220, 277.2, 329.6, 440], [185, 233.1, 277.2, 370], [146.8, 185, 220, 293.7], [164.8, 207.7, 246.9, 329.6]]
for b in range(int(DUR / beat) + 1):
    t = b * beat; ch = chords[(b // 4) % 4]
    for k in range(4):
        add(mus, tone(ch[(k * 3 + b) % 4] * 2, .35, .14) * .05, t + k * beat / 4)
    if b % 4 == 0:
        n = int(2.2 * SR); tt = np.arange(n) / SR
        pad = sum(np.sin(2 * np.pi * f * tt) + .5 * np.sin(2 * np.pi * f * 1.005 * tt) for f in ch) * np.minimum(tt / .4, 1) * np.exp(-tt / 1.6)
        add(mus, pad * .03, t)
    if t >= 2.0:
        add(mus, sweep(120, 48, 22, .35) * env(int(.35 * SR), .001, .12) * .45, t)
        if b % 2 == 1: add(mus, lp(rng.standard_normal(int(.15 * SR)), .5) * env(int(.15 * SR), .001, .03) * .12, t)
# --- SFX ---
add(sfx, impact(), .05, .7)
for t in [2.6, 5.45, 8.7, 11.05, 12.55, 13.3]: add(sfx, whoosh(.55, .65, .45), t, .55)
add(sfx, impact(), 5.62, .6)
for t in [7.75, 10.2, 12.35]: add(sfx, glass(2093 if t < 12 else 2637), t, .9)
for k in range(6): add(sfx, click(), 3.4 + k * .06, .35)
add(sfx, click(), 14.5, 1.0)
add(sfx, shimmer(), 13.75, .9)
# --- voice-over ---
def read(path):
    with wave.open(path) as w:
        sr, ch = w.getframerate(), w.getnchannels(); x = np.frombuffer(w.readframes(w.getnframes()), np.int16).astype(float) / 32768
    if ch > 1: x = x.reshape(-1, ch).mean(1)
    x = np.interp(np.arange(int(len(x) * SR / sr)) * sr / SR, np.arange(len(x)), x)
    nz = np.nonzero(np.abs(x) > .01)[0]; return x[nz[0]:nz[-1] + 1]
act = np.zeros(N + SR)
for k, t in enumerate(VO_AT, 1):
    x = read(f"{VO_DIR}/{k}.wav"); x = np.tanh(x / np.max(np.abs(x)) * 1.5)
    x = x - lp(x, .02) * .6  # thin out mud
    add(vo, x, t); i = int(t * SR); act[i:i + len(x)] = 1
k = int(.1 * SR); duck = np.convolve(act, np.ones(k) / k, 'same')
mus *= 1 - .55 * duck
mix = (mus * .9 + sfx * .7 + vo * 1.0)[:N]
f = int(.06 * SR); mix[-f:] *= np.linspace(1, 0, f)
mix /= np.max(np.abs(mix)) * 1.1
pcm = (mix * 32767).astype(np.int16)
with wave.open(OUT, 'wb') as w:
    w.setnchannels(2); w.setsampwidth(2); w.setframerate(SR); w.writeframes(np.column_stack([pcm, pcm]).ravel().tobytes())
