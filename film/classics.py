"""The Net Reads the Classics: a 3Blue1Brown-style film (Manim Community, no LaTeX).

Six famous short games, read move by move by Parliament Chess's champion net. Every number on screen
comes from docs/data/classics.json (sh bin/php tools/classics.php) and the published weights in
models/; nothing is typed in by hand. The MIND panel is drawn exactly as the game draws its sidebar
(public/assets/app.js, MindSide): green +, amber -, black 0, every unit framed in its own colour.

    sh film/classics.sh          # every chapter, the live capture, the cut -> docs/media/net_classics.mp4
    manim -ql film/classics.py HowItSees     # one chapter, a quick preview

MIT License.
"""
import colorsys
import json
import os

import numpy as np
from PIL import Image, ImageDraw, ImageFont
from manim import (
    DOWN, LEFT, ORIGIN, RIGHT, UP, Circumscribe, Create, FadeIn, FadeOut, Flash, GrowFromEdge, ImageMobject,
    Indicate, LaggedStart, Line, ManimColor, Rectangle, Scene, Square, Succession, Text, Transform,
    TransformFromCopy, UpdateFromAlphaFunc, VGroup, Wait, Write, config, linear, rate_functions, smooth,
)

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.join(HERE, "..")
DATA = json.load(open(os.path.join(ROOT, "docs", "data", "classics.json")))
GAMES = {g["key"]: g for g in DATA["games"]}
NET = DATA["net"]
GEN = NET.lstrip("g").lstrip("0")

config.background_color = "#050605"

# The MIND palette (app.js MX), and the game's own gold and parchment for titles.
SCR, PHOS, DIM, AMBER, RED, SKY, WHT = "#070807", "#33F24D", "#1D8A2C", "#FFBF4D", "#F23326", "#73C7FF", "#F0F8F0"
GOLD, PARCH, BENCH = "#F0C75E", "#F3EAD6", "#0F2C24"
MONO, SERIF, GLYPHS = "Menlo", "Palatino", "Apple Symbols"
MENLO_TTC = "/System/Library/Fonts/Menlo.ttc"


def rgb(c):
    c = c.lstrip("#")
    return np.array([int(c[i:i + 2], 16) for i in (0, 2, 4)], dtype=float)


def hexc(a):
    a = np.clip(np.round(a), 0, 255).astype(int)
    return "#%02X%02X%02X" % tuple(a)


def mix(a, b, k):
    return hexc(rgb(a) + (rgb(b) - rgb(a)) * k)


def shade(v):
    """green +, amber -, black 0 (app.js shadeM)."""
    a = max(-1.0, min(1.0, float(v or 0)))
    return mix(SCR, PHOS if a >= 0 else AMBER, abs(a) ** 0.6)


def hue(i):
    """A unit's own colour: the golden angle round the wheel (app.js nodeHue)."""
    r, g, b = colorsys.hsv_to_rgb((i * 0.381966011250105) % 1, 0.62, 1)
    return hexc(np.array([r, g, b]) * 255)


def M(s, size=24, color=PHOS, weight="NORMAL"):
    return Text(s, font=MONO, font_size=size, color=color, weight=weight)


def P(s, size=34, color=PARCH, **kw):
    return Text(s, font=SERIF, font_size=size, color=color, **kw)


# Sensors v2's scalar groups, as app.js BRAIN_SCALARS lays them out.
SCALARS = [(1152, 5), (1157, 5), (1163, 5), (1168, 5), (1193, 5), (1198, 5),
           (1173, 4), (1177, 4), (1181, 6), (1187, 4), (1191, 2), (1162, 1)]
SCALAR_NAMES = ["our king zone", "their king zone", "our mobility", "their mobility", "our worst hang", "their worst hang",
                "our Writs", "their Writs", "election", "best poll", "pending wins", "in check"]
PLANES = ["our P", "our N", "our B", "our R", "our Q", "our K", "their P", "their N", "their B", "their R", "their Q",
          "their K", "our ex-K", "their ex-K", "we attack", "they attack", "ours hang", "theirs hang"]


def plane_of(i):
    """Input i as (plane, side-relative square), or (None, scalar group, k)."""
    if i < 768:
        return i // 64, i % 64
    if i < 1152:
        return 12 + (i - 768) // 64, (i - 768) % 64
    for gi, (at, n) in enumerate(SCALARS):
        if at <= i < at + n:
            return None, gi, i - at
    return None, 11, 0


# ----------------------------------------------------------------------------------------------
# The board
# ----------------------------------------------------------------------------------------------
GL = {"k": "♚", "q": "♛", "r": "♜", "b": "♝", "n": "♞", "p": "♟"}
GH = {"k": 0.80, "q": 0.78, "r": 0.66, "b": 0.76, "n": 0.70, "p": 0.58}


def fen_pieces(fen):
    out = {}
    rows = fen.split()[0].split("/")
    for ri, row in enumerate(rows):
        f = 0
        for ch in row:
            if ch.isdigit():
                f += int(ch)
            else:
                out[(7 - ri) * 8 + f] = ch
                f += 1
    return out


class Board:
    """A board drawn in the film's dark dress, with pieces that move."""

    def __init__(self, scene, fen, s=0.6, center=(-4.3, 0.5)):
        self.scene, self.s, self.c0 = scene, s, np.array([center[0], center[1], 0.0])
        self.squares = VGroup()
        for sq in range(64):
            f, r = sq & 7, sq >> 3
            light = (f + r) % 2 == 1
            q = Square(s).move_to(self.c(sq)).set_stroke(width=0).set_fill("#3A4A3F" if light else "#1E2B24", 1)
            self.squares.add(q)
        self.coords = VGroup(*[M("abcdefgh"[f], 13, "#6F8F7A").move_to(self.c(f) + DOWN * s * 0.64) for f in range(8)],
                             *[M(str(r + 1), 13, "#6F8F7A").move_to(self.c(r * 8) + LEFT * s * 0.64) for r in range(8)])
        self.frame = Rectangle(width=8 * s + 0.06, height=8 * s + 0.06).move_to(self.c0).set_stroke("#4F6B58", 2)
        self.hl = VGroup()
        self.pieces = {sq: self.glyph(ch, sq) for sq, ch in fen_pieces(fen).items()}

    def c(self, sq):
        f, r = sq & 7, sq >> 3
        return self.c0 + np.array([(f - 3.5) * self.s, (r - 3.5) * self.s, 0.0])

    def glyph(self, ch, sq):
        white = ch.isupper()
        g = Text(GL[ch.lower()], font=GLYPHS, font_size=96)
        g.scale_to_fit_height(GH[ch.lower()] * self.s)
        if white:
            g.set_fill(PARCH, 1).set_stroke("#000000", 2.2, background=True)
            return g.move_to(self.c(sq))
        # A black piece: a solid dark backing fills the glyph's holes, a pale line draws its edge.
        back = g.copy().set_fill("#0A0C0A", 1).set_stroke("#0A0C0A", 7)
        g.set_fill("#0A0C0A", 1).set_stroke("#A8C4B0", 1.3)
        return VGroup(back, g).move_to(self.c(sq))

    def mobjects(self):
        return VGroup(self.frame, self.squares, self.coords, self.hl, *self.pieces.values())

    @staticmethod
    def sq(name):
        return "abcdefgh".index(name[0]) + 8 * (int(name[1]) - 1)

    def move(self, uci, run_time=0.7):
        """Animate a move (with castling's Rook and captures); returns after playing it."""
        a, b = self.sq(uci[:2]), self.sq(uci[2:4])
        anims, gone = [], []
        mover = self.pieces.pop(a)
        if b in self.pieces:
            gone.append(self.pieces.pop(b))
        is_king = mover.get_fill_color() is not None and abs((b & 7) - (a & 7)) == 2 and self._is_king(a, mover)
        anims.append(mover.animate(path_arc=0.0).move_to(self.c(b)))
        if is_king:  # castling: the Rook comes round
            rf, rt = (b + 1, b - 1) if (b & 7) == 6 else (b - 2, b + 1)
            rook = self.pieces.pop(rf)
            anims.append(rook.animate.move_to(self.c(rt)))
            self.pieces[rt] = rook
        self.pieces[b] = mover
        new_hl = VGroup(*[Square(self.s).move_to(self.c(q)).set_stroke(width=0).set_fill(GOLD, 0.28) for q in (a, b)])
        self.scene.play(*anims, *[FadeOut(g, scale=0.4) for g in gone], Transform(self.hl, new_hl), run_time=run_time)

    def _is_king(self, a, mover):
        return getattr(mover, "_kind", None) == "k"

    def tag_kings(self, fen):
        for sq, ch in fen_pieces(fen).items():
            if sq in self.pieces:
                self.pieces[sq]._kind = ch.lower()


