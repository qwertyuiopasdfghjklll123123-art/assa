/* تمريني - رسوم متحركة للتمارين (SVG): هيكل جسم يتحرك بين وضعيتين + الأجهزة + العضلة الشغّالة باللون الأحمر.
   كل تمرين بالمكتبة إله "anim" من الأنماط تحت. */
(function () {
  'use strict';
  const L = { t: 40, neck: 5, head: 8.5, ua: 24, fa: 22, hand: 8, th: 32, sn: 31, foot: 10 };
  const FLOOR = 140;
  const R = d => d * Math.PI / 180;
  const pt = (p, a, l) => [p[0] + l * Math.cos(R(a)), p[1] + l * Math.sin(R(a))];
  const ang = (a, b) => Math.atan2(b[1] - a[1], b[0] - a[0]) * 180 / Math.PI;
  const dist = (a, b) => Math.hypot(b[0] - a[0], b[1] - a[1]);
  /** حركة عكسية: مفصلين (فخذ+ساق أو عضد+ساعد) يوصلون لنقطة. bend: -1 أو 1 لجهة الركبة/الكوع */
  function ik(root, target, l1, l2, bend) {
    const d = Math.min(dist(root, target), l1 + l2 - 0.01);
    const a = ang(root, target);
    const c = Math.max(-1, Math.min(1, (l1 * l1 + d * d - l2 * l2) / (2 * l1 * d)));
    const a1 = a + bend * Math.acos(c) * 180 / Math.PI;
    const j = pt(root, a1, l1);
    return [a1, ang(j, target)];
  }

  // ------------------------------------------------------------ الأجهزة الثابتة
  const bench = (x1, y1, x2, y2, legs = true) => ({ k: 'bench', x1, y1, x2, y2, legs });
  const cableTop = (x, y = -14) => [{ k: 'tower', x: x + 6 }, { k: 'cable', from: [x, y], to: 'hd' }];
  const seat = (x1, x2, y) => bench(x1, y, x2, y);

  // ------------------------------------------------------------ الأنماط: A و B وضعيتين + أجهزة
  // hip: الورك، t: زاوية الجذع، ua/fa: العضد/الساعد (درجات، 0 = يمين، 90 = تحت)، th/sn: الفخذ/الساق، ank: مكان الكاحل (حركة عكسية)
  const STAND = { hip: [96, 74], t: -92, ank: [100, 137], ank2: [96, 137] };
  const P = {
    bench: { A: { hip: [100, 96], t: 180, th: 20, sn: 90, ua: 50, fa: -100 }, B: { ua: -95, fa: -90 }, eq: [bench(34, 104, 122, 104)], hold: 'plate' },
    incline: { A: { hip: [106, 104], t: -150, th: 0, sn: 92, ua: 110, fa: -70 }, B: { ua: -80, fa: -80 }, eq: [bench(112, 114, 62, 86, false), seat(96, 126, 113), { k: 'line', a: [118, 113], b: [118, FLOOR] }, { k: 'line', a: [70, 92], b: [70, FLOOR] }], hold: 'plate' },
    fly: { front: true, A: { ua: 0, fa: -15, k: 1 }, B: { ua: 0, fa: -15, k: -0.35 }, hold: 'db' },
    pushup: { A: { hip: [105.8, 128.1], t: -174.1, ank: [154, 133], hand: [60, 137], eb: 1, flip: true }, B: { hip: [96.6, 107.1], t: -156.3 } },
    dips: { A: { hip: [100, 66], t: -90, th: 105, sn: 150, hand: [101, 72], eb: 1 }, B: { hip: [103, 92], t: -95 }, eq: [{ k: 'line', a: [76, 76], b: [140, 76], w: 5 }, { k: 'line', a: [134, 76], b: [134, FLOOR], w: 4 }] },
    pulldown: { A: { hip: [100, 104], t: -95, th: 0, sn: 90, ua: -95, fa: -92 }, B: { ua: 115, fa: -50 }, eq: [seat(84, 118, 111), { k: 'circle', c: [126, 94], r: 5 }, ...cableTop(96), { k: 'bar', at: 'hd' }] },
    row: { A: { hip: [70, 120], t: -70, th: -8, sn: 8, ft: -82, ua: 5, fa: 3 }, B: { t: -95, ua: 130, fa: -20 }, eq: [{ k: 'rect', r: [44, 124, 46, 16, 3] }, { k: 'line', a: [138, 104], b: [138, 128], w: 5 }, { k: 'tower', x: 190 }, { k: 'cable', from: [186, 92], to: 'hd' }] },
    bentrow: { A: { hip: [90, 76], t: -25, ank: [94, 137], ank2: [90, 137], ua: 90, fa: 90 }, B: { ua: 210, fa: 95 }, hold: 'plate' },
    dbrow: { A: { hip: [86, 80], t: -8, ank: [84, 137], ank2: [96, 137], ua: 90, fa: 90, ua2: 92, fa2: 92 }, B: { ua: 205, fa: 100 }, eq: [bench(112, 126, 150, 126)], hold: 'db' },
    pullup: { A: { hip: [100, 76], t: -90, th: 100, sn: 130, hand: [101, -10], eb: 1 }, B: { hip: [100, 44] }, eq: [{ k: 'line', a: [70, -10], b: [140, -10], w: 4 }] },
    deadlift: { A: { hip: [72, 98], t: -30, ank: [100, 137], ank2: [96, 137], ua: 92, fa: 92 }, B: { hip: [96, 74], t: -92 }, hold: 'plate' },
    backext: { A: { hip: [100, 70], t: 112, ank: [140, 110], ank2: [136, 110], uaR: 150, faR: -150, flip: true }, B: { t: 222 }, eq: [{ k: 'circle', c: [104, 77], r: 6 }, { k: 'line', a: [106, 80], b: [126, FLOOR], w: 5 }, { k: 'circle', c: [146, 114], r: 4 }, { k: 'line', a: [146, 116], b: [140, FLOOR], w: 4 }] },
    straightpull: { A: { ...STAND, t: -80, ua: -40, fa: -40 }, B: { ua: 82, fa: 82 }, eq: [{ k: 'tower', x: 160 }, { k: 'cable', from: [154, -14], to: 'hd' }, { k: 'bar', at: 'hd' }] },
    ohp: { A: { ...STAND, ua: 70, fa: -95 }, B: { ua: -85, fa: -90 }, hold: 'plate' },
    ohp_db: { A: { hip: [92, 100], t: -92, th: 0, sn: 90, ua: 70, fa: -95 }, B: { ua: -85, fa: -90 }, eq: [seat(80, 112, 107), { k: 'line', a: [84, 104], b: [80, 50], w: 6 }, { k: 'line', a: [96, 107], b: [96, FLOOR] }], hold: 'db' },
    lateral: { front: true, A: { ua: 80, fa: 84, k: 1 }, B: { ua: -2, fa: -6, k: 1 }, hold: 'db' },
    frontraise: { A: { ...STAND, ua: 86, fa: 86 }, B: { ua: -4, fa: -6 }, hold: 'db' },
    reardelt: { front: true, A: { ua: 86, fa: 88, k: 1, bent: 1 }, B: { ua: 6, fa: 4, k: 1, bent: 1 }, hold: 'db' },
    upright: { A: { ...STAND, ua: 90, fa: 88 }, B: { ua: -15, fa: 140 }, hold: 'plate' },
    shrug: { front: true, A: { ua: 88, fa: 90, k: 1, sy: 0 }, B: { ua: 88, fa: 90, k: 1, sy: -7 }, hold: 'db' },
    curl: { A: { ...STAND, ua: 92, fa: 88 }, B: { ua: 92, fa: -105 }, hold: 'plate' },
    preacher: { A: { hip: [92, 100], t: -80, th: 5, sn: 95, ua: 45, fa: 60 }, B: { fa: -100 }, eq: [seat(80, 110, 107), { k: 'line', a: [96, 68], b: [122, 92], w: 6 }, { k: 'line', a: [118, 90], b: [118, FLOOR], w: 4 }], hold: 'plate' },
    cablecurl: { A: { ...STAND, ua: 92, fa: 80 }, B: { ua: 92, fa: -105 }, eq: [{ k: 'tower', x: 140 }, { k: 'cable', from: [134, 132], to: 'hd' }, { k: 'bar', at: 'hd' }] },
    concentration: { A: { hip: [88, 100], t: -62, th: 0, sn: 92, ua: 80, fa: 85 }, B: { fa: -110 }, eq: [seat(70, 100, 107)], hold: 'db' },
    pushdown: { A: { ...STAND, ua: 95, fa: -50 }, B: { ua: 95, fa: 80 }, eq: [{ k: 'tower', x: 136 }, { k: 'cable', from: [130, -14], to: 'hd' }] },
    overhead: { A: { ...STAND, ua: -100, fa: 125 }, B: { fa: -95 }, hold: 'db' },
    skull: { A: { hip: [100, 96], t: 180, th: 20, sn: 90, ua: -100, fa: 135 }, B: { fa: -95 }, eq: [bench(34, 104, 122, 104)], hold: 'plate' },
    benchdip: { A: { hip: [81, 92], t: -94, ank: [150, 136], ank2: [150, 136], hand: [72, 96], eb: -1 }, B: { hip: [83, 116], t: -93 }, eq: [bench(36, 100, 74, 100)] },
    wrist: { A: { hip: [90, 100], t: -60, th: 0, sn: 90, ua: 80, fa: 0, wr: 60 }, B: { wr: -35 }, eq: [seat(70, 104, 107)], hold: 'db' },
    squat: { A: { ...STAND }, B: { hip: [74, 104], t: -55 }, bar: 'back' },
    goblet: { A: { ...STAND }, B: { hip: [76, 106], t: -62 }, bar: 'chest' },
    legpress: { A: { hip: [70, 112], t: -155, ank: [96, 90], ank2: [96, 90], ft: -45, ua: 30, fa: 20 }, B: { ank: [116, 70], ank2: [116, 70] }, eq: [{ k: 'line', a: [80, 118], b: [24, 92], w: 7 }, { k: 'line', a: [70, 118], b: [100, 118], w: 6 }, { k: 'line', a: [40, 140], b: [160, 40], w: 3, dim: 1 }], sled: 1 },
    legext: { A: { hip: [90, 100], t: -98, th: 0, sn: 90, ua: 85, fa: 30 }, B: { sn: 8 }, eq: [seat(66, 124, 108), { k: 'line', a: [72, 104], b: [66, 56], w: 6 }, { k: 'line', a: [96, 108], b: [96, FLOOR] }], pad: 1 },
    legcurl: { A: { hip: [98, 96], t: 180, th: 0, sn: 0, ua: 100, fa: 80, flip: true }, B: { sn: -110 }, eq: [bench(36, 104, 136, 104)], pad: 1 },
    lunge: { A: { hip: [94, 78], t: -92, ank: [122, 137], ank2: [68, 137], ua: 90, fa: 90 }, B: { hip: [94, 102] }, hold: 'db' },
    rdl: { A: { ...STAND, ua: 92, fa: 92 }, B: { hip: [84, 80], t: -25 }, hold: 'plate' },
    calf: { A: { ...STAND, ft: 0, ua: 90, fa: 90 }, B: { hip: [98, 68], ank: [102, 131], ank2: [98, 131], ft: 37 }, eq: [{ k: 'rect', r: [104, 137, 34, 7, 2] }] },
    hipthrust: { A: { hip: [96, 121], t: -140, ank: [130, 137], ank2: [126, 137], ua: 60, fa: 20 }, B: { hip: [104, 97], t: -178 }, eq: [bench(36, 100, 66, 100)], bar: 'hip' },
    crunch: { A: { hip: [110, 133], t: 180, ank: [142, 136], ank2: [138, 136], uaR: 160, faR: 200 }, B: { t: -150 } },
    legraise: { A: { hip: [100, 133], t: 180, th: 0, sn: 0, ua: 0, fa: 0 }, B: { th: -80, sn: -80 } },
    plank: { A: { hip: [104, 116], t: -174, ank: [164, 136], ank2: [164, 136], ua: 90, fa: 180, flip: true }, B: { hip: [104, 114.5] }, slow: 1 },
  };
  // أشكال تعتمد على غيرها
  P.bench_db = { ...P.bench, hold: 'db' };
  P.incline_db = { ...P.incline, hold: 'db' };
  P.curl_db = { ...P.curl, hold: 'db' };
  P.hammer = { ...P.curl, hold: 'dbv' };
  P.goblet.hold = 'none';

  function poseAt(def, s) {
    const A = def.A, B = { ...A, ...def.B }, out = {};
    for (const k of new Set([...Object.keys(A), ...Object.keys(B)])) {
      const a = A[k], b = B[k];
      if (Array.isArray(a)) out[k] = a.map((v, i) => v + (b[i] - v) * s);
      else if (typeof a === 'number') out[k] = a + (b - a) * s;
      else out[k] = a;
    }
    return out;
  }

  /** يحسب كل المفاصل لوضعية جانبية */
  function joints(p, def = {}) {
    const J = {};
    J.hip = p.hip;
    J.sh = pt(J.hip, p.t, L.t);
    J.neck = pt(J.sh, p.t, L.neck);
    J.head = pt(J.neck, p.t, L.head);
    // الرجلين
    for (const [s, th, sn, ank, ft] of [['', p.th, p.sn, p.ank, p.ft], ['2', p.th2 ?? p.th, p.sn2 ?? p.sn, p.ank2 || p.ank, p.ft2 ?? p.ft]]) {
      let a1 = th ?? 90, a2 = sn ?? 90;
      if (ank) [a1, a2] = ik(J.hip, ank, L.th, L.sn, -1);
      J['kn' + s] = pt(J.hip, a1, L.th);
      J['an' + s] = pt(J['kn' + s], a2, L.sn);
      J['toe' + s] = pt(J['an' + s], ft ?? (p.flip ? a2 + 90 : a2 - 90), L.foot);
      J['th' + s] = a1; J['sn' + s] = a2;
    }
    // اليدين: بزوايا، أو بالنسبة للجذع، أو لنقطة ثابتة (حركة عكسية)
    for (const s of ['', '2']) {
      let ua = (s ? (p.ua2 ?? p.ua) : p.ua) ?? 90, fa = (s ? (p.fa2 ?? p.fa) : p.fa) ?? 90;
      if (p.uaR !== undefined) { ua = p.t + p.uaR; fa = p.t + p.faR; }
      if (p.hand) [ua, fa] = ik(J.sh, p.hand, L.ua, L.fa, p.eb || 1);
      // البار على الظهر/الدمبل على الصدر: اليدين تمسكه
      if (def.bar === 'back') [ua, fa] = ik(J.sh, pt(J.sh, p.t - 90, 7), L.ua, L.fa, -1);
      if (def.bar === 'chest') [ua, fa] = ik(J.sh, pt(pt(J.sh, p.t + 90, 10), p.t, -12), L.ua, L.fa, 1);
      J['el' + s] = pt(J.sh, ua, L.ua);
      J['hd' + s] = pt(J['el' + s], fa, L.fa);
      J['ua' + s] = ua; J['fa' + s] = fa;
    }
    if (p.wr !== undefined) { J.wrist = J.hd; J.hd = pt(J.wrist, p.wr, L.hand); }
    return J;
  }

  // ------------------------------------------------------------ الرسم
  const seg = (a, b, cls, w) => `<line x1="${a[0].toFixed(1)}" y1="${a[1].toFixed(1)}" x2="${b[0].toFixed(1)}" y2="${b[1].toFixed(1)}" class="${cls}" stroke-width="${w}"/>`;
  const circ = (c, r, cls) => `<circle cx="${c[0].toFixed(1)}" cy="${c[1].toFixed(1)}" r="${r}" class="${cls}"/>`;
  function along(a, b, f0, f1, off, side) {
    const an = ang(a, b) + side * 90;
    const o = [Math.cos(R(an)) * off, Math.sin(R(an)) * off];
    const p0 = [a[0] + (b[0] - a[0]) * f0 + o[0], a[1] + (b[1] - a[1]) * f0 + o[1]];
    const p1 = [a[0] + (b[0] - a[0]) * f1 + o[0], a[1] + (b[1] - a[1]) * f1 + o[1]];
    return [p0, p1];
  }
  /** العضلة الشغّالة (أحمر) فوق الجسم - جانبي */
  function muscles(J, p, list) {
    const out = [], fl = p.flip ? -1 : 1, hl = (a, b, w = 5) => out.push(seg(a, b, 'an-hl', w));
    const torso = (f0, f1, front) => { const [a, b] = along(J.hip, J.sh, f0, f1, 4.2, front ? fl : -fl); hl(a, b, 6); };
    for (const m of list) {
      if (m === 'chest') torso(0.55, 0.92, 1);
      else if (m === 'upper_chest') torso(0.72, 0.96, 1);
      else if (m === 'abs') torso(0.08, 0.52, 1);
      else if (m === 'back' || m === 'lats') torso(0.4, 0.88, 0);
      else if (m === 'lower_back') torso(0.06, 0.42, 0);
      else if (m === 'traps') { const [a, b] = along(J.sh, J.neck, -0.2, 1.1, 3.5, -fl); hl(a, b, 5); }
      else if (['shoulders', 'front_delt', 'side_delt', 'rear_delt'].includes(m)) out.push(circ(J.sh, 6, 'an-hl-f'));
      else if (m === 'biceps') { const [a, b] = along(J.sh, J.el, 0.18, 0.88, 3, -1); hl(a, b); }
      else if (m === 'triceps') { const [a, b] = along(J.sh, J.el, 0.18, 0.88, 3, 1); hl(a, b); }
      else if (m === 'forearms') { const [a, b] = along(J.el, J.wrist || J.hd, 0.12, 0.85, 0, 1); hl(a, b, 6); }
      else if (m === 'quads') { const [a, b] = along(J.hip, J.kn, 0.15, 0.85, 3.5, -fl); hl(a, b, 6); }
      else if (m === 'hamstrings') { const [a, b] = along(J.hip, J.kn, 0.15, 0.85, 3.5, fl); hl(a, b, 6); }
      else if (m === 'glutes') { const [a] = along(J.hip, J.sh, 0.02, 0.02, 5, -fl); out.push(circ(a, 5.5, 'an-hl-f')); }
      else if (m === 'calves') { const [a, b] = along(J.kn, J.an, 0.12, 0.6, 3.2, fl); hl(a, b, 6); }
    }
    return out.join('');
  }
  function holdAt(type, c, a = 0) {
    if (type === 'plate') return circ(c, 9.5, 'an-plate') + circ(c, 2.4, 'an-hole');
    if (type === 'db' || type === 'dbv') {
      const r = type === 'dbv' ? 90 : a;
      return `<g transform="translate(${c[0].toFixed(1)} ${c[1].toFixed(1)}) rotate(${r})"><rect x="-8" y="-1.6" width="16" height="3.2" rx="1.5" class="an-eqf"/><rect x="-9.5" y="-4.6" width="5" height="9.2" rx="2" class="an-plate"/><rect x="4.5" y="-4.6" width="5" height="9.2" rx="2" class="an-plate"/></g>`;
    }
    return '';
  }
  function equipment(eq, J, after) {
    let s = '';
    for (const e of eq || []) {
      if (!!after !== (e.k === 'cable' || e.k === 'bar')) continue;
      if (e.k === 'bench') {
        s += seg([e.x1, e.y1], [e.x2, e.y2], 'an-eq', 7);
        if (e.legs) { s += seg([e.x1 + 8, e.y1], [e.x1 + 8, FLOOR], 'an-eq', 4) + seg([e.x2 - 8, e.y2], [e.x2 - 8, FLOOR], 'an-eq', 4); }
      } else if (e.k === 'line') s += seg(e.a, e.b, e.dim ? 'an-eq dim' : 'an-eq', e.w || 4);
      else if (e.k === 'rect') s += `<rect x="${e.r[0]}" y="${e.r[1]}" width="${e.r[2]}" height="${e.r[3]}" rx="${e.r[4] || 0}" class="an-eqf"/>`;
      else if (e.k === 'circle') s += circ(e.c, e.r, 'an-eqf');
      else if (e.k === 'tower') s += `<rect x="${e.x - 3}" y="-18" width="6" height="${FLOOR + 18}" rx="2" class="an-eqf dim"/>`;
      else if (e.k === 'cable') { const to = J[e.to]; s += seg(e.from, to, 'an-cable', 1.6) + circ(e.from, 4, 'an-eqf'); }
      else if (e.k === 'bar') { const c = J[e.at]; s += `<rect x="${(c[0] - 2.5).toFixed(1)}" y="${(c[1] - 2.5).toFixed(1)}" width="5" height="5" rx="1.5" class="an-plate"/>`; }
    }
    return s;
  }

  function drawSide(def, p, ex) {
    const J = joints(p, def);
    const W = { torso: 15, ua: 8.5, fa: 7.5, th: 11.5, sn: 9.5, foot: 5.5 };
    let s = `<line x1="-10" y1="${FLOOR + 2}" x2="210" y2="${FLOOR + 2}" class="an-floor"/>`;
    s += equipment(def.eq, J, false);
    // الأطراف البعيدة (فاتحة)
    s += seg(J.hip, J.kn2, 'an-far', W.th) + seg(J.kn2, J.an2, 'an-far', W.sn) + seg(J.an2, J.toe2, 'an-far', W.foot);
    s += seg(J.sh, J.el2, 'an-far', W.ua) + seg(J.el2, J.hd2, 'an-far', W.fa);
    if (def.hold === 'db' || def.hold === 'dbv') s += `<g opacity=".45">${holdAt(def.hold, J.hd2, J.fa2)}</g>`;
    // الجسم
    s += seg(J.hip, J.sh, 'an-body', W.torso) + seg(J.sh, J.neck, 'an-body', 6.5);
    s += circ(J.head, L.head, 'an-head');
    const face = pt(J.head, p.t + (p.flip ? -90 : 90), L.head - 1.5);
    s += circ(face, 1.6, 'an-face');
    s += seg(J.hip, J.kn, 'an-body', W.th) + seg(J.kn, J.an, 'an-body', W.sn) + seg(J.an, J.toe, 'an-body', W.foot);
    s += muscles(J, p, ex.muscles || []);
    // الحمل والأجهزة المتحركة
    if (def.pad) s += circ(pt(J.an, J.sn + (p.flip ? -90 : -90), 6), 5, 'an-eqf');
    if (def.sled) { const d = [Math.cos(R(-45)) * 12, Math.sin(R(-45)) * 12]; const a = [J.an[0] + 6, J.an[1] - 2]; s += seg([a[0] - d[1], a[1] + d[0]], [a[0] + d[1], a[1] - d[0]], 'an-eq', 6); }
    if (def.bar === 'back') { const b = pt(J.sh, p.t - 90, 7); s += holdAt('plate', b); }
    if (def.bar === 'hip') { const b = pt(J.hip, p.t + 90, 9); s += holdAt('plate', b); }
    s += equipment(def.eq, J, true);
    s += seg(J.sh, J.el, 'an-body', W.ua) + seg(J.el, J.wrist || J.hd, 'an-body', W.fa);
    if (J.wrist) s += seg(J.wrist, J.hd, 'an-body', 6);
    s += muscles(J, p, (ex.muscles || []).filter(m => ['biceps', 'triceps', 'forearms'].includes(m)));
    if (def.bar === 'chest') { const b = pt(pt(J.sh, p.t + 90, 9), p.t, -12); s += holdAt('db', b, 90); }
    if (def.hold && def.hold !== 'none') s += holdAt(def.hold, J.hd, J.fa + 90);
    return s;
  }

  /** منظر أمامي (رفرفة جانبي، فراشة، ترابيس) */
  function drawFront(def, p, ex) {
    const cx = 100, bent = p.bent ? 1 : 0;
    const hipY = 76, shY = (bent ? 50 : 36) + (p.sy || 0), shW = 17;
    const W = { torso: 26, ua: 8.5, fa: 7.5, leg: 11 };
    let s = `<line x1="-10" y1="${FLOOR + 2}" x2="210" y2="${FLOOR + 2}" class="an-floor"/>`;
    const head = [cx, (bent ? 50 : 36) - 16 + (bent ? 4 : 0)];
    // الرجلين
    for (const d of [-1, 1]) s += seg([cx + d * 8, hipY], [cx + d * 11, 108], 'an-body', W.leg) + seg([cx + d * 11, 108], [cx + d * 12, 136], 'an-body', 9.5) + seg([cx + d * 12, 137], [cx + d * 18, 138], 'an-body', 5);
    // الجذع (مثلث مقلوب ناعم)
    s += `<path d="M${cx - shW - 2} ${shY} Q${cx} ${shY - 5} ${cx + shW + 2} ${shY} L${cx + 10} ${hipY} L${cx - 10} ${hipY} Z" class="an-bodyf"/>`;
    s += seg([cx, shY - 2], [cx, head[1] + 6], 'an-body', 7) + circ(head, L.head, 'an-head');
    const m = ex.muscles || [];
    if (m.includes('traps')) for (const d of [-1, 1]) s += seg([cx + d * 3, shY - 6], [cx + d * 13, shY - 1], 'an-hl', 5);
    if (m.includes('chest')) for (const d of [-1, 1]) s += `<ellipse cx="${cx + d * 8}" cy="${shY + 8}" rx="7" ry="5" class="an-hl-f"/>`;
    // اليدين (اليسرى مرآة)
    for (const d of [1, -1]) {
      const sh = [cx + d * shW, shY];
      const ua = p.ua, fa = p.fa, k = p.k ?? 1;
      const el = [sh[0] + d * k * L.ua * Math.cos(R(ua)), sh[1] + L.ua * Math.sin(R(ua))];
      const hd = [el[0] + d * k * L.fa * Math.cos(R(fa)), el[1] + L.fa * Math.sin(R(fa))];
      s += seg(sh, el, 'an-body', W.ua) + seg(el, hd, 'an-body', W.fa);
      if (m.some(x => ['side_delt', 'rear_delt', 'shoulders', 'front_delt'].includes(x))) s += circ(sh, 6.5, 'an-hl-f');
      if (def.hold === 'db') s += holdAt('db', hd, 90);
    }
    if (bent) s += `<text x="${cx}" y="22" class="an-note">منحني لقدام</text>`;
    return s;
  }

  function frame(anim, ex, s) {
    const def = P[anim] || P.curl;
    const p = poseAt(def, s);
    return def.front ? drawFront(def, p, ex) : drawSide(def, p, ex);
  }

  // ------------------------------------------------------------ التشغيل: حلقة وحدة لكل الرسوم الظاهرة بالشاشة
  const live = new Set();
  let raf = 0;
  const ease = x => (1 - Math.cos(Math.PI * x)) / 2;
  function phase(t, slow) {
    const T = slow ? 4.2 : 2.6, x = (t / 1000 % T) / T; // ثبات - صعود - ثبات - نزول
    if (x < 0.12) return 0;
    if (x < 0.5) return ease((x - 0.12) / 0.38);
    if (x < 0.62) return 1;
    return ease(1 - (x - 0.62) / 0.38);
  }
  function loop(t) {
    raf = 0;
    for (const it of live) {
      if (!it.el.isConnected) { live.delete(it); continue; }
      if (!it.visible) continue;
      it.g.innerHTML = frame(it.anim, it.ex, phase(t + it.offset, P[it.anim]?.slow));
    }
    if (live.size) raf = requestAnimationFrame(loop);
  }
  const io = 'IntersectionObserver' in window ? new IntersectionObserver(es => es.forEach(e => { const it = e.target._anim; if (it) it.visible = e.isIntersecting; })) : null;
  const reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  /** يرسم تمرين داخل عنصر. static = صورة ثابتة (للقوائم الطويلة) */
  function mount(el, ex, opts = {}) {
    el.innerHTML = `<svg viewBox="0 -24 200 170" class="an-svg" role="img" aria-label="${String(ex.name).replace(/[<>"&]/g, '')}"><g></g></svg>`;
    const g = el.querySelector('g');
    if (opts.static || reduce) { g.innerHTML = frame(ex.anim, ex, opts.at ?? 0.55); return; }
    const it = { el, g, ex, anim: ex.anim, visible: !io, offset: opts.offset || 0 };
    el._anim = it;
    if (io) io.observe(el);
    live.add(it);
    g.innerHTML = frame(ex.anim, ex, 0);
    if (!raf) raf = requestAnimationFrame(loop);
  }

  window.Anim = { mount, frame, patterns: Object.keys(P) };
})();
