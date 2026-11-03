// Animation for the ninja mascot: keyframed body poses + procedural layers
// (breathing, blinking, aimed arms/legs) + rope physics for the headband tails.
// Everything is sampled and baked into a THREE.AnimationClip that is stored in the GLB.

export const DURATION = 7.4;

const TAU = Math.PI * 2;
const P0 = {
  rootY: 0, yaw: 0, hipsY: 0, sq: 0, lean: 0, twist: 0, side: 0,
  headX: 0, headY: 0, headZ: 0,
  fL: [-0.28, 0, 0.02], fR: [0.28, 0, 0.02], fYaw: 0,
  sPos: [0.05, 0.25, 0.62], sDir: [0.15, 0.55, 0.82], roll: 0,
  brow: 0, squint: 0, gazeX: 0, gazeY: 0,
};

// Pose keys. `e` = easing of the segment that ends at this key.
const KEYS = [
  { t: 0.0 },
  { t: 0.28, e: 'io', gazeX: 0.45, gazeY: 0.05, brow: 0.15 },
  { t: 0.6, e: 'io', gazeX: -0.35, gazeY: 0.0, brow: -0.25 },
  { t: 0.85, e: 'io', gazeX: 0, brow: 0 },
  { t: 1.52 },
  // anticipation crouch
  { t: 1.76, e: 'io', hipsY: -0.26, sq: -0.12, lean: 0.3, headX: -0.12, sPos: [0.05, 0.1, 0.55] },
  // jump with a full spin
  { t: 1.98, e: 'o', rootY: 1.15, yaw: Math.PI, hipsY: 0.05, sq: 0.1, lean: -0.1, headX: 0,
    fL: [-0.22, 0.28, 0.1], fR: [0.22, 0.32, -0.05], sPos: [0.1, 0.35, 0.5] },
  // land into a wide fighting stance
  { t: 2.18, e: 'i', rootY: 0, yaw: TAU, hipsY: -0.34, sq: -0.16, lean: 0.25,
    fL: [-0.58, 0, 0.32], fR: [0.55, 0, -0.26], fYaw: 0.35, sPos: [0.05, 0.2, 0.62] },
  { t: 2.42, e: 'o', hipsY: -0.22, sq: 0, lean: 0.12, twist: -0.15 },
  { t: 2.55, e: 'io' },
  // two-handed diagonal raise
  { t: 3.02, e: 'io', sPos: [0.5, 1.12, 0.5], sDir: [0.72, 0.66, -0.12], roll: 0.3, twist: -0.25, headX: 0.04, headY: 0.08 },
  // focused, determined expression
  { t: 3.3, e: 'o', brow: 1, squint: 0.55, headX: 0.14, headY: 0.02 },
  // wind-up (coil)
  { t: 3.47, e: 'io', twist: 0.4, sPos: [0.55, 1.15, 0.25], sDir: [0.78, 0.55, -0.3], lean: 0.05 },
  // fast slash with full body rotation
  { t: 3.66, e: 'i', yaw: TAU - Math.PI * 0.7, twist: 0.1, sPos: [0.58, 0.55, 0.5], sDir: [0.96, 0.12, 0.25], roll: 0, lean: 0.15 },
  { t: 3.95, e: 'o', yaw: 0, twist: -0.1, lean: 0.22, sPos: [-0.1, 0.42, 0.78], sDir: [-0.62, -0.1, 0.78] },
  // follow-through
  { t: 4.28, e: 'o', twist: -0.32, hipsY: -0.3, sPos: [-0.36, 0.3, 0.6], sDir: [-0.86, -0.36, 0.38] },
  { t: 4.95, e: 'io', twist: -0.15, hipsY: -0.22, brow: 0.8, squint: 0.35, headX: 0.06 },
  { t: 5.3, e: 'io', hipsY: -0.16, sq: 0.04 },
  // dynamic finishing pose
  { t: 5.68, e: 'o', hipsY: -0.32, sq: -0.04, fL: [-0.62, 0, 0.46], fR: [0.52, 0, -0.36], fYaw: 0.45,
    lean: 0.18, side: 0.12, twist: 0.22, headZ: -0.12, headX: 0.08, headY: -0.06,
    sPos: [0.3, 0.74, 0.56], sDir: [0.36, 0.62, 0.7], roll: -0.4, brow: 1, squint: 0.42, gazeX: -0.05 },
  { t: 5.9, e: 'io', sq: 0 },
  { t: DURATION },
];