# ----------------------------------------------------------------------------------------------
# MIND: app.js MindSide, drawn at film scale. Geometry in the sidebar's own pixels (360 x 486).
# ----------------------------------------------------------------------------------------------
PW, PH = 360, 486


def in_cell(i):
    pl = plane_of(i)
    if pl[0] is not None:
        b, rs = pl
        return 10 + (b % 6) * 38 + (rs & 7) * 4, 52 + (b // 6) * 38 + (7 - (rs >> 3)) * 4, 3
    _, gi, k = pl
    return 246 + (0 if gi < 6 else 1) * 54 + k * 7, 52 + (gi % 6) * 18, 6


def h1_cell(j):
    return 10 + (j % 16) * 9, 190 + (j // 16) * 9


def h2_cell(k):
    return 178 + (k % 5) * 11, 190 + (k // 5) * 11


def out_cell(r):
    return (256, 188, 14) if r < 0 else (257, 214 + r * 19, 12)


def panel_background(path, scale=4):
    """The panel's still parts as one image: glass, scanlines, the dark input cells, the legend."""
    W, H = PW * scale, PH * scale
    im = Image.new("RGB", (W, H), tuple(int(x) for x in rgb(DIM)))
    d = ImageDraw.Draw(im)
    d.rectangle([scale, scale, W - scale - 1, H - scale - 1], fill=tuple(int(x) for x in rgb(SCR)))
    for y in range(2, PH - 1, 3):
        d.rectangle([scale, y * scale, W - scale - 1, (y + 1) * scale - 1], fill=(11, 14, 11))
    cell = (14, 24, 15)
    for b in range(18):
        for q in range(64):
            x0, y0 = 10 + (b % 6) * 38 + (q & 7) * 4, 52 + (b // 6) * 38 + (7 - (q >> 3)) * 4
            d.rectangle([x0 * scale, y0 * scale, (x0 + 3) * scale - 1, (y0 + 3) * scale - 1], fill=cell)
    for gi, (at, n) in enumerate(SCALARS):
        for k in range(n):
            x0, y0 = 246 + (0 if gi < 6 else 1) * 54 + k * 7, 52 + (gi % 6) * 18
            d.rectangle([x0 * scale, y0 * scale, (x0 + 6) * scale - 1, (y0 + 6) * scale - 1], fill=cell)
    f9 = ImageFont.truetype(MENLO_TTC, 9 * scale)
    dim = tuple(int(x) for x in rgb(DIM))
    for r, w in enumerate(["value", "net rank", "net's say", "who", "events"]):
        d.text((10 * scale, (396 + r * 14) * scale), w, font=f9, fill=dim, anchor="lm")
    d.text((10 * scale, (PH - 12) * scale), "green +, amber −, black 0; white frame: the move played", font=f9, fill=dim, anchor="lm")
    im.save(path)
    return path


class Mind:
    """The panel as mobjects: a still background, and the parts that change with every position."""

    def __init__(self, scene, top_left=(-1.45, 3.35), width=4.75):
        self.scene = scene
        self.k = width / PW
        self.o = np.array([top_left[0], top_left[1], 0.0])
        bg_path = os.path.join(HERE, "media", "classics_panel.png")
        os.makedirs(os.path.dirname(bg_path), exist_ok=True)
        if not os.path.exists(bg_path):
            panel_background(bg_path)
        self.bg = ImageMobject(bg_path).scale_to_fit_width(width)
        self.bg.move_to(self.p(PW / 2, PH / 2))
        # Units: an outer frame in the unit's colour, an inner square shaded by its sum.
        self.f1, self.u1 = VGroup(), VGroup()
        for j in range(160):
            x0, y0 = h1_cell(j)
            self.f1.add(self.sq(x0, y0, 8, hue(j)))
            self.u1.add(self.sq(x0 + 1, y0 + 1, 6, SCR))
        self.f2, self.u2 = VGroup(), VGroup()
        for k in range(40):
            x0, y0 = h2_cell(k)
            self.f2.add(self.sq(x0, y0, 10, hue(160 + k)))
            self.u2.add(self.sq(x0 + 1, y0 + 1, 8, SCR))
        self.lit, self.wires, self.rows, self.cols = VGroup(), VGroup(), VGroup(), VGroup()
        self.vsq = self.sq(256, 188, 14, SCR)
        self.head = VGroup()
        self.trace = None
        self.plies = []

    def p(self, x, y):
        return self.o + np.array([x * self.k, -y * self.k, 0.0])

    def sq(self, x0, y0, s, color):
        q = Square(s * self.k).set_stroke(width=0).set_fill(color, 1)
        return q.move_to(self.p(x0 + s / 2, y0 + s / 2))

    def text(self, s, x, y, px=10, color=PHOS, weight="NORMAL", align="left"):
        t = M(s, px * self.k / 0.0138, color, weight)
        if align == "left":
            return t.move_to(self.p(x, y), aligned_edge=LEFT).shift(RIGHT * 0)
        return t.move_to(self.p(x, y), aligned_edge=RIGHT)

    def all(self):
        return [self.bg, self.wires, self.f1, self.u1, self.f2, self.u2, self.lit, self.vsq, self.rows, self.cols, self.head]

    def add(self):
        self.scene.add(*self.all())

    # -- one position --------------------------------------------------------------------------
    def build_parts(self, ply, game):
        t = ply["trace"]
        side = "White" if ply["side"] == 1 else "Black"
        mv = f"{(ply['ply'] + 1) // 2}{'.' if ply['side'] == 1 else '…'} {ply['san']}"
        head = VGroup(
            self.text(f"MIND  ply {ply['ply']}  {side}", 10, 14, 12, PHOS, "BOLD"),
            self.text(f"GEN {GEN}", PW - 10, 14, 12, AMBER, "BOLD", "right"),
            self.text(f"{mv}  history, read by the net", 10, 31, 10, PHOS),
            self.text(f"inputs  {len(t['features'])} of 1,203", 10, 44, 10, DIM),
            self.text("transform  1203 > 160 > 40 > moves", 10, 177, 10, DIM),
            self.text(f"decisions, the last {ply['ply']} moves", 10, 378, 10, DIM),
        )
        lit = VGroup()
        for i in t["features"]:
            x0, y0, w = in_cell(i)
            lit.add(self.sq(x0, y0, w, PHOS))
        # The wires: inputs -> layer 1 (the strongest 30), layer 1 -> 2 (100), layer 2 -> outputs.
        top = lambda lst: max([abs(e[-1]) for e in lst] + [1e-9])
        wk = lambda v, mx: ((abs(v) / mx) ** 0.8) * 0.75 + 0.2
        wires = VGroup()

        def wire(a, b, col, k, neg):
            ln = Line(self.p(*a), self.p(*b), stroke_width=1.1).set_stroke(mix(SCR, col, k), opacity=1)
            if neg:
                ln.set_stroke(opacity=0.55)
            wires.add(ln)
        ins = t["in"][:30]
        mi = top(ins)
        for i, j, w in ins:
            x0, y0, s = in_cell(i)
            hx, hy = h1_cell(j)
            wire((x0 + s / 2, y0 + s / 2), (hx + 4, hy + 4), hue(i), wk(w, mi) * 0.5, w < 0)
        mids = t["mid"][:100]
        mm = top(mids)
        for j, k, w in mids:
            ax, ay = h1_cell(j)
            bx, by = h2_cell(k)
            wire((ax + 4, ay + 4), (bx + 5, by + 5), hue(j), wk(w, mm), w < 0)
        rows = self.rows_for(ply)
        outs = [(k, -1, w) for k, w in t["val"]]
        for ri, r in enumerate(rows):
            outs += [(k, ri, w) for k, w in r.get("edges", [])[:4]]
        mo = top(outs)
        for k, r, w in outs:
            bx, by = h2_cell(k)
            ox, oy, s = out_cell(r)
            wire((bx + 5, by + 5), (ox + s / 2, oy + s / 2), hue(160 + k), wk(w, mo), w < 0)
        # The outputs: the value, and the policy's favourites (the search's score colours a square).
        rowg = VGroup(self.text(f"value {ply['value']:+.2f}", 276, 195, 10, PHOS))
        maxp = max(r["p"] for r in rows)
        for ri, r in enumerate(rows):
            ox, oy, s = out_cell(ri)
            played = r["uci"] == ply["uci"]
            sv = r["search"] / 150 if r.get("search") is not None else r["p"] / maxp
            if played:
                rowg.add(self.sq(ox - 2, oy - 2, s + 4, WHT), self.sq(ox - 1, oy - 1, s + 2, SCR))
            rowg.add(self.sq(ox, oy, s, shade(sv)))
            rowg.add(self.text(r["san"], 276, oy + 6, 10, WHT if played else PHOS, "BOLD" if played else "NORMAL"))
            rowg.add(self.text(f"{100 * r['p']:.1f}%", PW - 10, oy + 6, 10, WHT if played else DIM, "NORMAL", "right"))
        return head, lit, wires, rowg

    @staticmethod
    def rows_for(ply):
        top = ply["trace"]["top"]
        rows = top[:7]
        if not any(r["uci"] == ply["uci"] for r in rows):
            rows = rows + [r for r in top if r["uci"] == ply["uci"]]
        else:
            rows = top[:8] if len(top) >= 8 else top
        return rows[:8]

    def column(self, ply, n):
        """The decisions strip: one column a move (value, net rank, net's say, who, events)."""
        x = 74 + n * 7
        val = ply["side"] * ply["value"]
        corr = ply["side"] * 400 * max(-2, min(2, ply["raw"]))
        rank = ply["rank"]
        san = ply["san"]
        ev = RED if "x" in san else SKY if ("+" in san or "#" in san) else None
        cells = [shade(val), shade(1 if rank == 1 else max(0.08, 1 - (rank - 1) / 12)), shade(corr / 200), WHT, ev]
        col = VGroup()
        for r, c in enumerate(cells):
            if c:
                q = Rectangle(width=5 * self.k, height=10 * self.k).set_stroke(width=0).set_fill(c, 1)
                col.add(q.move_to(self.p(x + 2.5, 391 + r * 14 + 5)))
        return col

    def show(self, ply, game, n, run_time=1.4, first=False):
        """Compute a position: the inputs light, the wires carry, the units take their colours."""
        t = ply["trace"]
        head, lit, wires, rows = self.build_parts(ply, game)
        pre1 = t["pre1"]
        pre2 = t["pre2"]
        old_lit, old_w, old_rows, old_head = self.lit, self.wires, self.rows, self.head
        sc = self.scene
        anims = [FadeOut(old_lit, run_time=run_time * 0.25), FadeOut(old_w, run_time=run_time * 0.25),
                 FadeOut(old_rows, run_time=run_time * 0.25), FadeOut(old_head, run_time=run_time * 0.2)]
        anims.append(FadeIn(head, run_time=run_time * 0.3))
        anims.append(LaggedStart(*[FadeIn(q, scale=2.2) for q in lit], lag_ratio=0.6 / max(1, len(lit)), run_time=run_time * 0.45))
        anims.append(Succession(Wait(run_time=run_time * 0.15),
                                LaggedStart(*[Create(w) for w in wires], lag_ratio=0.004, run_time=run_time * 0.75)))
        # The transform's colour mapping: each unit's square walks to its new shade, in index order.
        anims.append(Succession(Wait(run_time=run_time * 0.25),
                                LaggedStart(*[q.animate.set_fill(shade(pre1[j]), 1) for j, q in enumerate(self.u1)],
                                            lag_ratio=0.012, run_time=run_time * 0.5)))
        anims.append(Succession(Wait(run_time=run_time * 0.5),
                                LaggedStart(*[q.animate.set_fill(shade(pre2[k]), 1) for k, q in enumerate(self.u2)],
                                            lag_ratio=0.03, run_time=run_time * 0.3)))
        anims.append(Succession(Wait(run_time=run_time * 0.7),
                                self.vsq.animate(run_time=run_time * 0.2).set_fill(shade(ply["value"]), 1),
                                FadeIn(rows, shift=LEFT * 0.08, run_time=run_time * 0.2)))
        col = self.column(ply, n)
        anims.append(FadeIn(col, run_time=run_time * 0.3))
        sc.play(*anims)
        sc.remove(old_lit, old_w, old_rows, old_head)
        self.lit, self.wires, self.rows, self.head = lit, wires, rows, head
        self.cols.add(col)
        self.ply = ply

    def played_row(self):
        """The played move's square in the outputs, for pointing at."""
        rows = self.rows_for(self.ply)
        for ri, r in enumerate(rows):
            if r["uci"] == self.ply["uci"]:
                ox, oy, s = out_cell(ri)
                return self.p(ox + s / 2, oy + s / 2), ri
        return self.p(256, 230), 0

    def row_box(self, ri, width_px=100):
        ox, oy, s = out_cell(ri)
        r = Rectangle(width=(PW - 10 - ox + 6) * self.k, height=(s + 6) * self.k)
        return r.move_to(self.p((ox - 3 + PW - 7) / 2, oy + s / 2))


# ----------------------------------------------------------------------------------------------
# The film's chrome: captions, chapter cards, the "would play" ticker, stamps
# ----------------------------------------------------------------------------------------------
class Chrome:
    def __init__(self, scene):
        self.scene = scene
        self.cap = None

    def caption(self, s, wait=0.0, color=PARCH, size=30):
        sc = self.scene
        t = P(s, size, color)
        if t.width > 12.6:
            t.scale_to_fit_width(12.6)
        bar = Rectangle(width=t.width + 0.8, height=t.height + 0.42).set_fill("#081A15", 0.92).set_stroke(GOLD, 1.2, opacity=0.5)
        g = VGroup(bar, t).move_to(DOWN * 3.48)
        t.move_to(bar)
        if self.cap is not None:
            sc.play(FadeOut(self.cap, run_time=0.25), FadeIn(g, run_time=0.35))
        else:
            sc.play(FadeIn(g, shift=UP * 0.1), run_time=0.4)
        self.cap = g
        if wait:
            sc.wait(wait)

    def clear(self):
        if self.cap is not None:
            self.scene.play(FadeOut(self.cap), run_time=0.3)
            self.cap = None

    def chapter(self, n, title, sub=""):
        """A chapter card: the number punches in, the title types, a phosphor sweep wipes it off."""
        sc = self.scene
        num = M(f"{n:02d}", 120, PHOS, "BOLD").move_to(UP * 0.9)
        bar = Rectangle(width=14.4, height=0.06).set_stroke(width=0).set_fill(PHOS, 1).move_to(UP * 0.05)
        tt = P(title, 64, PARCH).move_to(DOWN * 0.75)
        st = M(sub, 22, DIM).move_to(DOWN * 1.55)
        glow = num.copy().set_stroke(PHOS, 14, opacity=0.18)
        sc.play(FadeIn(num, scale=2.4), FadeIn(glow, scale=2.6), GrowFromEdge(bar, LEFT), run_time=0.55, rate_func=rate_functions.ease_out_back)
        sc.play(Write(tt), FadeIn(st, shift=UP * 0.15), run_time=0.9)
        sc.wait(1.0)
        wipe = Rectangle(width=14.4, height=8.2).set_stroke(width=0).set_fill(PHOS, 1).move_to(LEFT * 14.4)
        sc.add(wipe)
        sc.play(wipe.animate.move_to(ORIGIN), run_time=0.35, rate_func=rate_functions.ease_in_quad)
        sc.remove(num, glow, bar, tt, st)
        sc.play(wipe.animate.move_to(RIGHT * 14.4), run_time=0.35, rate_func=rate_functions.ease_out_quad)
        sc.remove(wipe)

    def stamp(self, s, color, at=None, size=22, angle=0.0, slot=0):
        """A big word slammed onto the frame."""
        sc = self.scene
        at = at if at is not None else [5.25, -1.45 - 0.85 * slot, 0]  # the empty foot of the right-hand column
        t = M(s, size, color, "BOLD").move_to(at).rotate(angle)
        if t.width > 3.2:
            t.scale_to_fit_width(3.2)
        box = Rectangle(width=t.width + 0.4, height=t.height + 0.3).set_stroke(color, 4).set_fill("#000000", 0.75).move_to(t).rotate(0)
        g = VGroup(box, t)
        sc.play(FadeIn(g, scale=1.8), run_time=0.3, rate_func=rate_functions.ease_out_back)
        sc.play(Flash(g.get_center(), color=color, line_length=0.5, num_lines=16, flash_radius=max(g.width, g.height) / 2 + 0.2), run_time=0.45)
        return g


class Ticker:
    """The right-hand column: what history played, and what each player would have played."""
    X = 3.62
    WHO = [("net", "THE NET  2 ply", "the lobby's champion"), ("deep", "ONE PLY DEEPER", "the same net, 3 ply"),
           ("plain", "NO NET", "hand-written, 2 ply")]

    def __init__(self, scene):
        self.scene = scene
        self.g = VGroup()

    def build(self, ply):
        x = self.X
        g = VGroup()
        g.add(M("HISTORY", 16, DIM).move_to([x, 3.2, 0], aligned_edge=LEFT))
        mv = f"{(ply['ply'] + 1) // 2}{'.' if ply['side'] == 1 else '…'} {ply['san']}"
        g.add(P(mv, 40, GOLD).move_to([x, 2.72, 0], aligned_edge=LEFT))
        g.add(M(f"policy  #{ply['rank']} of {ply['legal']}  {100 * ply['p']:.1f}%", 15, AMBER if ply["rank"] > 3 else PHOS)
              .move_to([x, 2.22, 0], aligned_edge=LEFT))
        side = "White" if ply["side"] == 1 else "Black"
        g.add(M(f"value  {ply['value']:+.2f}  ({round(50 * (1 + ply['value']))}% {side})", 15, DIM).move_to([x, 1.9, 0], aligned_edge=LEFT))
        g.add(M("WOULD PLAY  \u2713 = history's move", 13, DIM).move_to([x, 1.32, 0], aligned_edge=LEFT))
        y = 0.82
        for key, name, sub in self.WHO:
            pk = ply["picks"][key]
            ok = pk["agree"]
            mark = "✓" if ok else "✗"
            col = PHOS if ok else AMBER
            g.add(M(name, 15, DIM).move_to([x, y + 0.2, 0], aligned_edge=LEFT))
            s = pk["san"] + ("  mate" if pk["mate"] and pk["score"] > 0 else "")
            g.add(M(f"{mark} {s}", 24, col, "BOLD").move_to([x, y - 0.17, 0], aligned_edge=LEFT))
            y -= 0.82
        return g

    def show(self, ply, run_time=0.5):
        g = self.build(ply)
        self.scene.play(FadeOut(self.g, run_time=run_time * 0.5), LaggedStart(*[FadeIn(m, shift=LEFT * 0.12) for m in g], lag_ratio=0.08), run_time=run_time)
        self.g = g


# ----------------------------------------------------------------------------------------------
# A game, read move by move
# ----------------------------------------------------------------------------------------------
class ClassicScene(Scene):
    def stage(self, key, start=0, title=True):
        self.game = GAMES[key]
        plies = self.game["plies"]
        self.chrome = Chrome(self)
        self.board = Board(self, plies[start]["fen"])
        self.board.tag_kings(plies[start]["fen"])
        self.mind = Mind(self)
        self.ticker = Ticker(self)
        g = self.game
        who = f"{g['white']} – {g['black']}"
        where = f"{g['event']}{', ' + str(g['year']) if g.get('year') else ''}"
        self.title = VGroup(P(g["title"], 34, GOLD), M(f"{who}  ·  {where}", 15, DIM))
        self.title.arrange(RIGHT, buff=0.35, aligned_edge=DOWN).move_to([-6.75, 3.68, 0], aligned_edge=LEFT)
        if self.title.width > 7.9:
            self.title.scale_to_fit_width(7.9).move_to([-6.75, 3.68, 0], aligned_edge=LEFT)
        self.play(FadeIn(self.board.mobjects()), FadeIn(self.mind.bg), FadeIn(self.mind.f1), FadeIn(self.mind.u1),
                  FadeIn(self.mind.f2), FadeIn(self.mind.u2), FadeIn(self.mind.vsq), FadeIn(self.title, shift=DOWN * 0.1), run_time=0.8)
        self.add(self.mind.cols)
        self.n = 0

    def step(self, i, think=1.1, move=0.6, hold=0.2, ticker=True):
        ply = self.game["plies"][i]
        self.mind.show(ply, self.game, self.n, run_time=think)
        if ticker:
            self.ticker.show(ply, run_time=0.45)
        if move > 0:  # 0: the caller plays the move itself, after its callout
            self.board.move(ply["uci"], run_time=move)
        self.n += 1
        if hold:
            self.wait(hold)
        return ply

    def fast(self, a, b, think=0.55, move=0.3):
        for i in range(a, b):
            self.step(i, think=think, move=move, hold=0.0, ticker=False)
        self.ticker.show(self.game["plies"][b - 1], run_time=0.3)

    def point_row(self, color=WHT):
        _, ri = self.mind.played_row()
        box = self.mind.row_box(ri).set_stroke(color, 3)
        self.play(Create(box), run_time=0.4)
        return box

    def outro_fade(self):
        self.play(*[FadeOut(m) for m in self.mobjects], run_time=0.7)


# ----------------------------------------------------------------------------------------------
# The chapters
# ----------------------------------------------------------------------------------------------
class ColdOpen(ClassicScene):
    """Morphy's 16.Qb8+!!: the policy's 49th choice, the search's mate."""

    def construct(self):
        g = GAMES["opera"]
        i = next(n for n, p in enumerate(g["plies"]) if p["san"].startswith("Qb8"))
        self.stage("opera", start=i)
        self.chrome.caption("Paris, 1858. Morphy to move. A queen sacrifice is on the board.", 0.6)
        ply = self.step(i, think=2.2, move=0.0, hold=0.0, ticker=False)
        self.ticker.show(ply, run_time=0.6)
        box = self.point_row()
        s1 = self.chrome.stamp(f"POLICY #{ply['rank']} OF {ply['legal']}", AMBER)
        self.chrome.caption(f"The net's first hunch ranks Qb8+ {ply['rank']}th of {ply['legal']} moves: {100 * ply['p']:.1f}%.", 1.6)
        s2 = self.chrome.stamp("SEARCH: MATE", PHOS, slot=1)
        self.chrome.caption("Then its search looks two moves ahead, and finds the mate. It would play Qb8+ too.", 1.4)
        self.play(FadeOut(s1), FadeOut(s2), FadeOut(box), run_time=0.3)
        self.board.move(ply["uci"], run_time=0.6)
        rest = g["plies"][i + 1:]
        for p in rest:
            self.board.move(p["uci"], run_time=0.55)
        k = self.board.pieces[[sq for sq, pc in self.board.pieces.items() if getattr(pc, "_kind", "") == "k"
                               and sq >= 56][0]]
        self.play(Flash(k.get_center(), color=RED, line_length=0.45, num_lines=20, flash_radius=0.5), Indicate(k, color=RED), run_time=0.8)
        self.chrome.caption("A net trained only on its own games, in a variant of chess. Can it read the classics?", 2.0, GOLD)
        self.outro_fade()


class Title(Scene):
    def construct(self):
        a = P("The Net Reads", 92, PARCH)
        b = P("the Classics", 92, GOLD)
        g = VGroup(a, b).arrange(DOWN, buff=0.15).move_to(UP * 0.5)
        sub = M(f"six famous short games  ·  one trained net (generation {GEN})  ·  every number measured", 20, DIM).next_to(g, DOWN, buff=0.55)
        lines = VGroup(*[Line(LEFT * 7.2, RIGHT * 7.2).set_stroke(PHOS, 1, opacity=0.25).shift(UP * (y * 0.5 - 3.7)) for y in range(16)])
        self.play(LaggedStart(*[Create(l) for l in lines], lag_ratio=0.05), run_time=0.8)
        self.play(FadeIn(a, shift=RIGHT * 0.6), FadeIn(b, shift=LEFT * 0.6), run_time=0.7, rate_func=rate_functions.ease_out_back)
        self.play(Write(sub), run_time=0.9)
        self.play(Indicate(b, color=GOLD, scale_factor=1.06), run_time=0.7)
        self.wait(1.2)
        self.play(FadeOut(VGroup(a, b, sub)), FadeOut(lines, shift=DOWN * 0.5), run_time=0.6)


class HowItSees(Scene):
    """Chapter 1: 1,203 switches, 160 and 40 units, a value and a policy, and a search that decides."""

    def construct(self):
        ch = Chrome(self)
        ch.chapter(1, "How the net sees", "1,203 switches  >  160  >  40  >  a value and every move")
        g = GAMES["opera"]
        i = next(n for n, p in enumerate(g["plies"]) if p["san"].startswith("Qb8"))
        ply = g["plies"][i]
        t = ply["trace"]
        board = Board(self, ply["fen"], s=0.5, center=(-4.9, 0.9))
        board.tag_kings(ply["fen"])
        self.play(FadeIn(board.mobjects()), run_time=0.6)
        ch.caption("The Opera Game, move 16. The net does not see pieces. It sees switches.", 1.2)

        # The eighteen planes, big, on the right: each lit switch flies in from its square.
        cell, gap = 0.1, 0.22
        ox, oy = -1.35, 3.05

        def pc(b, rs):
            x = ox + (b % 6) * (8 * cell + gap) + (rs & 7) * cell + cell / 2
            y = oy - (b // 6) * (8 * cell + gap + 0.18) - (7 - (rs >> 3)) * cell - cell / 2 - 0.2
            return np.array([x, y, 0.0])
        grid = VGroup()
        labels = VGroup()
        for b in range(18):
            for q in range(64):
                grid.add(Square(cell * 0.82).set_stroke(width=0).set_fill("#0E180F", 1).move_to(pc(b, q)))
            labels.add(M(PLANES[b], 10, DIM).move_to(pc(b, 56) + UP * 0.2 + LEFT * cell * 0.4, aligned_edge=LEFT))
        self.play(FadeIn(grid, lag_ratio=0.0005), FadeIn(labels), run_time=0.9)
        lit, flights = VGroup(), []
        for f in t["features"]:
            pl = plane_of(f)
            if pl[0] is None:
                continue
            b, rs = pl
            sqr = rs if ply["side"] == 1 else rs ^ 56
            tgt = Square(cell * 0.82).set_stroke(width=0).set_fill(PHOS, 1).move_to(pc(b, rs))
            src = Square(board.s * 0.9).set_stroke(width=0).set_fill(PHOS, 0.55).move_to(board.c(sqr))
            flights.append(TransformFromCopy(src, tgt, path_arc=-0.6))
            lit.add(tgt)
        self.play(LaggedStart(*flights, lag_ratio=0.03), run_time=3.2)
        self.add(lit)
        nplane = len(lit)
        ch.caption(f"Eighteen 8×8 boards from the mover's side: its pieces, theirs, every square each side attacks, every piece left hanging.", 1.0)
        # The 51 summaries
        sx, sy = -1.35, -0.62
        sgrid, slit = VGroup(), VGroup()
        for gi, (at, n) in enumerate(SCALARS):
            for k in range(n):
                x = sx + (gi % 4) * 1.72 + k * 0.17
                y = sy - (gi // 4) * 0.52
                q = Square(0.13).set_stroke(width=0).set_fill("#0E180F", 1).move_to([x, y, 0])
                sgrid.add(q)
                if at + k in t["features"]:
                    slit.add(Square(0.13).set_stroke(width=0).set_fill(PHOS, 1).move_to([x, y, 0]))
            sgrid.add(M(SCALAR_NAMES[gi], 10, DIM).move_to([sx + (gi % 4) * 1.72 - 0.07, sy - (gi // 4) * 0.52 + 0.2, 0], aligned_edge=LEFT))
        self.play(FadeIn(sgrid), run_time=0.5)
        self.play(LaggedStart(*[FadeIn(q, scale=2) for q in slit], lag_ratio=0.15), run_time=1.0)
        cnt = M(f"{len(t['features'])} of 1,203 switches on", 26, PHOS, "BOLD").move_to([2.45, -2.55, 0])
        ch.caption("And 51 summaries: pressure on each King, mobility, the worst piece hanging, Writs, the election.", 0)
        self.play(Write(cnt), run_time=0.8)
        self.wait(1.4)
        self.play(FadeOut(VGroup(grid, labels, lit, sgrid, slit)), cnt.animate.scale(0.7).move_to([-4.9, -1.85, 0]), run_time=0.8)

        # Each lit switch adds its row of 160 learned weights.
        W = json.load(open(os.path.join(ROOT, "models", f"{NET}.json")))
        emb, b1 = np.array(W["emb"]), np.array(W["b1"])
        pitch = 0.27

        def ucell(j, x0=-1.3, y0=2.75):
            return np.array([x0 + (j % 16) * pitch, y0 - (j // 16) * pitch, 0.0])

        def vec_grid(v, x0, y0, scale=1.0, size=0.22, frames=True):
            return VGroup(*[Square(size).set_stroke(hue(j) if frames else SCR, 2.2 if frames else 0).set_fill(shade(v[j] * scale), 1)
                            .move_to(ucell(j, x0, y0)) for j in range(160)])
        acc = b1.copy()
        acc_g = vec_grid(acc, -1.3, 2.75)
        acc_lbl = M("layer 1: 160 sums", 18, DIM).next_to(acc_g, UP, buff=0.15).align_to(acc_g, LEFT)
        ch.caption("Every switch that is on adds its own row of 160 learned weights into layer 1.", 0)
        self.play(FadeIn(acc_g, lag_ratio=0.01), FadeIn(acc_lbl), run_time=0.9)
        feats = t["features"]
        show = [f for f in feats if f < 768][:3]
        for n, f in enumerate(show):
            pl = plane_of(f)
            sqr = pl[1] if ply["side"] == 1 else pl[1] ^ 56
            name = f"{PLANES[pl[0]]} on {'abcdefgh'[sqr & 7]}{(sqr >> 3) + 1}"
            row = emb[f]
            rg = vec_grid(row, 3.55, 2.75, scale=1 / max(1e-9, np.abs(row).max()), size=0.2, frames=False)
            rg.scale(0.62).move_to([5.25, 1.55 - n * 0.06, 0])
            rl = M(f"+ row {f}: {name}", 15, PHOS).next_to(rg, UP, buff=0.12)
            src = Square(board.s * 0.95).set_stroke(GOLD, 3).move_to(board.c(sqr))
            self.play(Create(src), FadeIn(rl), FadeIn(rg, shift=DOWN * 0.2), run_time=0.6)
            acc = acc + row
            self.play(TransformFromCopy(rg, acc_g.copy().set_opacity(0)), *[acc_g[j].animate.set_fill(shade(acc[j]), 1) for j in range(160)], run_time=0.7)
            self.play(FadeOut(rg), FadeOut(rl), FadeOut(src), run_time=0.3)
        rest = np.array(t["pre1"])
        more = M(f"+ {len(feats) - len(show)} more rows", 22, PHOS, "BOLD").move_to([5.2, 1.6, 0])
        self.play(FadeIn(more, scale=1.4), run_time=0.4)
        self.play(*[acc_g[j].animate.set_fill(shade(rest[j]), 1) for j in range(160)], run_time=1.2, rate_func=linear)
        self.play(FadeOut(more), run_time=0.3)
        legend = VGroup(*[Square(0.3).set_stroke(width=0).set_fill(shade(v), 1) for v in np.linspace(-1, 1, 21)]).arrange(RIGHT, buff=0.02)
        legend.move_to([2.2, -0.35, 0])
        ll = VGroup(M("− amber", 16, AMBER).next_to(legend, LEFT, buff=0.2), M("green +", 16, PHOS).next_to(legend, RIGHT, buff=0.2),
                    M("0 black", 16, DIM).next_to(legend, DOWN, buff=0.12))
        ch.caption("The colour is the sum: amber below zero, black at zero, green above.", 0)
        self.play(FadeIn(legend, lag_ratio=0.05), FadeIn(ll), run_time=0.8)
        self.wait(1.2)
        a1 = np.clip(rest, 0, 1)
        fired, sat = int((a1 > 0).sum()), int((a1 >= 1).sum())
        ch.caption(f"A unit fires only above zero, and saturates at one: {fired} of 160 fire here, {sat} at full.", 0)
        self.play(*[acc_g[j].animate.set_fill(shade(a1[j]), 1) for j in range(160)], run_time=1.0)
        self.wait(1.2)
        self.play(FadeOut(legend), FadeOut(ll), run_time=0.3)

        # Layer 2: forty units mix the 160.
        W2, b2 = np.array(W["w2"]), np.array(W["b2"])
        pre2 = np.array(t["pre2"])
        h2 = VGroup(*[Square(0.3).set_stroke(hue(160 + k), 3).set_fill(SCR, 1).move_to([3.4 + (k % 5) * 0.36, 2.75 - (k // 5) * 0.36, 0]) for k in range(40)])
        h2l = M("layer 2: 40 units", 18, DIM).next_to(h2, UP, buff=0.15).align_to(h2, LEFT)
        self.play(FadeIn(h2), FadeIn(h2l), run_time=0.5)
        wires = VGroup()
        for j, k, w in t["mid"][:110]:
            wires.add(Line(acc_g[j].get_center(), h2[k].get_center(), stroke_width=1.2).set_stroke(mix(SCR, hue(j), 0.85), opacity=0.9 if w > 0 else 0.45))
        ch.caption("Layer 2 mixes them: forty units, each a weighted sum of the 160. The wires are the strongest pushes.", 0)
        self.play(LaggedStart(*[Create(w) for w in wires], lag_ratio=0.01), run_time=1.6)
        self.play(*[h2[k].animate.set_fill(shade(pre2[k]), 1) for k in range(40)], run_time=0.8)
        self.wait(0.8)

        # Two heads: the value, and a score for every legal move.
        val = ply["value"]
        vbox = Square(0.5).set_stroke(WHT, 2).set_fill(SCR, 1).move_to([-1.0, -0.85, 0])
        vt = M(f"value = tanh({ply['raw']:+.3f}) = {val:+.3f}", 20, PHOS).next_to(vbox, RIGHT, buff=0.3)
        vt2 = M(f"hand {ply['hand']:+d} cp  +  net {round(400 * ply['raw']):+d} cp  =  {ply['total']:+d} cp", 16, DIM).next_to(vt, DOWN, buff=0.15).align_to(vt, LEFT)
        vw = VGroup(*[Line(h2[k].get_center(), vbox.get_center(), stroke_width=1.2).set_stroke(mix(SCR, hue(160 + k), 0.8)) for k, w in t["val"][:20]])
        ch.caption("Head one, the value: who is winning, as a correction to the hand-written evaluation.", 0)
        self.play(LaggedStart(*[Create(w) for w in vw], lag_ratio=0.03), run_time=0.8)
        self.play(vbox.animate.set_fill(shade(val), 1), Write(vt), FadeIn(vt2), run_time=0.9)
        self.wait(1.0)
        self.play(FadeOut(VGroup(vw, vbox, vt, vt2, wires)), run_time=0.4)

        # The policy: all legal moves as bars, sorted.
        top = t["top"]
        n_legal = ply["legal"]
        ch.caption(f"Head two, the policy: a probability for each of the {n_legal} legal moves. Its first hunch.", 0)
        bars = VGroup()
        bx0, by0, bw = -1.2, -2.5, 7.6 / n_legal
        dist = ply["dist"]  # every legal move's probability, best first (tools/classics.php)
        pmax = dist[0]
        hist_rank = ply["rank"]
        for r in range(n_legal):
            p = dist[r]
            h = 2.2 * p / pmax
            col = GOLD if r == hist_rank - 1 else mix(SCR, PHOS, 0.75)
            bars.add(Rectangle(width=bw * 0.8, height=max(0.02, h)).set_stroke(width=0).set_fill(col, 1)
                     .move_to([bx0 + r * bw + bw / 2, by0 + max(0.02, h) / 2, 0]))
        self.play(FadeOut(VGroup(acc_g, acc_lbl, h2, h2l)), run_time=0.4)
        bars.shift(UP * 1.6)
        self.play(LaggedStart(*[GrowFromEdge(b, DOWN) for b in bars], lag_ratio=0.02), run_time=1.4)
        names = VGroup(M(f"#1 {top[0]['san']}  {100 * top[0]['p']:.1f}%", 16, PHOS).next_to(bars[0], UP, buff=0.12).align_to(bars, LEFT))
        hb = bars[hist_rank - 1]
        qn = M(f"{ply['san'].rstrip('+#')}: #{hist_rank} of {n_legal}, {100 * ply['p']:.1f}%", 20, GOLD, "BOLD").align_to(bars, RIGHT).set_y(bars.get_top()[1] + 0.3)
        self.play(FadeIn(names), Indicate(hb, color=GOLD, scale_factor=1.6), FadeIn(qn, shift=DOWN * 0.1), run_time=1.0)
        ch.caption(f"A weak hunch: its favourite gets {100 * dist[0]:.1f}%, against an even share of {100 / n_legal:.1f}%.", 1.8)

        # The search decides.
        ch.caption("The policy proposes. The search disposes: two moves deep, plus one for every check.", 0)
        self.play(FadeOut(VGroup(bars, names, qn)), run_time=0.4)
        root = M("16. Qb8+", 26, GOLD, "BOLD").move_to([-0.5, 1.6, 0])
        n1 = M("16… Nxb8   (forced)", 22, PARCH).move_to([1.0, 0.5, 0])
        n2 = M("17. Rd8#", 26, RED, "BOLD").move_to([1.7, -0.6, 0])
        e1 = Line(root.get_bottom(), n1.get_top()).set_stroke(DIM, 3)
        e2 = Line(n1.get_bottom(), n2.get_top()).set_stroke(DIM, 3)
        others = VGroup(*[M(f"{r['san']}  {r['search']:+d}", 18, AMBER).move_to([-0.4 + 0.0, 0.9 - n * 0.42, 0], aligned_edge=LEFT)
                          for n, r in enumerate(top[:5]) if r.get("search") is not None])
        others.next_to(root, DOWN, buff=0.4).align_to(root, LEFT).shift(LEFT * 1.4)
        self.play(FadeIn(root, scale=1.3), run_time=0.4)
        self.play(Create(e1), FadeIn(n1, shift=DOWN * 0.1), run_time=0.5)
        self.play(Create(e2), FadeIn(n2, shift=DOWN * 0.1), run_time=0.5)
        sc = M("+99,997  mate", 30, PHOS, "BOLD").next_to(n2, RIGHT, buff=0.35)
        self.play(FadeIn(sc, scale=1.6), Flash(n2.get_center(), color=PHOS, num_lines=14), run_time=0.6)
        ch.caption("Every other move scores below zero. Qb8+ scores a mate. In MIND, a move's square takes the search's colour.", 0)
        self.play(FadeIn(others, lag_ratio=0.1), run_time=0.8)
        self.wait(1.6)
        ch.caption("That is the whole mind. Now watch it read the classics, move by move.", 1.6, GOLD)
        self.play(*[FadeOut(m) for m in self.mobjects], run_time=0.6)


class Traps(ClassicScene):
    """Chapter 2: Fool's Mate and Scholar's Mate."""

    def construct(self):
        Chrome(self).chapter(2, "The traps", "Fool's Mate  ·  Scholar's Mate")
        self.stage("fool")
        c = self.chrome
        c.caption("Fool's Mate. Each move is history's; the net reads it, and the right column says what it would play.", 0)
        p = self.step(0, think=1.6, move=0.6, hold=0.4)
        self.step(1, think=1.1, move=0.5, hold=0.3)
        p = self.step(2, think=1.4, move=0.0, hold=0.0)
        c.caption("2.g4?? opens the diagonal. None of the three would play it: they all choose a quiet centre move.", 1.4)
        self.board.move(p["uci"], run_time=0.5)
        p = self.step(3, think=1.4, move=0.0, hold=0.0)
        box = self.point_row(PHOS)
        c.caption(f"Qh4#: the policy ranks it #{p['rank']}, only {100 * p['p']:.1f}%. The search sees mate, and all three play it.", 0)
        self.board.move(p["uci"], run_time=0.6)
        self.play(Flash(self.board.c(Board.sq("e1")), color=RED, num_lines=18, flash_radius=0.45), run_time=0.6)
        self.wait(1.4)
        self.outro_fade()

        self.stage("scholar")
        c.caption("Scholar's Mate: Queen and Bishop aim at f7, the square only the King defends.", 0)
        self.fast(0, 4, think=0.8, move=0.45)
        p = self.step(4, think=1.2, move=0.5, hold=0.2)
        p = self.step(5, think=1.8, move=0.0, hold=0.0)
        box = self.point_row(AMBER)
        s = self.chrome.stamp("POLICY'S #1", AMBER)
        c.caption(f"3…Nf6?? is the policy's own favourite ({100 * p['p']:.1f}%). But its search refuses it: "
                  f"the net plays {p['picks']['net']['san']}.", 2.4)
        self.play(FadeOut(s), FadeOut(box), run_time=0.3)
        self.board.move(p["uci"], run_time=0.5)
        p = self.step(6, think=1.2, move=0.6, hold=0.0)
        self.play(Flash(self.board.c(Board.sq("e8")), color=RED, num_lines=18, flash_radius=0.45), run_time=0.6)
        c.caption("The hunch can be wrong. The search is what keeps the net out of the trap.", 1.6, GOLD)
        self.outro_fade()


class Legal(ClassicScene):
    """Chapter 3: Légal's Mate, Paris 1750."""

    def construct(self):
        Chrome(self).chapter(3, "Légal's Mate", "Paris, 1750  ·  the oldest trap in the book")
        self.stage("legal")
        c = self.chrome
        c.caption("Légal de Kermeur against Saint Brie. Black pins the f3 Knight to the Queen.", 0)
        self.fast(0, 8, think=0.6, move=0.35)
        p = self.step(8, think=1.6, move=0.0, hold=0.0)
        c.caption(f"5.Nxe5!? gives the Queen away. None of the three would: it is a bluff, and 5…dxe5 would refute it.", 1.6)
        self.board.move(p["uci"], run_time=0.55)
        p = self.step(9, think=1.6, move=0.0, hold=0.0)
        s = self.chrome.stamp("THE NET FALLS FOR IT", AMBER)
        c.caption(f"5…Bxd1?? takes the Queen. The 2-ply net takes it too. One ply deeper, it plays {p['picks']['deep']['san']} instead.", 2.2)
        self.play(FadeOut(s), run_time=0.3)
        self.board.move(p["uci"], run_time=0.55)
        self.step(10, think=1.0, move=0.5, hold=0.1)
        self.step(11, think=0.9, move=0.5, hold=0.1)
        p = self.step(12, think=1.3, move=0.6, hold=0.0)
        self.play(Flash(self.board.c(Board.sq("e7")), color=RED, num_lines=18, flash_radius=0.45), run_time=0.6)
        c.caption("Three minor pieces mate. Once the trap is sprung, every player finds the mate.", 1.6, GOLD)
        self.outro_fade()


class Reti(ClassicScene):
    """Chapter 4: Réti-Tartakower, Vienna 1910."""

    def construct(self):
        Chrome(self).chapter(4, "Réti–Tartakower", "Vienna, 1910  ·  a Queen given on d8")
        self.stage("reti")
        c = self.chrome
        c.caption("A Caro-Kann. Tartakower grabs a pawn and leaves his King in the centre.", 0)
        self.fast(0, 15, think=0.5, move=0.3)
        p = self.step(15, think=1.5, move=0.0, hold=0.0)
        c.caption(f"8…Nxe4?? loses to a mate five moves deep. All three would play it too: too deep for two or three plies.", 1.8)
        self.board.move(p["uci"], run_time=0.5)
        p = self.step(16, think=1.8, move=0.0, hold=0.0)
        box = self.point_row(PHOS)
        s = self.chrome.stamp("ONLY 3-PLY FINDS IT", PHOS)
        c.caption(f"9.Qd8+!! The policy ranks it #{p['rank']}. The 2-ply net plays {p['picks']['net']['san']}; one ply deeper, it finds the Queen sacrifice.", 2.4)
        self.play(FadeOut(s), FadeOut(box), run_time=0.3)
        self.board.move(p["uci"], run_time=0.6)
        self.step(17, think=0.9, move=0.5, hold=0.1)
        self.step(18, think=1.1, move=0.5, hold=0.1)
        self.step(19, think=0.9, move=0.5, hold=0.1)
        p = self.step(20, think=1.2, move=0.6, hold=0.0)
        self.play(Flash(self.board.c(Board.sq("c7")), color=RED, num_lines=18, flash_radius=0.45), run_time=0.6)
        c.caption("10.Bg5+ double check, 11.Bd8#: the Bishop mates from the square where the Queen died.", 1.8, GOLD)
        self.outro_fade()


class Lasker(ClassicScene):
    """Chapter 5: Ed. Lasker-Thomas, London 1912: the sacrifice no player finds, then the King hunt."""

    def construct(self):
        Chrome(self).chapter(5, "The King hunt", "Edward Lasker – George Thomas  ·  London, 1912")
        self.stage("lasker")
        c = self.chrome
        c.caption("A Dutch Defence. White builds up quietly against the castled King.", 0)
        self.fast(0, 20, think=0.36, move=0.22)
        p = self.step(20, think=1.8, move=0.0, hold=0.0)
        box = self.point_row(AMBER)
        s = self.chrome.stamp("NOBODY FINDS IT", AMBER)
        c.caption(f"11.Qxh7+!! is mate in eight. Too deep for every player: the net plays {p['picks']['net']['san']}, "
                  f"one ply deeper {p['picks']['deep']['san']}.", 2.4)
        self.play(FadeOut(s), FadeOut(box), run_time=0.3)
        self.board.move(p["uci"], run_time=0.6)
        c.caption("But once the Queen is gone, every check is forced, and the net agrees with every one of them.", 0)
        for i in range(21, 35):
            self.step(i, think=0.75, move=0.45, hold=0.0)
        self.play(Flash(self.board.c(Board.sq("g1")), color=RED, num_lines=22, flash_radius=0.5), run_time=0.7)
        c.caption("The black King walked from h8 to g1, and a King move mates it: 18.Kd2#.", 1.8, GOLD)
        self.outro_fade()


class Scoreboard(Scene):
    """Chapter 7: what the net would have played, all six games."""

    def construct(self):
        Chrome(self).chapter(7, "The scoreboard", "113 positions  ·  three players  ·  one question: would it play history's move?")
        ch = Chrome(self)
        tot = {k: 0 for k in ("net", "deep", "plain", "top1")}
        n = 0
        for s in DATA["summary"]:
            w = s["winner"]
            n += w["moves"]
            tot["net"] += w["net"]
            tot["deep"] += w["deep"]
            tot["plain"] += w["plain"]
            tot["top1"] += w["netTop1"]
        head = P(f"The winners' {n} moves: how many would each have played?", 40, PARCH).move_to(UP * 3.2)
        self.play(Write(head), run_time=1.0)
        rows = [("policy alone (its first hunch)", tot["top1"], AMBER), ("hand-written Medium, no net", tot["plain"], SKY),
                (f"the net (generation {GEN}), 2 ply", tot["net"], PHOS), ("the same net, 3 ply", tot["deep"], GOLD)]
        bars = VGroup()
        for i, (label, v, col) in enumerate(rows):
            y = 1.9 - i * 0.95
            lab = M(label, 20, PARCH).move_to([-6.6, y, 0], aligned_edge=LEFT)
            full = Rectangle(width=6.0, height=0.5).set_stroke(DIM, 1).set_fill(SCR, 1).move_to([2.3, y, 0])
            bar = Rectangle(width=max(0.02, 6.0 * v / n), height=0.5).set_stroke(width=0).set_fill(col, 1)
            bar.move_to(full.get_left(), aligned_edge=LEFT)
            num = M(f"{v}/{n}  {100 * v / n:.0f}%", 22, col, "BOLD").next_to(full, RIGHT, buff=0.25)
            bars.add(VGroup(lab, full, bar, num))
            self.play(FadeIn(lab), FadeIn(full), run_time=0.3)
            self.play(GrowFromEdge(bar, LEFT), FadeIn(num), run_time=0.7)
        ch.caption("The policy's first hunch is history's move one time in ten. With a search behind it, about half the time.", 2.4)
        self.play(FadeOut(VGroup(head, bars)), run_time=0.5)

        # The decisive moments.
        moments = [("fool", "Qh4#", "find", "Fool's Mate: 2…Qh4#"), ("fool", "g4", "avoid", "Fool's Mate: 2.g4??"),
                   ("scholar", "Nf6", "avoid", "Scholar's Mate: 3…Nf6??"), ("legal", "Nxe5", "find", "Légal: 5.Nxe5!?"),
                   ("legal", "Bxd1", "avoid", "Légal: 5…Bxd1??"), ("reti", "Nxe4", "avoid", "Réti: 8…Nxe4??"),
                   ("reti", "Qd8+", "find", "Réti: 9.Qd8+!!"), ("opera", "Rxd7", "find", "Opera: 13.Rxd7!"),
                   ("opera", "Qb8+", "find", "Opera: 16.Qb8+!!"), ("lasker", "Qxh7+", "find", "Lasker: 11.Qxh7+!!")]

        def find(key, san):
            g = GAMES[key]
            plies = g["plies"]
            if key == "reti" and san == "Nxe4":
                return [p for p in plies if p["san"] == san and p["side"] == -1][-1]
            return next(p for p in plies if p["san"] == san)
        hdr = VGroup(M("the moment", 18, DIM).move_to([-6.6, 3.3, 0], aligned_edge=LEFT),
                     M("2 ply", 18, PHOS).move_to([1.4, 3.3, 0]), M("3 ply", 18, GOLD).move_to([3.0, 3.3, 0]),
                     M("no net", 18, SKY).move_to([4.6, 3.3, 0]), M("policy", 18, AMBER).move_to([6.1, 3.3, 0]))
        self.play(FadeIn(hdr), run_time=0.4)
        rowsg = VGroup()
        for i, (key, san, kind, label) in enumerate(moments):
            p = find(key, san)
            y = 2.75 - i * 0.56
            cells = [M(label + ("   find it" if kind == "find" else "   avoid it"), 19, PARCH).move_to([-6.6, y, 0], aligned_edge=LEFT)]
            for x, who in ((1.4, "net"), (3.0, "deep"), (4.6, "plain")):
                ok = p["picks"][who]["agree"] if kind == "find" else not p["picks"][who]["agree"]
                cells.append(M("✓" if ok else "✗", 26, PHOS if ok else RED, "BOLD").move_to([x, y, 0]))
            cells.append(M(f"#{p['rank']}", 19, AMBER).move_to([6.1, y, 0]))
            r = VGroup(*cells)
            rowsg.add(r)
            self.play(FadeIn(r, shift=LEFT * 0.2), run_time=0.32)
        ch.caption("It finds what checks and captures can prove in a few moves. It misses the quiet, deep sacrifices.", 2.6)
        mates = sum(1 for s in DATA["summary"] if s["finalMate"]["net"])
        ch.caption(f"And every final mate, {mates} of 6, the net finds on its own.", 2.0, GOLD)
        self.play(*[FadeOut(m) for m in self.mobjects], run_time=0.6)


class Outro(Scene):
    def construct(self):
        a = P("It sees two moves ahead.", 54, PARCH).move_to(UP * 1.6)
        b = P("It still beats the person who made it.", 54, GOLD).next_to(a, DOWN, buff=0.35)
        self.play(FadeIn(a, shift=UP * 0.2), run_time=0.7)
        self.play(FadeIn(b, shift=UP * 0.2), run_time=0.7)
        self.wait(1.4)
        cmd = M("make up   →   localhost:8080   →   The classics   →   Watch the net read it", 22, PHOS).move_to(DOWN * 0.6)
        url = M("github.com/vonglurt/parliamentchess", 24, DIM).move_to(DOWN * 1.4)
        self.play(Write(cmd), run_time=1.2)
        self.play(FadeIn(url), run_time=0.5)
        crest = Text("♚", font=GLYPHS, font_size=96, color=GOLD).scale(0.8).move_to(DOWN * 2.6)
        self.play(FadeIn(crest, scale=1.6), run_time=0.6)
        self.wait(2.4)
        self.play(*[FadeOut(m) for m in self.mobjects], run_time=0.8)


class LiveCard(Scene):
    """Chapter 6's card: the live footage follows it in the cut."""

    def construct(self):
        Chrome(self).chapter(6, "Live: the Opera Game", "Paris, 1858  ·  recorded in the real client, the net reading every move")
