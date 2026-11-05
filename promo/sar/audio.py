"""Sar reel soundtrack: upbeat 124 BPM synth bed + UI SFX synced to reel.html. Usage: python3 audio.py out.wav"""
import sys, wave
import numpy as np
SR, DUR = 48000, 20.0
N = int(SR * DUR); rng = np.random.default_rng(5)
mus = np.zeros(N + SR); sfx = np.zeros(N + SR)
def add(b, s, t, g=1.0):
    i = int(max(t, 0) * SR); b[i:i + len(s)] += s[:len(b) - i] * g
def env(n, a=.003, d=.2):
    t = np.arange(n) / SR; return np.minimum(t / a, 1) * np.exp(-t / d)
def sweep(f0, f1, k, L):
    n = int(L * SR); t = np.arange(n) / SR; return np.sin(2 * np.pi * np.cumsum(f1 + (f0 - f1) * np.exp(-t * k)) / SR)
def lp(x, a):
    y = np.zeros_like(x); acc = 0.
    for i in range(len(x)): acc += a * (x[i] - acc); y[i] = acc
    return y
def tone(f, L, d, h=((1, 1), (2, .35), (3, .12))):
    n = int(L * SR); t = np.arange(n) / SR; return sum(a * np.sin(2 * np.pi * f * m * t) for m, a in h) * env(n, .004, d)
def whoosh(L=.45, peak=.65, br=.5):
    n = int(L * SR); t = np.linspace(0, 1, n); s = rng.standard_normal(n)
    sh = np.where(t < peak, (t / peak) ** 2, ((1 - t) / (1 - peak)) ** 1.5); cut = .02 + br * sh; y = np.zeros(n); acc = 0.
    for i in range(n): acc += cut[i] * (s[i] - acc); y[i] = acc
    return y * sh * 1.2
def kick(g=1.):
    n = int(.35 * SR); return np.tanh(sweep(160, 48, 26, .35) * env(n, .001, .12) * 2.2) * .5 * g
def hat(g=1.):
    n = int(.06 * SR); return np.diff(rng.standard_normal(n), prepend=0) * env(n, .0005, .014) * .1 * g
def clap(g=1.):
    n = int(.2 * SR); return lp(rng.standard_normal(n), .6) * env(n, .001, .045) * .25 * g
def bass(f, L):
    n = int(L * SR); t = np.arange(n) / SR; saw = 2 * ((t * f) % 1) - 1
    return lp(np.tanh(saw * 1.5 + np.sin(2 * np.pi * f * t)), .09) * env(n, .005, L * .7) * .33
def impact():
    n = int(.9 * SR); return np.tanh((sweep(120, 42, 11, .9) * env(n, .002, .3) + lp(rng.standard_normal(n), .12) * env(n, .001, .05)) * 1.7) * .8
def tap():
    n = int(.06 * SR); return (sweep(2200, 900, 160, .06) * env(n, .0005, .012) + np.diff(rng.standard_normal(n), prepend=0) * env(n, .0003, .004) * .5) * .6
def pop():
    n = int(.12 * SR); return sweep(1500, 420, 38, .12) * env(n, .001, .035) * .5
def chime(fs=(1318, 1976, 2637)):
    n = int(1.2 * SR); t = np.arange(n) / SR; out = np.zeros(n)
    for k, f in enumerate(fs):
        s = np.sin(2 * np.pi * f * t) * env(n, .002, .35) * .22; o = int(k * .07 * SR); out[o:] += s[:n - o]
    return out
def riser(L):
    n = int(L * SR); t = np.arange(n) / SR
    return (np.sin(2 * np.pi * np.cumsum(240 * 2 ** (t / L * 2.4)) / SR) * .1 + whoosh(L, .97, .55)[:n] * .3) * (t / L) ** 2.2
beat = 60 / 124
chords = [[261.6, 329.6, 392], [220, 261.6, 329.6], [174.6, 220, 261.6], [196, 246.9, 293.7]]  # C Am F G
roots = [65.4, 55, 43.65, 49]
k = 0
while k * beat < 19.2:
    t = k * beat; bar = k // 4; ch = chords[bar % 4]
    for h in range(2): add(mus, tone(ch[(k * 2 + h) % 3] * 2, .28, .1) * .05, t + h * beat / 2)
    if k % 4 == 0:
        n = int(2.2 * SR); tt = np.arange(n) / SR
        add(mus, sum(np.sin(2 * np.pi * f * tt) + .5 * np.sin(2 * np.pi * f * 1.004 * tt) for f in ch) * np.minimum(tt / .4, 1) * np.exp(-tt / 1.6) * .024, t)
    if t >= 3.45:
        add(mus, kick(), t); add(mus, hat(), t + beat / 2)
        if k % 2 == 1: add(mus, clap(), t)
        add(mus, bass(roots[bar % 4] * 2, beat * .85), t)
    k += 1
R = lambda u: u * 3.45 / 2.45 if u < 2.45 else u + 1.0   # story time -> 20 s cut
add(mus, riser(.9), 2.55, .7); add(mus, impact(), 3.45, .9)                 # drop into the explainer
add(mus, impact(), R(15.95), .9)                                            # outro hit
for f in (523.3, 659.3, 784, 1046.5): add(mus, tone(f, 2.4, .7) * .12, R(16.2))
# fire crackle under the intro and outro
def crackle(L, dens=38):
    n = int(L * SR); out = lp(rng.standard_normal(n), .05) * .25
    for _ in range(int(L * dens)):
        i = int(rng.random() * (n - 800)); m = int(.004 * SR); out[i:i + m] += rng.standard_normal(m) * env(m, .0002, .0015) * (.6 + rng.random())
    return out
# SFX
add(sfx, impact(), .05, .5); add(sfx, pop(), R(.35), .8)
for i in range(3): add(sfx, pop(), R(1.15 + i * .15), .5)
for t in [2.15, 5.05, 5.35, 6.75, 8.0, 10.6, 13.0, 15.6]: add(sfx, whoosh(), R(t) - .15, .45)
for c in range(4): add(sfx, pop(), R(3.1 + c * .12), .35); add(sfx, pop(), R(4.1 + c * .12), .35)
for t in [6.3, 7.5, 9.0, 12.15, 16.7]: add(sfx, tap(), R(t), 1.0)
for t in [10.85, 11.0, 11.15, 11.3]: add(sfx, pop(), R(t), .45)
add(sfx, chime(), R(12.4), .9)
add(sfx, pop(), R(13.55), .6); add(sfx, pop(), R(14.6), .6)
mix = (mus * .8 + sfx * .75)[:N]
f = int(.25 * SR); mix[-f:] *= np.linspace(1, 0, f)
mix /= np.max(np.abs(mix)) * 1.1
pcm = (mix * 32767).astype(np.int16)
with wave.open(sys.argv[1], 'wb') as w:
    w.setnchannels(2); w.setsampwidth(2); w.setframerate(SR); w.writeframes(np.column_stack([pcm, pcm]).ravel().tobytes())