// Fill each key with the previous key's values so every param is defined.
const FULL = (() => {
  const out = []; let prev = { ...P0 };
  for (const k of KEYS) { prev = { ...prev, ...k }; out.push(prev); }
  return out;
})();

const EASE = {
  lin: (x) => x,
  io: (x) => (x < 0.5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2),
  i: (x) => x * x * x,
  o: (x) => 1 - Math.pow(1 - x, 3),
};
const lerp = (a, b, t) => a + (b - a) * t;

function samplePose(t) {
  let i = 0;
  while (i < FULL.length - 2 && t > FULL[i + 1].t) i++;
  const a = FULL[i], b = FULL[i + 1];
  const u = Math.min(1, Math.max(0, (t - a.t) / (b.t - a.t || 1)));
  const k = EASE[b.e || 'io'](u);
  const p = {};
  for (const key of Object.keys(P0)) {
    const va = a[key], vb = b[key];
    p[key] = Array.isArray(va) ? va.map((v, j) => lerp(v, vb[j], k)) : lerp(va, vb, k);
  }
  return p;
}

const BLINKS = [0.45, 1.3, 2.72, 4.7, 6.15];
function blinkAmount(t) {
  let c = 0;
  for (const b of BLINKS) {
    const d = (t - b) / 0.075; // 0.15s blink
    if (d > -1 && d < 1) c = Math.max(c, 1 - Math.abs(d));
  }
  return c;
}

