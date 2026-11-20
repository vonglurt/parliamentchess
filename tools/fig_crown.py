#!/usr/bin/env python3
"""How often the crown moved after a Writ, by the position at filing: a dot and a 95% interval per row.

    sh bin/php bin/crown-theory.php --json docs/data/crown-theory.json orrery/census/results orrery/gen/results
    python3 tools/fig_crown.py docs/data/crown-theory.json docs/media/fig-crown-theory.svg   (and a .png)

Plain Python, no libraries; rsvg-convert makes the PNG.
"""
import json
import subprocess
import sys

d = json.load(open(sys.argv[1]))
OUT = sys.argv[2]
groups = [
    ("The count at filing", "crownedRateByPollWon", [("poll already won", "already won"), ("poll not won", "not yet won")]),
    ("Squares around the King under attack", "crownedRateByDanger", [("0 none", "none"), ("1 one or two", "one or two"), ("2 three or more", "three or more")]),
    ("When the Writ was filed", "crownedRateByPhase", [("1 moves 10-19", "moves 10-19"), ("2 moves 20-29", "moves 20-29"), ("3 moves 30-44", "moves 30-44"), ("4 move 45+", "move 45 or later")]),
    ("King-steps from King to Candidate", "crownedRateByDistance", [("1 near (1-2)", "1-2"), ("2 middle (3-4)", "3-4"), ("3 far (5-7)", "5-7")]),
]
SANS = "Helvetica Neue, Helvetica, Arial, sans-serif"
INK, INK2, GRID, DOT = "#1e1a14", "#6b6457", "#e3ddd0", "#2a7bd8"
W, LX, X0, X1 = 1200, 40, 470, 1010
rows = sum(len(g[2]) for g in groups)
H = 150 + rows * 40 + len(groups) * 44 + 60
px = lambda v: X0 + (X1 - X0) * v
o = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}">',
     f'<rect width="{W}" height="{H}" fill="#ffffff"/>',
     f'<text x="{LX}" y="48" font-family="{SANS}" font-size="27" font-weight="700" fill="{INK}">A Writ that has already won the count moves the crown</text>',
     f'<text x="{LX}" y="80" font-family="{SANS}" font-size="17" fill="{INK2}">Share of {d["writs"]:,} Writs in {d["games"]:,} self-play games after which the crown moved, with 95% intervals</text>']
top = 120
bottom = H - 50
for v in range(0, 101, 20):
    x = px(v / 100)
    o.append(f'<line x1="{x}" y1="{top}" x2="{x}" y2="{bottom}" stroke="{GRID}" stroke-width="1.5"/>')
    o.append(f'<text x="{x}" y="{bottom + 26}" text-anchor="middle" font-family="{SANS}" font-size="16" fill="{INK2}">{v}%</text>')
y = top + 10
for title, key, items in groups:
    y += 26
    o.append(f'<text x="{LX}" y="{y}" font-family="{SANS}" font-size="16" font-weight="700" fill="{INK2}">{title.upper()}</text>')
    y += 18
    for k, label in items:
        c = d[key][k]
        y += 22
        o.append(f'<text x="{LX + 14}" y="{y + 6}" font-family="{SANS}" font-size="19" fill="{INK}">{label}</text>')
        lo, hi, m = max(0, c["lo"]), min(1, c["hi"]), c["mean"]
        o.append(f'<line x1="{px(lo)}" y1="{y}" x2="{px(hi)}" y2="{y}" stroke="{DOT}" stroke-width="3"/>')
        for e in (lo, hi):
            o.append(f'<line x1="{px(e)}" y1="{y - 8}" x2="{px(e)}" y2="{y + 8}" stroke="{DOT}" stroke-width="3"/>')
        o.append(f'<circle cx="{px(m)}" cy="{y}" r="9" fill="{DOT}"/>')
        o.append(f'<text x="{X1 + 24}" y="{y + 6}" font-family="{SANS}" font-size="18" fill="{INK}">{m * 100:.1f}%  n={c["n"]:,}</text>')
        y += 18
o.append("</svg>")
open(OUT, "w").write("\n".join(o))
subprocess.run(["rsvg-convert", "-z", "1.5", "-o", OUT[:-4] + ".png", OUT], check=True)
print(OUT, OUT[:-4] + ".png")
