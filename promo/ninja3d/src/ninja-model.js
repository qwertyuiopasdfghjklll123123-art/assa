// Procedural 3D cartoon ninja mascot (chibi proportions), built as a named node hierarchy
// so it can be exported to GLB with baked animation and re-loaded with GLTFLoader.
// Units: head radius = 1. The character stands on y = 0 facing +z.

export const COLORS = {
  skin: 0xf3c4a0,
  hood: 0x3e2a20, // dark brown head covering
  suit: 0x2b2d34, // charcoal outfit
  suitDark: 0x1c1e23,
  mask: 0x141519,
  band: 0x0089cc, // bright turquoise-blue headband & belt
  glove: 0x101114,
  iris: 0x7b4422, // warm brown eyes
  brow: 0x15110e,
  blade: 0xe7eef3,
  tsuba: 0xc8a14a,
  handle: 0x17171c,
};

export function buildNinja(THREE) {
  const C = COLORS;
  const M = {
    skin: new THREE.MeshPhysicalMaterial({ name: 'Skin', color: C.skin, roughness: 0.55, sheen: 0.5, sheenColor: new THREE.Color(0xffd9c4) }),
    hood: new THREE.MeshStandardMaterial({ name: 'Hood', color: C.hood, roughness: 0.85 }),
    suit: new THREE.MeshStandardMaterial({ name: 'Suit', color: C.suit, roughness: 0.8 }),
    suitDark: new THREE.MeshStandardMaterial({ name: 'SuitDark', color: C.suitDark, roughness: 0.85 }),
    mask: new THREE.MeshStandardMaterial({ name: 'Mask', color: C.mask, roughness: 0.75 }),
    band: new THREE.MeshStandardMaterial({ name: 'Band', color: C.band, roughness: 0.38, emissive: 0x003a5c, emissiveIntensity: 0.35, side: THREE.DoubleSide }),
    glove: new THREE.MeshStandardMaterial({ name: 'Glove', color: C.glove, roughness: 0.55 }),
    sclera: new THREE.MeshPhysicalMaterial({ name: 'Sclera', color: 0xffffff, roughness: 0.2, clearcoat: 1, clearcoatRoughness: 0.05 }),
    iris: new THREE.MeshPhysicalMaterial({ name: 'Iris', color: C.iris, roughness: 0.3, clearcoat: 1, clearcoatRoughness: 0.05 }),
    pupil: new THREE.MeshStandardMaterial({ name: 'Pupil', color: 0x0b0705, roughness: 0.2 }),
    shine: new THREE.MeshBasicMaterial({ name: 'Shine', color: 0xffffff }),
    lash: new THREE.MeshStandardMaterial({ name: 'Lash', color: 0x0d0a09, roughness: 0.6 }),
    brow: new THREE.MeshStandardMaterial({ name: 'Brow', color: C.brow, roughness: 0.9 }),
    blade: new THREE.MeshStandardMaterial({ name: 'Blade', color: C.blade, metalness: 1, roughness: 0.16 }),
    tsuba: new THREE.MeshStandardMaterial({ name: 'Tsuba', color: C.tsuba, metalness: 0.9, roughness: 0.35 }),
    handle: new THREE.MeshStandardMaterial({ name: 'Handle', color: C.handle, roughness: 0.7 }),
  };

  const node = (name, parent, pos = [0, 0, 0]) => {
    const g = new THREE.Group(); g.name = name; g.position.set(...pos); parent.add(g); return g;
  };
  const mesh = (name, geo, m, parent, pos = [0, 0, 0], rot = [0, 0, 0], scl = [1, 1, 1]) => {
    const o = new THREE.Mesh(geo, m); o.name = name;
    o.position.set(...pos); o.rotation.set(...rot); o.scale.set(...scl);
    parent.add(o); return o;
  };
  const sphere = (r, ws = 48, hs = 32, ...rest) => new THREE.SphereGeometry(r, ws, hs, ...rest);

  const root = new THREE.Group(); root.name = 'Ninja';

  // ---------- lower body ----------
  const hips = node('Hips', root, [0, 0.8, 0]);
  mesh('Pelvis', sphere(0.4), M.suit, hips, [0, 0.02, 0], [0, 0, 0], [1.05, 0.72, 0.9]);

  for (const [s, side] of [[-1, 'L'], [1, 'R']]) {
    const leg = node('Leg' + side, hips, [s * 0.22, -0.08, 0]);
    const lg = new THREE.CapsuleGeometry(0.16, 0.2, 8, 20); lg.translate(0, 0.26, 0);
    mesh('LegMesh' + side, lg, M.suit, leg);
    const foot = node('Foot' + side, root, [s * 0.28, 0, 0.02]);
    const boot = new THREE.CapsuleGeometry(0.17, 0.16, 8, 20);
    mesh('Boot' + side, boot, M.glove, foot, [0, 0.16, 0.06], [Math.PI / 2, 0, 0], [1, 0.95, 1]);
    mesh('BootCuff' + side, new THREE.TorusGeometry(0.16, 0.045, 10, 24), M.suitDark, foot, [0, 0.3, 0], [Math.PI / 2, 0, 0]);
  }

  // ---------- torso ----------
  const spine = node('Spine', hips);
  mesh('Torso', new THREE.CapsuleGeometry(0.46, 0.32, 10, 32), M.suit, spine, [0, 0.38, 0], [0, 0, 0], [1, 1, 0.86]);
  // wrap-style collar lines
  mesh('Collar', new THREE.TorusGeometry(0.3, 0.07, 12, 32), M.suitDark, spine, [0, 0.8, 0], [Math.PI / 2, 0, 0]);
  mesh('WrapL', new THREE.BoxGeometry(0.06, 0.55, 0.02), M.suitDark, spine, [-0.1, 0.5, 0.385], [0.05, 0, -0.5]);
  mesh('WrapR', new THREE.BoxGeometry(0.06, 0.55, 0.02), M.suitDark, spine, [0.1, 0.5, 0.385], [0.05, 0, 0.5]);
  // turquoise belt + knot
  mesh('Belt', new THREE.TorusGeometry(0.44, 0.075, 16, 48), M.band, spine, [0, 0.1, 0], [Math.PI / 2, 0, 0], [1, 0.88, 1]);
  mesh('BeltKnot', sphere(0.1), M.band, spine, [0.24, 0.1, 0.37], [0, 0, 0], [1.2, 1, 0.8]);
  mesh('BeltTail1', new THREE.BoxGeometry(0.09, 0.28, 0.025), M.band, spine, [0.29, -0.07, 0.38], [0.1, 0, 0.28]);
  mesh('BeltTail2', new THREE.BoxGeometry(0.09, 0.24, 0.025), M.band, spine, [0.19, -0.06, 0.39], [0.1, 0, -0.2]);

  // ---------- arms (aimed + stretched at runtime toward the gloves on the sword grip) ----------
  for (const [s, side] of [[-1, 'L'], [1, 'R']]) {
    mesh('Shoulder' + side, sphere(0.17), M.suit, spine, [s * 0.5, 0.6, 0]);
    const arm = node('Arm' + side, spine, [s * 0.5, 0.6, 0]);
    const ag = new THREE.CapsuleGeometry(0.13, 0.4, 8, 20); ag.translate(0, 0.33, 0);
    mesh('ArmMesh' + side, ag, M.suit, arm);
  }

  // ---------- katana ----------
  const sword = node('Sword', spine, [0.05, 0.25, 0.62]);
  mesh('Handle', new THREE.CylinderGeometry(0.055, 0.05, 0.46, 16), M.handle, sword, [0, -0.04, 0]);
  for (const [i, y] of [-0.2, -0.08, 0.04, 0.14].entries())
    mesh('Wrap' + i, new THREE.TorusGeometry(0.058, 0.013, 8, 20), M.band, sword, [0, y, 0], [Math.PI / 2, 0, 0]);
  mesh('Pommel', sphere(0.07, 20, 14), M.tsuba, sword, [0, -0.28, 0]);
  mesh('Tsuba', new THREE.CylinderGeometry(0.15, 0.15, 0.04, 28), M.tsuba, sword, [0, 0.21, 0]);
  mesh('Habaki', new THREE.BoxGeometry(0.06, 0.05, 0.04), M.tsuba, sword, [0.005, 0.255, 0]);
  const L = 1.5;
  const c = (u) => 0.11 * u * u, w = (u) => 0.085 * (1 - 0.3 * u);
  const shape = new THREE.Shape();
  const n = 24;
  shape.moveTo(c(0) - w(0) / 2, 0);
  for (let i = 1; i <= n; i++) { const u = (i / n) * 0.93; shape.lineTo(c(u) - w(u) / 2, u * L); }
  shape.lineTo(c(1) + 0.01, L);
  for (let i = n; i >= 0; i--) { const u = (i / n) * 0.93; shape.lineTo(c(u) + w(u) / 2, u * L); }
  const bg = new THREE.ExtrudeGeometry(shape, { depth: 0.02, bevelEnabled: true, bevelThickness: 0.006, bevelSize: 0.006, bevelSegments: 2, curveSegments: 4 });
  bg.translate(0, 0, -0.01);
  mesh('Blade', bg, M.blade, sword, [0, 0.27, 0]);
  node('SwordTip', sword, [c(1), 0.27 + L, 0]);
  node('SwordMid', sword, [c(0.3), 0.27 + 0.45, 0]);
  // gloves grip the handle (two-handed)
  mesh('GloveR', sphere(0.19), M.glove, sword, [0, 0.08, 0], [0, 0, 0], [1, 1.12, 1]);
  mesh('GloveL', sphere(0.19), M.glove, sword, [0, -0.17, 0], [0, 0, 0], [1, 1.12, 1]);

  // ---------- head ----------
  const head = node('Head', spine, [0, 0.92, 0]);
  const hc = node('HeadCenter', head, [0, 0.85, 0]);
  mesh('HeadSkin', sphere(1, 64, 48), M.skin, hc);
  // dark brown hood: top cap + back wrap, leaving an eye window at the front
  mesh('HoodTop', sphere(1.03, 64, 32, 0, Math.PI * 2, 0, 1.25), M.hood, hc);
  mesh('HoodBack', sphere(1.028, 64, 32, Math.PI / 2 + 0.95, Math.PI * 2 - 1.9, 1.1, 1.95), M.hood, hc);
  mesh('HoodRim', new THREE.TorusGeometry(0.955, 0.04, 10, 64), M.hood, hc, [0, 0.31, 0], [Math.PI / 2, 0, 0]);
  // black face mask over nose & mouth
  mesh('Mask', sphere(1.035, 64, 32, 0, Math.PI * 2, 1.85, Math.PI - 1.85), M.mask, hc);
  mesh('MaskRim', new THREE.TorusGeometry(0.96, 0.035, 10, 64), M.mask, hc, [0, -0.276, 0], [Math.PI / 2, 0, 0]);
  mesh('Nose', sphere(0.1, 24, 16), M.mask, hc, [0, -0.34, 0.97], [0, 0, 0], [1, 0.75, 0.55]);

  // big expressive brown eyes
  for (const [s, side] of [[-1, 'L'], [1, 'R']]) {
    const x = s * 0.36, y = 0.02, z = Math.sqrt(1 - x * x - y * y) - 0.02;
    const eye = node('Eye' + side, hc, [x, y, z]);
    eye.rotation.set(-Math.asin(y), Math.atan2(x, z) * 0.75, 0);
    const shp = node('EyeShape' + side, eye); shp.scale.set(1, 1.28, 0.55);
    mesh('Sclera' + side, sphere(0.21), M.sclera, shp);
    const gaze = node('Gaze' + side, shp);
    mesh('Iris' + side, sphere(0.14), M.iris, gaze, [0, 0, 0.19], [0, 0, 0], [1, 1, 0.3]);
    mesh('Pupil' + side, sphere(0.075, 24, 16), M.pupil, gaze, [0, 0, 0.215], [0, 0, 0], [1, 1, 0.3]);
    const shine = node('Shine' + side, shp);
    mesh('ShineA' + side, sphere(0.045, 16, 12), M.shine, shine, [-0.05, 0.065, 0.232]);
    mesh('ShineB' + side, sphere(0.022, 12, 8), M.shine, shine, [0.05, -0.05, 0.232]);
    const lid = node('Lid' + side, shp); lid.rotation.x = -1.25;
    mesh('LidMesh' + side, sphere(0.232, 40, 20, 0, Math.PI * 2, 0, Math.PI / 2), M.skin, lid);
    mesh('Lash' + side, new THREE.TorusGeometry(0.232, 0.02, 10, 40), M.lash, lid, [0, 0, 0], [Math.PI / 2, 0, 0]);
    // thick eyebrows on the hood edge
    const brow = node('Brow' + side, hc, [s * 0.36, 0.34, 0.9]);
    brow.rotation.set(-0.3, Math.atan2(x, z) * 0.8, 0);
    mesh('BrowMesh' + side, new THREE.CapsuleGeometry(0.068, 0.26, 8, 16), M.brow, brow, [0, 0, 0], [0, 0, Math.PI / 2], [1, 1, 0.6]);
  }

  // turquoise headband + knot + two flowing tails (rope-simulated at bake time)
  mesh('Headband', new THREE.TorusGeometry(0.92, 0.09, 16, 72), M.band, hc, [0, 0.47, 0], [Math.PI / 2 - 0.12, 0, 0]);
  const knot = node('BandKnot', hc, [0, 0.38, -0.93]);
  mesh('KnotCore', sphere(0.13), M.band, knot, [0, 0, 0], [0, 0, 0], [1.3, 1, 0.8]);
  mesh('KnotLoopL', sphere(0.09), M.band, knot, [-0.15, 0.02, -0.02], [0, 0, 0.4], [1.5, 0.8, 0.6]);
  mesh('KnotLoopR', sphere(0.09), M.band, knot, [0.15, 0.02, -0.02], [0, 0, -0.4], [1.5, 0.8, 0.6]);
  const SEG = 8, SL = 0.21;
  for (const [t, off] of [['A', [-0.06, 0.02, -0.06]], ['B', [0.06, -0.03, -0.06]]]) {
    let parent = node('Tail' + t, knot, off);
    for (let i = 0; i < SEG; i++) {
      const seg = node(`Tail${t}_${i}`, parent, i === 0 ? [0, 0, 0] : [0, 0, -SL]);
      const g = new THREE.BoxGeometry(0.24 * (1 - 0.045 * i), 0.03, SL + 0.012); g.translate(0, 0, -SL / 2);
      mesh(`Tail${t}Mesh_${i}`, g, M.band, seg);
      parent = seg;
    }
  }

  root.userData.tail = { segments: SEG, length: SL };
  return root;
}