export function createAnimator(THREE, root) {
  const get = (n) => { const o = root.getObjectByName(n); if (!o) throw new Error('missing node ' + n); return o; };
  const R = {
    root, hips: get('Hips'), spine: get('Spine'), head: get('Head'), hc: get('HeadCenter'), sword: get('Sword'),
    arms: { L: get('ArmL'), R: get('ArmR') }, legs: { L: get('LegL'), R: get('LegR') },
    feet: { L: get('FootL'), R: get('FootR') }, gloves: { L: get('GloveL'), R: get('GloveR') },
    gaze: { L: get('GazeL'), R: get('GazeR') }, lid: { L: get('LidL'), R: get('LidR') },
    shine: { L: get('ShineL'), R: get('ShineR') }, brow: { L: get('BrowL'), R: get('BrowR') },
  };
  const { segments: SEG, length: SL } = root.userData.tail;
  const tails = ['A', 'B'].map((n) => ({
    root: get('Tail' + n),
    segs: Array.from({ length: SEG }, (_, i) => get(`Tail${n}_${i}`)),
    p: [], q: [],
  }));

  const Y = new THREE.Vector3(0, 1, 0), NZ = new THREE.Vector3(0, 0, -1);
  const v1 = new THREE.Vector3(), v2 = new THREE.Vector3(), qa = new THREE.Quaternion();
  const browBase = { L: R.brow.L.rotation.clone(), R: R.brow.R.rotation.clone() };
  const browY0 = R.brow.L.position.y;

  function aim(node, parent, targetWorld, restLen) {
    v1.copy(targetWorld); parent.worldToLocal(v1); v1.sub(node.position);
    const d = v1.length();
    node.quaternion.setFromUnitVectors(Y, v1.normalize());
    node.scale.set(1, Math.max(0.55, d / restLen), 1);
  }

  function applyBody(t) {
    const p = samplePose(t);
    const breathe = Math.sin((t / 1.7) * TAU);
    R.root.position.y = p.rootY;
    R.root.rotation.y = p.yaw;
    R.hips.position.y = 0.8 + p.hipsY;
    R.hips.scale.set(1 - p.sq * 0.5, 1 + p.sq, 1 - p.sq * 0.5);
    R.spine.rotation.set(p.lean + 0.02 * breathe, p.twist + 0.02 * Math.sin(t * 1.3), p.side);
    R.spine.scale.set(1 + 0.008 * breathe, 1 + 0.018 * breathe, 1 + 0.012 * breathe);
    R.head.rotation.set(p.headX - 0.015 * breathe, p.headY + 0.03 * Math.sin(t * 0.9), p.headZ + 0.015 * Math.sin(t * 1.1));
    R.feet.L.position.set(...p.fL); R.feet.R.position.set(...p.fR);
    R.feet.L.rotation.y = -p.fYaw; R.feet.R.rotation.y = p.fYaw;
    R.sword.position.set(p.sPos[0], p.sPos[1] + 0.01 * breathe, p.sPos[2]);
    v2.set(...p.sDir).normalize();
    R.sword.quaternion.setFromUnitVectors(Y, v2);
    qa.setFromAxisAngle(Y, p.roll); R.sword.quaternion.multiply(qa);

    // face
    const blink = blinkAmount(t);
    const close = Math.max(blink, p.squint * 0.55);
    for (const [s, side] of [[-1, 'L'], [1, 'R']]) {
      R.lid[side].rotation.x = lerp(-1.25, Math.PI / 2, close);
      const sh = close > 0.6 ? 0 : 1; R.shine[side].scale.setScalar(sh || 1e-4);
      R.gaze[side].rotation.set(-p.gazeY * 0.4, p.gazeX * 0.45, 0);
      const b = browBase[side];
      R.brow[side].rotation.set(b.x, b.y, s * lerp(-0.12, 0.5, p.brow) + s * 0.04 * blink);
      R.brow[side].position.y = lerp(browY0, browY0 - 0.06, Math.max(0, p.brow)) + 0.03 * Math.max(0, -p.brow) - 0.02 * blink;
    }

    root.updateMatrixWorld(true);
    // aim arms at the gloves on the sword handle, legs at the boots
    for (const side of ['L', 'R']) {
      R.gloves[side].getWorldPosition(v2);
      aim(R.arms[side], R.spine, v2, 0.66);
      R.feet[side].localToWorld(v2.set(0, 0.28, 0.02));
      aim(R.legs[side], R.hips, v2, 0.52);
      // legs point down: flip aim so capsule grows downward from hip
    }
    root.updateMatrixWorld(true);
  }

  // ---- rope physics for the headband tails ----
  const G = new THREE.Vector3(0, -2.4, 0);
  const headC = new THREE.Vector3();
  function initTails() {
    for (const tl of tails) {
      tl.segs.forEach((s, i) => { s.rotation.set(i === 0 ? 0.5 : 0.08, tl === tails[0] ? 0.25 : -0.25, 0); });
      root.updateMatrixWorld(true);
      tl.p = []; tl.q = [];
      for (let i = 0; i <= SEG; i++) {
        const w = new THREE.Vector3();
        if (i < SEG) tl.segs[i].getWorldPosition(w); else tl.segs[SEG - 1].localToWorld(w.set(0, 0, -SL));
        tl.p.push(w); tl.q.push(w.clone());
      }
    }
  }
  function stepTails(t, dt) {
    R.hc.getWorldPosition(headC);
    const sub = 4, h = dt / sub;
    for (let k = 0; k < sub; k++) {
      const tt = t + k * h;
      for (const [ti, tl] of tails.entries()) {
        tl.root.getWorldPosition(tl.p[0]);
        for (let i = 1; i <= SEG; i++) {
          const p = tl.p[i], q = tl.q[i];
          v1.copy(p).sub(q).multiplyScalar(0.975); // velocity with drag
          q.copy(p);
          const wind = v2.set((ti ? 1.1 : -1.1) + 0.9 * Math.sin(tt * 2.1 + ti), 0.8 + 0.6 * Math.sin(tt * 5 + i * 0.7 + ti * 2), -2.2 - 0.9 * Math.sin(tt * 3.3 + i));
          p.add(v1).addScaledVector(G, h * h).addScaledVector(wind, h * h);
        }
        for (let it = 0; it < 8; it++) {
          for (let i = 0; i < SEG; i++) {
            const a = tl.p[i], b = tl.p[i + 1];
            v1.copy(b).sub(a); const d = v1.length() || 1e-6; const diff = (d - SL) / d;
            if (i === 0) b.addScaledVector(v1, -diff);
            else { a.addScaledVector(v1, 0.5 * diff); b.addScaledVector(v1, -0.5 * diff); }
          }
          for (let i = 1; i <= SEG; i++) { // keep fabric outside the head
            v1.copy(tl.p[i]).sub(headC); const d = v1.length();
            if (d < 1.12) tl.p[i].copy(headC).addScaledVector(v1.normalize(), 1.12);
          }
        }
      }
    }
    // orient segments along the rope
    for (const tl of tails) {
      for (let i = 0; i < SEG; i++) {
        const s = tl.segs[i];
        v1.copy(tl.p[i + 1]).sub(tl.p[i]).normalize();
        s.parent.getWorldQuaternion(qa).invert();
        v1.applyQuaternion(qa);
        s.quaternion.setFromUnitVectors(NZ, v1);
        s.updateMatrixWorld(true);
      }
    }
  }

  let initialized = false;
  function reset() {
    applyBody(0); initTails();
    for (let i = 0; i < 90; i++) { applyBody(0); stepTails(0, 1 / 60); } // settle
    initialized = true;
  }
  function update(t, dt) {
    if (!initialized) reset();
    applyBody(t); stepTails(t, dt);
  }
  return { update, reset };
}

