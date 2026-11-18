"""
يبني ملفات الخطوط والأيقونات المحلية لموقع واصل (بدل CDN) - شغّله بعد ما تضيف أيقونات جديدة.

المتطلبات:
  npm i @fortawesome/fontawesome-free@6.5.1 @fontsource/ibm-plex-sans-arabic   (بأي مجلد)
  pip install fonttools brotli pillow

الاستخدام:
  python3 tools/build_assets.py /path/to/node_modules

المخرجات (site/views/vendor/):
  fa.css + fa-solid/regular/brands.woff2  ← الأيقونات المستخدمة فقط (يقرأها من الكود)
  fonts.css + plex-*.woff2                ← خط IBM Plex Sans Arabic محلي
وكذلك يصغّر logo.png وأيقونات PWA.
"""
import glob
import os
import re
import shutil
import sys

from fontTools import subset
from PIL import Image

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = os.path.join(ROOT, 'site')
OUT = os.path.join(SITE, 'views', 'vendor')
NM = sys.argv[1] if len(sys.argv) > 1 else 'node_modules'
FA = os.path.join(NM, '@fortawesome', 'fontawesome-free')
PLEX = os.path.join(NM, '@fontsource', 'ibm-plex-sans-arabic', 'files')
os.makedirs(OUT, exist_ok=True)

# ------------------------------------------------------------ 1) الأيقونات المستخدمة
UTIL = {'fa-solid', 'fa-regular', 'fa-brands', 'fa-spin', 'fa-xs', 'fa-sm', 'fa-lg', 'fa-xl', 'fa-2x', 'fa-fw'}
used = set()
for f in glob.glob(os.path.join(SITE, 'views', '*.*')) + glob.glob(os.path.join(SITE, 'lib', '*.php')) + [os.path.join(SITE, 'index.php'), os.path.join(SITE, 'install.php')]:
    if not f.endswith(('.html', '.js', '.php')):
        continue
    used |= set(re.findall(r'\bfa-[a-z0-9]+(?:-[a-z0-9]+)*', open(f, encoding='utf-8').read()))
used -= UTIL

css = open(os.path.join(FA, 'css', 'all.min.css'), encoding='utf-8').read()
keep_codes = set()
out_rules = []
pos = 0
# نمر على كل القواعد: قواعد الأيقونات (content) نخلي بس المستخدمة
for m in re.finditer(r'([^{}]+)\{([^{}]*)\}', css):
    sel, body = m.group(1), m.group(2)
    if sel.startswith('@font-face') or sel.strip().startswith('@'):
        continue
    cm = re.fullmatch(r'content:"\\([0-9a-f]+)"', body.strip())
    if cm:
        names = re.findall(r'\.(fa-[a-z0-9-]+):before', sel)
        hit = [n for n in names if n in used]
        if hit:
            keep_codes.add(int(cm.group(1), 16))
            out_rules.append(','.join(f'.{n}:before' for n in hit) + '{' + body + '}')
        continue
    out_rules.append(sel + '{' + body + '}')
# الـ keyframes والـ media (بسيطة) نأخذها كما هي
extras = re.findall(r'@(?:-webkit-)?keyframes [^{]+\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}', css)
ff = '''@font-face{font-family:"Font Awesome 6 Free";font-style:normal;font-weight:900;font-display:block;src:url(/assets/vendor/fa-solid.woff2?v=__V__) format("woff2")}
@font-face{font-family:"Font Awesome 6 Free";font-style:normal;font-weight:400;font-display:block;src:url(/assets/vendor/fa-regular.woff2?v=__V__) format("woff2")}
@font-face{font-family:"Font Awesome 6 Brands";font-style:normal;font-weight:400;font-display:block;src:url(/assets/vendor/fa-brands.woff2?v=__V__) format("woff2")}
'''
open(os.path.join(OUT, 'fa.css'), 'w', encoding='utf-8').write('/* Font Awesome Free 6.5.1 (CC BY 4.0 / SIL OFL 1.1) - الأيقونات المستخدمة فقط */\n' + ff + '\n'.join(out_rules) + '\n' + '\n'.join(extras) + '\n')

