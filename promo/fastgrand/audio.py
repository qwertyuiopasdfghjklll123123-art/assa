"""FastCrand film soundtrack: music scored to the picture (120 BPM) + SFX + optional voice-over (ducked).
Usage: python3 audio.py <voDir|-> <out.wav>   ("-" = music + SFX only, no voice)"""
import sys, wave
import numpy as np
SR, DUR = 48000, 16.0
VO_DIR, OUT = sys.argv[1], sys.argv[2]
VO_AT = [0.2, 3.2, 6.1, 9.1, 11.6, 13.9]
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
# --- music scored to the picture: 120 BPM, beats at 0.1 + 0.5k (every scene change sits on a beat) ---
B0, beat = 0.1, 0.5
bt = lambda k: B0 + k * beat
chords = [[220, 277.2, 329.6], [185, 233.1, 277.2], [146.8, 185, 220], [164.8, 207.7, 246.9]]  # A  F#m  D  E
roots = [55, 46.25, 73.4, 82.4]
def kick(g=1.0):
    n = int(.4 * SR); return np.tanh(sweep(150, 46, 24, .4) * env(n, .001, .14) * 2.2) * .5 * g
def clap(g=1.0):
    n = int(.22 * SR); s = rng.standard_normal(n); return lp(s, .6) * env(n, .001, .05) * .25 * g
def hat(g=1.0):
    n = int(.07 * SR); return np.diff(rng.standard_normal(n), prepend=0) * env(n, .0005, .015) * .1 * g
def bass(f, L):
    n = int(L * SR); t = np.arange(n) / SR; saw = 2 * ((t * f) % 1) - 1
    return lp(np.tanh(saw * 1.5 + np.sin(2 * np.pi * f * t)), .08) * env(n, .005, L * .7) * .35
def riser(L):
    n = int(L * SR); t = np.arange(n) / SR
    tone_ = np.sin(2 * np.pi * np.cumsum(220 * 2 ** (t / L * 2.5)) / SR) * .12
    noise = whoosh(L, .97, .55)[:n]
    return (tone_ + noise * .3) * (t / L) ** 2.2
def bell(f):
    return tone(f, 1.4, .45, ((1, 1), (2.0, .4), (3.0, .2), (4.2, .1))) * .16
total = int((DUR - B0) / beat)
for k in range(total + 1):
    t = bt(k); bar = k // 4; ch = chords[bar % 4]; beat_in_bar = k % 4
    if t > 15.6: break
    for h in range(2):  # arpeggio, 8ths after the intro
        if t < 3.1 and h == 1: continue
        add(mus, tone(ch[(k * 2 + h) % 3] * 2, .3, .12) * .055, t + h * beat / 2)
    if beat_in_bar == 0:  # pad per bar
        n = int(2.4 * SR); tt = np.arange(n) / SR
        pad = sum(np.sin(2 * np.pi * f * tt) + .5 * np.sin(2 * np.pi * f * 1.004 * tt) for f in ch) * np.minimum(tt / .5, 1) * np.exp(-tt / 1.8)
        add(mus, pad * (.02 if t < 5.6 else .028), t)
    if 3.1 <= t < 5.6:  # build: soft kick + hats
        add(mus, kick(.55), t); add(mus, hat(.8), t + beat / 2)
    if 5.6 <= t < 13.1 or 14.1 <= t < 15.1:  # groove after the drop
        add(mus, kick(1.0), t); add(mus, hat(1.0), t + beat / 2)
        if beat_in_bar in (1, 3): add(mus, clap(1.0), t)
        add(mus, bass(roots[bar % 4] * 2, beat * .9), t)
add(mus, riser(1.0), 4.6, .9)              # build into the icon reveal
add(mus, impact(), 5.6, 1.0)               # DROP on the reveal
for k in range(8): add(mus, clap(.5 + k * .08), 13.1 + k * .0625)  # snare roll under the pull-back
add(mus, riser(.5), 13.6, .6)              # cards converge
add(mus, impact(), 14.1, .7)               # CTA button lands
for f in (440, 554.4, 659.3, 880): add(mus, bell(f), 15.1, .8)  # resolved chord on the click
# --- SFX (timed to the picture) ---
add(sfx, impact(), .1, .6)                                    # logo lands
for t in [2.9, 8.6, 11.07, 13.22, 13.8]: add(sfx, whoosh(.5, .7, .45), t, .5)   # moves
for k in range(6): add(sfx, click(), 3.95 + k * .05, .3)     # feature tiles lock in
for t in [7.6, 10.1, 12.6]: add(sfx, glass(2093 if t < 12 else 2637), t, .9)   # prices land on the beat
add(sfx, click(), 15.1, 1.0)                                  # button click
add(sfx, shimmer(), 15.12, .8)
# --- voice-over ---
def read(path):
    with wave.open(path) as w:
        sr, ch = w.getframerate(), w.getnchannels(); x = np.frombuffer(w.readframes(w.getnframes()), np.int16).astype(float) / 32768
    if ch > 1: x = x.reshape(-1, ch).mean(1)
    x = np.interp(np.arange(int(len(x) * SR / sr)) * sr / SR, np.arange(len(x)), x)
    nz = np.nonzero(np.abs(x) > .01)[0]; return x[nz[0]:nz[-1] + 1]
act = np.zeros(N + SR)
for k, t in (enumerate(VO_AT, 1) if VO_DIR != "-" else []):
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
