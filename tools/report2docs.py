#!/usr/bin/env python3
"""Write the lab report series into the repository: docs/parliament-chess-lab-report.md and .html.

    python3 tools/report2docs.py EXPORT.html docs/parliament-chess-lab-report.md docs/parliament-chess-lab-report.html

EXPORT.html is the shared doc's HTML export (the Claude Doc's Export, or the docs tool's export).
Charts and diagrams do not survive an export: docs/media/figures.json maps each one's id to a PNG
of it in docs/media. The author mention becomes a plain project credit.
"""
import json, os, re, sys, html as H
from html.parser import HTMLParser

src, md_out, html_out = sys.argv[1:4]
frag = open(src).read()

# Doc figures (charts, diagrams, uploaded images) -> their PNGs in docs/media, kept in figures.json.
# A figure the map does not know is kept as its caption with a note, so a new chart is never lost silently.
FIG = json.load(open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'docs', 'media', 'figures.json')))
def fig(m):
    cap = m.group(2)
    if m.group(1) not in FIG:
        return f'<p><em>[Figure not yet in docs/media: {cap}]</em></p>'
    path = FIG[m.group(1)]
    return f'<figure><img src="{path}" alt="{H.escape(cap, quote=True)}"><figcaption>{cap}</figcaption></figure>'
frag = re.sub(r'<figure data-embed="([^"]+)"><figcaption>(.*?)</figcaption></figure>', fig, frag, flags=re.S)
frag = re.sub(r'<img src="(blob/[^"]+)"', lambda m: f'<img src="{FIG.get(m.group(1), m.group(1))}"', frag)
frag = re.sub(r'<span data-atom="mention"[^>]*>.*?</span>', 'Parliament Chess project, with Claude Code (Claude Opus 5.5) as the pair', frag, flags=re.S)
frag = re.sub(r'<time[^>]*>(.*?)</time>', r'\1', frag)
missing = re.findall(r'(?:blob|node)/[0-9a-f-]{8,}', frag)
if missing:
    print('figures without a PNG in docs/media/figures.json:', sorted(set(missing)))