for src, dst in [('fa-solid-900', 'fa-solid'), ('fa-regular-400', 'fa-regular'), ('fa-brands-400', 'fa-brands')]:
    opts = subset.Options()
    opts.flavor = 'woff2'
    opts.layout_features = ['*']
    font = subset.load_font(os.path.join(FA, 'webfonts', src + '.woff2'), opts)
    sub = subset.Subsetter(opts)
    sub.populate(unicodes=keep_codes)
    sub.subset(font)
    subset.save_font(font, os.path.join(OUT, dst + '.woff2'), opts)

# ------------------------------------------------------------ 2) خط IBM Plex Sans Arabic
faces = []
ranges = {
    'arabic': 'U+0600-06FF,U+0750-077F,U+0870-088E,U+0890-0891,U+0897-08E1,U+08E3-08FF,U+200C-200E,U+2010-2011,U+204F,U+2E41,U+FB50-FDFF,U+FE70-FE74,U+FE76-FEFC,U+102E0-102FB,U+10E60-10E7E,U+10EC2-10EC4,U+10EFC-10EFF,U+1EE00-1EEFF',
    'latin': 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD',
}
for w in (400, 500, 600, 700):
    for sub_ in ('arabic', 'latin'):
        name = f'plex-{sub_}-{w}.woff2'
        shutil.copy(os.path.join(PLEX, f'ibm-plex-sans-arabic-{sub_}-{w}-normal.woff2'), os.path.join(OUT, name))
        faces.append('@font-face{font-family:"IBM Plex Sans Arabic";font-style:normal;font-weight:%d;font-display:swap;src:url(/assets/vendor/%s?v=__V__) format("woff2");unicode-range:%s}' % (w, name, ranges[sub_]))
open(os.path.join(OUT, 'fonts.css'), 'w', encoding='utf-8').write('/* IBM Plex Sans Arabic (SIL OFL 1.1) */\n' + '\n'.join(faces) + '\n')

# ------------------------------------------------------------ 2ب) خطوط المظهر (يختارها المستخدم من «المظهر») - تنحمل بس لما تنختار
THEME_FONTS = ['alexandria', 'readex-pro', 'cairo', 'tajawal', 'almarai', 'noto-kufi-arabic', 'reem-kufi', 'el-messiri', 'marhey', 'rakkas']
for fid in THEME_FONTS:
    src = os.path.join(NM, '@fontsource', fid, 'files')
    rules = []
    for sub_, short in (('arabic', 'ar'), ('latin', 'la')):
        for w in (400, 700):
            fn = os.path.join(src, f'{fid}-{sub_}-{w}-normal.woff2')
            if not os.path.isfile(fn):
                continue
            name = f'f-{fid}-{short}-{w}.woff2'
            shutil.copy(fn, os.path.join(OUT, name))
            rules.append('@font-face{font-family:"W-%s";font-style:normal;font-weight:%d;font-display:swap;src:url(/assets/vendor/%s?v=__V__) format("woff2");unicode-range:%s}' % (fid, w, name, ranges[sub_]))
    open(os.path.join(OUT, f'font-{fid}.css'), 'w', encoding='utf-8').write(f'/* {fid} (SIL OFL 1.1) */\n' + '\n'.join(rules) + '\n')

# ------------------------------------------------------------ 3) الصور
v = os.path.join(SITE, 'views')
im = Image.open(os.path.join(v, 'logo.png')).convert('RGBA')
if im.size[0] > 256:
    im = im.resize((256, 256), Image.LANCZOS)
im.save(os.path.join(v, 'logo.png'), optimize=True)
im.save(os.path.join(v, 'logo.webp'), 'WEBP', quality=88, method=6)
for n in ('pwa-192.png', 'pwa-512.png'):
    p = os.path.join(v, n)
    Image.open(p).convert('RGB').save(p, optimize=True)

print('icons:', len(used), 'codepoints:', len(keep_codes))
for f in sorted(os.listdir(OUT)):
    print(f'  {f:28s} {os.path.getsize(os.path.join(OUT, f)) / 1024:7.1f} KB')
for n in ('logo.png', 'logo.webp', 'pwa-192.png', 'pwa-512.png'):
    print(f'  {n:28s} {os.path.getsize(os.path.join(v, n)) / 1024:7.1f} KB')