export function bakeClip(THREE, root, fps = 60) {
  const anim = createAnimator(THREE, root);
  anim.reset();
  const names = ['Ninja', 'Hips', 'Spine', 'Head', 'Sword', 'ArmL', 'ArmR', 'LegL', 'LegR', 'FootL', 'FootR',
    'GazeL', 'GazeR', 'LidL', 'LidR', 'ShineL', 'ShineR', 'BrowL', 'BrowR'];
  const { segments } = root.userData.tail;
  for (const n of ['A', 'B']) for (let i = 0; i < segments; i++) names.push(`Tail${n}_${i}`);
  const nodes = names.map((n) => root.getObjectByName(n));
  const frames = Math.round(DURATION * fps) + 1;
  const times = new Float32Array(frames);
  const pos = nodes.map(() => new Float32Array(frames * 3));
  const quat = nodes.map(() => new Float32Array(frames * 4));
  const scl = nodes.map(() => new Float32Array(frames * 3));
  for (let f = 0; f < frames; f++) {
    const t = f / fps; times[f] = t;
    anim.update(t, 1 / fps);
    nodes.forEach((o, j) => {
      o.position.toArray(pos[j], f * 3); o.quaternion.toArray(quat[j], f * 4); o.scale.toArray(scl[j], f * 3);
    });
  }
  const tracks = [];
  nodes.forEach((o, j) => {
    tracks.push(new THREE.VectorKeyframeTrack(`${o.name}.position`, times, pos[j]));
    tracks.push(new THREE.QuaternionKeyframeTrack(`${o.name}.quaternion`, times, quat[j]));
    tracks.push(new THREE.VectorKeyframeTrack(`${o.name}.scale`, times, scl[j]));
  });
  return new THREE.AnimationClip('NinjaSequence', DURATION, tracks);
}