class MD(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.blocks, self.buf, self.lists, self.li = [], [], [], 0
        self.pre = False
        self.table = None
        self.cell = None
        self.href = []
        self.figure = False
    def w(self, s):
        (self.cell if self.cell is not None else self.buf).append(s)
    def flush(self, kind='p'):
        t = ''.join(self.buf).strip('\n')
        if t.strip():
            self.blocks.append((kind, t))
        self.buf = []
    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag in ('h1', 'h2', 'h3'):
            self.flush(); self.buf.append('#' * int(tag[1]) + ' ')
        elif tag == 'p':
            if not self.li and self.cell is None:
                self.flush()
            elif self.li and self.buf and not self.buf[-1].endswith(('- ', '. ', '] ')):
                self.w(' ')
        elif tag in ('strong', 'b'):
            self.w('**')
        elif tag in ('em', 'i'):
            self.w('*')
        elif tag == 'code':
            self.w('```' + a.get('class', '').replace('language-', '') + '\n' if self.pre else '`')
        elif tag == 'pre':
            self.flush(); self.pre = True
        elif tag == 'a':
            self.href.append(a.get('href', '')); self.w('[')
        elif tag in ('ul', 'ol'):
            self.flush('li' if self.li else 'p'); self.lists.append([tag, 0])
        elif tag == 'li':
            self.flush('li'); self.li += 1
            kind, n = self.lists[-1]; self.lists[-1][1] += 1
            self.buf.append('    ' * (len(self.lists) - 1) + (f'{n + 1}. ' if kind == 'ol' else '- '))
        elif tag == 'input' and a.get('type') == 'checkbox':
            self.w('[x] ' if 'checked' in a else '[ ] ')
        elif tag == 'table':
            self.flush(); self.table = []
        elif tag == 'tr':
            self.table.append([])
        elif tag in ('td', 'th'):
            self.cell = []
        elif tag == 'img':
            self.flush(); self.blocks.append(('p', f'![{a.get("alt", "")}]({a.get("src")})'))
        elif tag == 'figcaption':
            self.figure = True; self.w('*')
    def handle_endtag(self, tag):
        if tag in ('h1', 'h2', 'h3'):
            self.flush('h')
        elif tag == 'p' and not self.li and self.cell is None:
            self.flush()
        elif tag in ('strong', 'b'):
            self.w('**')
        elif tag in ('em', 'i'):
            self.w('*')
        elif tag == 'code':
            self.w('\n```' if self.pre else '`')
        elif tag == 'pre':
            self.pre = False; self.flush('pre')
        elif tag == 'a':
            self.w('](' + self.href.pop() + ')')
        elif tag == 'li':
            self.flush('li'); self.li -= 1
        elif tag in ('ul', 'ol'):
            self.lists.pop()
            if not self.lists:
                self.blocks.append(('end', ''))
        elif tag in ('td', 'th'):
            self.table[-1].append(''.join(self.cell).strip().replace('|', '\\|').replace('\n', ' ')); self.cell = None
        elif tag == 'table':
            rows = self.table; self.table = None
            out = ['| ' + ' | '.join(rows[0]) + ' |', '| ' + ' | '.join('---' for _ in rows[0]) + ' |']
            out += ['| ' + ' | '.join(r) + ' |' for r in rows[1:]]
            self.blocks.append(('p', '\n'.join(out)))
        elif tag == 'figcaption':
            self.w('*'); self.flush(); self.figure = False
    def handle_data(self, d):
        if self.pre:
            self.w(d)
        else:
            self.w(re.sub(r'\s+', ' ', d))

p = MD(); p.feed(frag); p.flush()
lines = []
prev = None
for kind, text in p.blocks:
    if kind == 'end':
        prev = 'end'; continue
    if lines:
        lines.append('\n' if (kind == 'li' and prev == 'li') else '\n\n')
    lines.append(text.rstrip())
    prev = kind
md = ''.join(lines).strip() + '\n'
md = re.sub(r'^(\s*- \[[ x]\]) +', r'\1 ', md, flags=re.M)
open(md_out, 'w').write(md)

css = """
:root{--ink:#1b1b1b;--dim:#5a5a5a;--rule:#d8d4cc;--paper:#fdfcf8;--accent:#1f4e79;--tile:#f3f0e8}
@media (prefers-color-scheme: dark){:root{--ink:#e8e6e1;--dim:#a8a49c;--rule:#3a3833;--paper:#151514;--accent:#8fb8de;--tile:#1f1e1c}}
*{box-sizing:border-box}html{background:var(--paper)}
body{margin:0;color:var(--ink);background:var(--paper);font:17px/1.6 "Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif}
main{max-width:860px;margin:0 auto;padding:40px 20px 80px}
h1{font-size:30px;line-height:1.2;margin:0 0 6px}
h1+p{color:var(--dim);font-style:italic;margin-top:0;border-bottom:1px solid var(--rule);padding-bottom:16px}
h2{font-size:22px;margin:44px 0 10px;padding-top:14px;border-top:2px solid var(--ink);font-variant:small-caps;letter-spacing:.02em}
h3{font-size:18px;margin:28px 0 8px}
p,li{max-width:76ch}a{color:var(--accent)}
code{font:14px/1.4 ui-monospace,Menlo,Consolas,monospace;background:var(--tile);padding:1px 4px;border-radius:3px}
pre{background:var(--tile);border:1px solid var(--rule);padding:12px 14px;overflow-x:auto;border-radius:4px}pre code{background:none;padding:0}
table{border-collapse:collapse;width:100%;font:15px/1.45 -apple-system,"Segoe UI",system-ui,sans-serif;margin:14px 0;display:block;overflow-x:auto}
th,td{border:1px solid var(--rule);padding:6px 9px;text-align:left;vertical-align:top}th{background:var(--tile)}
figure{margin:22px 0;text-align:center}figure img{max-width:100%;border:1px solid var(--rule);border-radius:4px;background:#fff}
figcaption{font-size:14px;color:var(--dim);font-style:italic;margin-top:6px}
li input{margin-right:6px}
"""
page = f"""<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Parliament Chess Lab Reports</title>
<style>{css}</style>
</head>
<body>
<main>
{frag}
</main>
</body>
</html>
"""
open(html_out, 'w').write(page)
print(len(md), 'chars markdown;', len(page), 'chars html;', md.count('!['), 'images')
