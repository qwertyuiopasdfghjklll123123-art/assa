"""Synthesized SFX + light music bed for the ninja mascot clip. Usage: python3 audio.py out.wav"""
import sys, wave
import numpy as np
SR, DUR = 44100, 7.4
N = int(SR * DUR); rng = np.random.default_rng(3)
mix = np.zeros(N + SR)
def add(s, t, g=1.0):
    i = int(max(t, 0) * SR); mix[i:i + len(s)] += s[:len(mix) - i] * g
def env(n, a=.002, d=.2):
    t = np.arange(n) / SR; return np.minimum(t / a, 1) * np.exp(-t / d)
def sweep(f0, f1, k, L):
    n = int(L * SR); t = np.arange(n) / SR
    return np.sin(2 * np.pi * np.cumsum(f1 + (f0 - f1) * np.exp(-t * k)) / SR)
def lp(x, a):
    y = np.zeros_like(x); acc = 0.
    for i in range(len(x)): acc += a * (x[i] - acc); y[i] = acc
    return y
def whoosh(L=.5, up=True, bright=.45):
    n = int(L * SR); t = np.linspace(0, 1, n); s = rng.standard_normal(n)
    cut = (t if up else 1 - t) * bright + .02; y = np.zeros(n); acc = 0.
    for i in range(n): acc += cut[i] * (s[i] - acc); y[i] = acc
    return y * np.sin(np.pi * t) ** 2 * 1.6
def thud():
    n = int(.6 * SR); return np.tanh((sweep(150, 45, 14, .6) * env(n, .001, .2) + lp(rng.standard_normal(n), .2) * env(n, .001, .04)) * 2)
def shing():
    n = int(1.2 * SR); t = np.arange(n) / SR
    ring = sum(a * np.sin(2 * np.pi * f * t) for f, a in [(2900, .5), (4400, .35), (6300, .2), (8100, .1)])
    return ring * env(n, .003, .35) * .35 + np.diff(rng.standard_normal(n), prepend=0) * env(n, .001, .06) * .3
def pop():
    n = int(.15 * SR); return sweep(1400, 300, 40, .15) * env(n, .001, .05) * .5
def chime():
    n = int(1.4 * SR); t = np.arange(n) / SR
    return sum(a * np.sin(2 * np.pi * f * t) * env(n, .002, d) for f, a, d in [(1318, 1, .5), (1976, .6, .4), (2637, .4, .3), (3951, .2, .25)]) * .25
# music bed: soft pluck + pulse, 120 bpm
beat = .5; notes = [440, 523.3, 587.3, 659.3, 784, 659.3, 587.3, 523.3]
for i in range(int(DUR / beat * 2)):
    t = i * beat / 2; f = notes[i % 8]
    n = int(.4 * SR); tt = np.arange(n) / SR
    add(sum(a * np.sin(2 * np.pi * f * h * tt) for h, a in [(1, 1), (2, .4), (3, .15)]) * env(n, .002, .12) * .07, t)
    if i % 2 == 0: add(sweep(110, 50, 25, .3) * env(int(.3 * SR), .001, .1) * .35, t)
# SFX synced with the animation
add(pop(), .45, .5); add(pop(), 1.3, .4)            # blinks
add(whoosh(.6, True, .3), .9, .9)                   # zoom out
add(whoosh(.45, True, .5), 1.72, 1.0)               # jump
add(whoosh(.3, False, .6), 1.95, .7)                # spin
add(thud(), 2.18, 1.0)                              # landing
add(shing(), 2.6, .8)                               # sword raise
add(whoosh(.5, True, .7), 3.45, 1.2); add(shing(), 3.7, 1.0)   # slash
add(thud(), 5.68, .6)                               # final pose
add(whoosh(.6, True, .4), 6.3, .8); add(chime(), 6.95, 1.0)    # star transition
mix = mix[:N]; mix /= np.max(np.abs(mix)) * 1.05
fade = int(.3 * SR); mix[-fade:] *= np.linspace(1, 0, fade)
pcm = (mix * 32767).astype(np.int16)
with wave.open(sys.argv[1], 'wb') as w:
    w.setnchannels(2); w.setsampwidth(2); w.setframerate(SR); w.writeframes(np.column_stack([pcm, pcm]).ravel().tobytes())
