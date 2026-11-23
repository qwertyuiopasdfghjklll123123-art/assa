"""
يبني site/views/vendor/fa.css + خطوط الأيقونات (المستخدمة بس) - شغّله بعد ما تضيف أيقونة جديدة.
  npm i @fortawesome/fontawesome-free@6.5.1   (بأي مجلد)
  pip install fonttools brotli
  python3 tools/build_icons.py /path/to/node_modules
"""
import glob, os, re, sys
from fontTools import subset

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'site', 'views', 'vendor')
FA = os.path.join(sys.argv[1] if len(sys.argv) > 1 else 'node_modules', '@fortawesome', 'fontawesome-free')
UTIL = {'fa-solid', 'fa-regular', 'fa-brands', 'fa-spin', 'fa-fw'}
used = set()
for f in glob.glob(os.path.join(ROOT, 'site', 'views', '*.*')) + [os.path.join(ROOT, 'tools', 'build_exercises.py')]:
    if f.endswith(('.html', '.js', '.json', '.py')):
        used |= set(re.findall(r'\bfa-[a-z0-9]+(?:-[a-z0-9]+)*', open(f, encoding='utf-8').read()))
used -= UTIL
css = open(os.path.join(FA, 'css', 'all.min.css'), encoding='utf-8').read()
keep, rules = set(), []
for m in re.finditer(r'([^{}]+)\{([^{}]*)\}', css):
    sel, body = m.group(1), m.group(2)
    if sel.strip().startswith('@'):
        continue
    cm = re.fullmatch(r'content:"\\([0-9a-f]+)"', body.strip())
    if cm:
        hit = [n for n in re.findall(r'\.(fa-[a-z0-9-]+):before', sel) if n in used]
        if hit:
            keep.add(int(cm.group(1), 16))
            rules.append(','.join(f'.{n}:before' for n in hit) + '{' + body + '}')
        continue
    rules.append(sel + '{' + body + '}')
extras = re.findall(r'@(?:-webkit-)?keyframes [^{]+\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}', css)
ff = '''@font-face{font-family:"Font Awesome 6 Free";font-style:normal;font-weight:900;font-display:block;src:url(/assets/vendor/fa-solid.woff2?v=__V__) format("woff2")}
@font-face{font-family:"Font Awesome 6 Free";font-style:normal;font-weight:400;font-display:block;src:url(/assets/vendor/fa-regular.woff2?v=__V__) format("woff2")}
'''
open(os.path.join(OUT, 'fa.css'), 'w', encoding='utf-8').write('/* Font Awesome Free 6.5.1 (CC BY 4.0 / SIL OFL 1.1) */\n' + ff + '\n'.join(rules) + '\n' + '\n'.join(extras) + '\n')
for src, dst in [('fa-solid-900', 'fa-solid'), ('fa-regular-400', 'fa-regular')]:
    o = subset.Options(); o.flavor = 'woff2'; o.layout_features = ['*']
    font = subset.load_font(os.path.join(FA, 'webfonts', src + '.woff2'), o)
    s = subset.Subsetter(o); s.populate(unicodes=keep); s.subset(font)
    subset.save_font(font, os.path.join(OUT, dst + '.woff2'), o)
print('icons:', len(used), 'glyphs:', len(keep))
