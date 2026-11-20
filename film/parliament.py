"""Parliament Chess — a 3Blue1Brown-style film (Manim Community, no LaTeX).

    sh film/render.sh        # 1080p30 -> film/out/parliament_chess.mp4
    sh film/render.sh l      # quick 480p preview

The evidence chart reads orrery/census/report.json at render time, so the film
always shows the latest census. MIT License.
"""
import json
import math
import os

import numpy as np
from manim import (
    BLACK, DOWN, LEFT, RIGHT, UP, WHITE, Arrow, Circle, Create, DashedLine, Dot, FadeIn, FadeOut,
    GrowFromEdge, Indicate, LaggedStart, Line, Rectangle, ReplacementTransform, Transform, Scene, Square,
    SurroundingRectangle, Text, VGroup, Write, config,
)

config.background_color = "#000000"

BLUE = "#58C4DD"
GOLD = "#F0C75E"
RED = "#FC6255"
GREEN = "#83C167"
GREY_L = "#BBBBBB"
GREY_M = "#888888"
GREY_D = "#444444"
SERIF = "Palatino"
GLYPHS = "Apple Symbols"
GL = {"K": "♚", "Q": "♛", "R": "♜", "B": "♝", "N": "♞", "P": "♟"}
HEIGHT = {"K": 0.80, "Q": 0.78, "R": 0.66, "B": 0.76, "N": 0.70, "P": 0.58}
HERE = os.path.dirname(os.path.abspath(__file__))
REPORT = os.path.join(HERE, "..", "orrery", "census", "report.json")


RX = 3.75  # centre of the right-hand column


def T(s, size=34, color=WHITE, **kw):
    return Text(s, font=SERIF, font_size=size, color=color, **kw)


def fit(m, w=6.2):
    if m.width > w:
        m.scale_to_fit_width(w)
    return m


def glyph(ch, white=True, h=0.6):
    g = Text(GL[ch], font=GLYPHS, font_size=96)
    g.scale_to_fit_height(h)
    if white:
        g.set_fill(WHITE, 1).set_stroke(BLACK, 2, background=True)
    else:
        g.set_fill("#2b2b2b", 1).set_stroke(GREY_L, 1.4)
    return g


class ParliamentChess(Scene):
    S = 0.78
    ORIGIN = np.array([-3.25, -0.2, 0.0])

    def c(self, f, r):
        return self.ORIGIN + np.array([(f - 3.5) * self.S, (r - 3.5) * self.S, 0.0])

    def piece(self, ch, white, f, r):
        return glyph(ch, white, HEIGHT[ch] * self.S).move_to(self.c(f, r))

    def district(self, f, r, d, color, opacity):
        f0, f1, r0, r1 = max(0, f - d), min(7, f + d), max(0, r - d), min(7, r + d)
        rect = Rectangle(width=(f1 - f0 + 1) * self.S - 0.04, height=(r1 - r0 + 1) * self.S - 0.04)
        rect.move_to((self.c(f0, r0) + self.c(f1, r1)) / 2)
        return rect.set_stroke(color, 3, opacity=opacity)

    # ------------------------------------------------------------------
    def construct(self):
        self.opening()
        self.board()
        self.writ()
        self.districts()
        self.weights()
        self.grid()
        self.campaign()
        self.ledger()
        self.evidence()
        self.outro()

    def opening(self):
        title = T("Parliament Chess", 80)
        sub = T("every move FIDE   ·   every crown elected", 32, GREY_L).next_to(title, DOWN, buff=0.45)
        self.play(Write(title), run_time=2)
        self.play(FadeIn(sub, shift=UP * 0.2))
        self.wait(1.2)
        q = T("What if the King were an office?", 48, GOLD)
        self.play(FadeOut(VGroup(title, sub), shift=UP * 0.6), FadeIn(q, shift=UP * 0.3), run_time=1.2)
        self.wait(1.6)
        self.play(FadeOut(q))

    def board(self):
        sq = VGroup()
        for f in range(8):
            for r in range(8):
                s = Square(self.S).move_to(self.c(f, r))
                s.set_fill("#262626" if (f + r) % 2 else "#141414", 1).set_stroke(GREY_D, 1)
                sq.add(s)
        coords = VGroup(*[T("abcdefgh"[f], 18, GREY_M).next_to(self.c(f, 0), DOWN, buff=self.S * 0.62) for f in range(8)],
                        *[T(str(r + 1), 18, GREY_M).next_to(self.c(0, r), LEFT, buff=self.S * 0.62) for r in range(8)])
        self.play(LaggedStart(*[FadeIn(s, scale=0.85) for s in sq], lag_ratio=0.015), run_time=2.2)
        self.play(FadeIn(coords), run_time=0.6)
        # RULES.md section 8: 6k1/5ppp/8/r7/8/2PPP3/1N6/7K w - - 0 20
        self.p = {
            "h1": self.piece("K", True, 7, 0), "b2": self.piece("N", True, 1, 1),
            "c3": self.piece("P", True, 2, 2), "d3": self.piece("P", True, 3, 2), "e3": self.piece("P", True, 4, 2),
            "g8": self.piece("K", False, 6, 7), "f7": self.piece("P", False, 5, 6), "g7": self.piece("P", False, 6, 6),
            "h7": self.piece("P", False, 7, 6), "a5": self.piece("R", False, 0, 4),
        }
        self.play(LaggedStart(*[FadeIn(m, scale=0.6) for m in self.p.values()], lag_ratio=0.1), run_time=2)
        note = T("Move 20. White to move.", 30, GREY_L).move_to([RX, 3.0, 0])
        self.play(FadeIn(note))
        self.wait(0.8)
        self.boardgroup = VGroup(sq, coords)
        self.note = note

    def writ(self):
        self.ring = Circle(radius=self.S * 0.47, color=GOLD, stroke_width=5).move_to(self.c(3, 2))
        w = T("W@d3", 46, GOLD).move_to([RX, 1.9, 0])
        line = T("a Writ: name a Candidate", 28, WHITE).next_to(w, DOWN, buff=0.3)
        cost = T("it costs your whole turn", 28, GREY_L).next_to(line, DOWN, buff=0.18)
        self.play(Create(self.ring), Write(w), run_time=1.4)
        self.play(FadeIn(line, shift=UP * 0.15), FadeIn(cost, shift=UP * 0.15))
        self.wait(1.6)
        self.panel = VGroup(w, line, cost)

    def districts(self):
        head = fit(T("Each piece votes for the nearer one,", 30)).move_to([RX, 2.0, 0])
        head2 = T("counted in king-steps", 30, GREY_L).next_to(head, DOWN, buff=0.18)
        self.play(FadeOut(self.panel), FadeOut(self.note), run_time=0.6)
        self.play(FadeIn(head), FadeIn(head2))
        rings = VGroup()
        anims = []
        for d in range(1, 5):
            for (f, r, col) in [(7, 0, BLUE), (3, 2, GOLD)]:
                rr = self.district(f, r, d, col, 0.85 - 0.17 * d)
                rings.add(rr)
                anims.append(Create(rr))
        inc = T("Incumbent", 24, BLUE).next_to(self.c(7, 0), RIGHT, buff=0.55)
        cand = T("Candidate", 24, GOLD).next_to(self.c(3, 2), UP, buff=0.5).shift(LEFT * 0.0)
        self.play(LaggedStart(*anims, lag_ratio=0.18), FadeIn(inc), run_time=2.6)
        voters = [("c3", 2, 2, "1 vs 5"), ("e3", 4, 2, "1 vs 3"), ("b2", 1, 1, "2 vs 6")]
        lines, dots, labs = VGroup(), VGroup(), VGroup()
        for name, f, r, txt in voters:
            lines.add(Line(self.c(f, r), self.c(3, 2), color=GOLD, stroke_width=4).set_opacity(0.7))
            dots.add(Dot(self.c(f, r) + np.array([-0.27, -0.27, 0]) * self.S, radius=0.09, color=GOLD))
            labs.add(T(txt, 20, GOLD).move_to(self.c(f, r) + np.array([0, 0.66, 0]) * self.S))
        legend = VGroup(T("steps to d3  vs  steps to h1", 24, GREY_L)).next_to(head2, DOWN, buff=0.5)
        self.play(LaggedStart(*[Create(l) for l in lines], lag_ratio=0.3), LaggedStart(*[FadeIn(d, scale=2) for d in dots], lag_ratio=0.3), run_time=1.8)
        self.play(FadeIn(labs), FadeIn(legend))
        self.wait(1.4)
        self.play(FadeOut(rings), FadeOut(inc), run_time=0.8)
        self.vote_marks = VGroup(lines, dots, labs)
        self.panel = VGroup(head, head2, legend)

    def weights(self):
        def row(chars, text, color, y):
            gs = VGroup(*[glyph(ch, True, HEIGHT[ch] * 0.62) for ch in chars]).arrange(RIGHT, buff=0.12)
            t = T(text, 28, color)
            g = VGroup(gs, t).arrange(RIGHT, buff=0.3)
            g.move_to([RX, y, 0])
            return fit(g)
        r1 = row("P", "pawn = 1 vote   (Commons)", GOLD, 2.3)
        r2 = row("NBR", "2, 2, 3 votes   (College)", BLUE, 1.5)
        r3 = row("Q", "breaks College ties", GREY_L, 0.7)
        self.play(FadeOut(self.panel), run_time=0.6)
        self.play(LaggedStart(FadeIn(r1, shift=UP * 0.2), FadeIn(r2, shift=UP * 0.2), FadeIn(r3, shift=UP * 0.2), lag_ratio=0.35), run_time=2)
        bars = VGroup()
        static = VGroup()
        grows = []
        for i, (name, a, b) in enumerate([("Commons", 2, 0), ("College", 2, 0)]):
            y = -0.6 - i * 1.0
            lab = T(name, 26).move_to([1.35, y, 0])
            frame = Rectangle(width=3.0, height=0.34).set_stroke(GREY_M, 1.5).move_to([3.85, y, 0])
            tot = max(1, a + b)
            fill = Rectangle(width=3.0 * a / tot, height=0.34).set_fill(GOLD, 0.9).set_stroke(width=0)
            fill.align_to(frame, LEFT).set_y(y)
            num = T(f"{a} – {b}", 26, GOLD).next_to(frame, RIGHT, buff=0.25)
            bars.add(lab, frame, fill, num)
            static.add(lab, frame, num)
            grows.append(GrowFromEdge(fill, LEFT))
        cap = T("Candidate  vs  Incumbent", 22, GREY_L).move_to([3.85, 0.0, 0])
        self.play(FadeIn(static), FadeIn(cap))
        self.play(*grows, run_time=1.4)
        self.wait(1.6)
        self.panel = VGroup(r1, r2, r3, bars, cap)

    def grid(self):
        self.play(FadeOut(self.panel), FadeOut(self.vote_marks))
        cw, ch = 2.35, 1.15
        x0, y0 = 3.15, 0.9
        cells = {}
        g = VGroup()
        spec = [("Coronation", GOLD, 0, 0), ("Contested\nCoronation", GOLD, 1, 0), ("Royal Swap", GREEN, 0, 1), ("Defeat", GREY_M, 1, 1)]
        for name, col, cx, cy in spec:
            rect = Rectangle(width=cw, height=ch).set_stroke(col, 2).move_to([x0 + cx * cw, y0 - cy * ch, 0])
            txt = T(name, 24, col).move_to(rect)
            cells[name] = rect
            g.add(rect, txt)
        top = VGroup(T("Commons back", 20, GREY_L), T("the Candidate", 20, GOLD)).arrange(DOWN, buff=0.06).next_to(cells["Coronation"], UP, buff=0.18)
        top2 = VGroup(T("Commons back", 20, GREY_L), T("the Incumbent", 20, BLUE)).arrange(DOWN, buff=0.06).next_to(cells["Contested\nCoronation"], UP, buff=0.18)
        left1 = VGroup(T("College", 20, GREY_L), T("Candidate", 20, GOLD)).arrange(DOWN, buff=0.06).next_to(cells["Coronation"], LEFT, buff=0.15)
        left2 = VGroup(T("College", 20, GREY_L), T("Incumbent", 20, BLUE)).arrange(DOWN, buff=0.06).next_to(cells["Royal Swap"], LEFT, buff=0.15)
        expl = T("Coronation: the Candidate is King,\nthe old King becomes a pawn", 22, GREY_L).move_to([4.3, -2.0, 0])
        self.play(LaggedStart(*[Create(m) if isinstance(m, Rectangle) else FadeIn(m) for m in g], lag_ratio=0.12), FadeIn(VGroup(top, top2, left1, left2)), run_time=2.4)
        hl = cells["Coronation"].copy().set_fill(GOLD, 0.22).set_stroke(GOLD, 5)
        self.play(FadeIn(hl), Indicate(g[1], color=GOLD), run_time=1.2)
        self.play(FadeIn(expl, shift=UP * 0.2))
        self.wait(1.8)
        self.panel = VGroup(g, top, top2, left1, left2, hl, expl)

    def campaign(self):
        self.play(FadeOut(self.panel))
        h1 = T("Black campaigns:", 30).move_to([RX, 2.1, 0])
        h2 = T("Rh5, check!", 34, RED).next_to(h1, DOWN, buff=0.25)
        self.play(FadeIn(h1))
        rook = self.p["a5"]
        self.play(rook.animate.move_to(self.c(7, 4)), run_time=1.3)
        arrow = Arrow(self.c(7, 4), self.c(7, 0), buff=self.S * 0.38, color=RED, stroke_width=6)
        self.play(GrowFromEdge(arrow, UP), Write(h2), run_time=1.0)
        self.wait(0.8)
        c1 = T("The Count, before White moves:", 28).move_to([RX, 0.6, 0])
        c2 = T("Commons 2–0   ·   College 2–0", 26, GOLD).next_to(c1, DOWN, buff=0.25)
        self.play(FadeIn(c1), FadeIn(c2))
        new_king = self.piece("K", True, 3, 2)
        new_pawn = self.piece("P", True, 7, 0)
        self.play(ReplacementTransform(self.p["d3"], new_king), ReplacementTransform(self.p["h1"], new_pawn), run_time=1.8)
        pret = T("Pretender", 22, RED).next_to(new_pawn, RIGHT, buff=0.3)
        crown = T("King", 20, GOLD).next_to(self.ring, UP, buff=0.08)
        self.play(FadeIn(pret), FadeIn(crown), FadeOut(arrow), run_time=1.0)
        esc = T("the crown escapes the net", 34, GOLD).move_to([RX, -1.2, 0])
        self.play(Write(esc), run_time=1.4)
        self.wait(1.8)
        self.p["d3"], self.p["h1"] = new_king, new_pawn
        everything = VGroup(self.boardgroup, *self.p.values(), self.ring, pret, crown, h1, h2, c1, c2, esc)
        self.play(FadeOut(everything), run_time=1.0)

    def ledger(self):
        head = T("The Ledger: social mobility", 40).to_edge(UP, buff=0.8)
        nodes = [("P", "Pawn"), ("R", "Rook"), ("K", "King"), ("P", "Pretender"), ("RBN", "R, B or N")]
        verbs = ["promotes", "elected", "dethroned", "may promote to"]
        group = VGroup()
        items = []
        for chars, lab in nodes:
            gs = VGroup(*[glyph(c, True, HEIGHT[c] * 0.95) for c in chars]).arrange(RIGHT, buff=0.1)
            col = GOLD if lab == "King" else (RED if lab == "Pretender" else WHITE)
            node = VGroup(gs, T(lab, 26, col)).arrange(DOWN, buff=0.25)
            group.add(node)
            items.append(node)
        group.arrange(RIGHT, buff=1.9, aligned_edge=DOWN).move_to([0, 0.6, 0])
        fit(group, 13.4)
        arrows = VGroup()
        vlabs = VGroup()
        for i, v in enumerate(verbs):
            y = items[i][0].get_center()[1]
            a = Arrow([items[i].get_right()[0] + 0.12, y, 0], [items[i + 1].get_left()[0] - 0.12, y, 0], buff=0,
                      color=GREY_M, stroke_width=3, max_tip_length_to_length_ratio=0.12)
            arrows.add(a)
            vlabs.add(fit(T(v, 19, GREY_L), a.width + 0.2).next_to(a, UP, buff=0.12))
        self.play(Write(head))
        seq = [FadeIn(items[0], shift=UP * 0.2)]
        for i in range(4):
            seq += [Create(arrows[i]), FadeIn(vlabs[i]), FadeIn(items[i + 1], shift=UP * 0.2)]
        self.play(LaggedStart(*seq, lag_ratio=0.35), run_time=5)
        q = glyph("Q", True, 0.6)
        bar = VGroup(q, T("a Queen can never stand for the crown", 28, GREY_L)).arrange(RIGHT, buff=0.3).move_to([0, -1.6, 0])
        one = T("and a piece that has reigned never reigns again", 26, GREY_M).next_to(bar, DOWN, buff=0.3)
        self.play(FadeIn(bar, shift=UP * 0.2))
        self.play(FadeIn(one))
        self.wait(2)
        self.play(FadeOut(VGroup(head, group, arrows, vlabs, bar, one)))

    def evidence(self):
        try:
            runs = json.load(open(REPORT))["runs"]
        except Exception:
            runs = {}
        try:  # 95% intervals from bin/analyze.php
            cis = json.load(open(os.path.join(HERE, "..", "docs", "data", "census-analysis.json")))["runs"]
        except Exception:
            cis = {}
        pick = [("A standard chess", "plain chess", GREY_L), ("B parliament", "Parliament\n(Whig AIs)", BLUE),
                ("D populist vs populist", "Parliament\n(Populist AIs)", GOLD)]
        data = [(lab, col, runs[k], cis.get(k, {}).get("trailingScore", {})) for k, lab, col in pick
                if k in runs and runs[k].get("trailingScore") is not None]
        head = T("Who comes back?", 44).to_edge(UP, buff=0.55)
        sub = T("score of the side behind on material at move 20", 24, GREY_L).next_to(head, DOWN, buff=0.2)
        self.play(Write(head), FadeIn(sub))
        if not data:
            self.wait(1)
            self.play(FadeOut(VGroup(head, sub)))
            return
        top = max(0.5, math.ceil(max(d[2]["trailingScore"] for d in data) * 10 + 1) / 10)
        base_y, h_max, x0, gap = -1.85, 3.7, -3.2, 3.2
        axis = Line([x0 - 1.6, base_y, 0], [x0 + gap * (len(data) - 1) + 1.6, base_y, 0], color=GREY_M, stroke_width=2)
        ticks = VGroup()
        for v in [0.1 * i for i in range(int(round(top * 10)) + 1) if i % 1 == 0]:
            y = base_y + h_max * v / top
            if v > 0:
                ticks.add(DashedLine([x0 - 1.6, y, 0], [x0 + gap * (len(data) - 1) + 1.6, y, 0], color=GREY_D, stroke_width=1, dash_length=0.08))
            ticks.add(T(f"{v:.1f}", 18, GREY_M).move_to([x0 - 2.0, y, 0]))
        half = None
        if top >= 0.5:
            yh = base_y + h_max * 0.5 / top
            half = T("an even game = 0.5", 18, GREY_L).move_to([x0 + gap * (len(data) - 1) + 0.6, yh + 0.2, 0])
        self.play(Create(axis), FadeIn(ticks), *( [FadeIn(half)] if half else []), run_time=1.2)
        bars, labs, grows, whisk = VGroup(), VGroup(), [], VGroup()
        for i, (lab, col, c, ci) in enumerate(data):
            x = x0 + gap * i
            hgt = max(0.02, h_max * c["trailingScore"] / top)
            bar = Rectangle(width=1.3, height=hgt).set_fill(col, 0.85).set_stroke(col, 2)
            bar.move_to([x, base_y + hgt / 2, 0])
            top_y = base_y + hgt
            if ci.get("lo") is not None:
                ylo, yhi = base_y + h_max * max(0, ci["lo"]) / top, base_y + h_max * ci["hi"] / top
                whisk.add(Line([x, ylo, 0], [x, yhi, 0], color=WHITE, stroke_width=3),
                          Line([x - 0.16, ylo, 0], [x + 0.16, ylo, 0], color=WHITE, stroke_width=3),
                          Line([x - 0.16, yhi, 0], [x + 0.16, yhi, 0], color=WHITE, stroke_width=3))
                top_y = max(top_y, yhi)
            val = T(f"{c['trailingScore']:.3f}", 26, col).move_to([x, top_y + 0.3, 0])
            name = T(lab, 20, WHITE).move_to([x, base_y - 0.55, 0])
            n = T(f"n = {c['trailingGames']} games", 16, GREY_M).next_to(name, DOWN, buff=0.12)
            bars.add(bar)
            labs.add(val, name, n)
            grows.append(GrowFromEdge(bar, DOWN))
        self.play(LaggedStart(*grows, lag_ratio=0.4), run_time=2.2)
        self.play(FadeIn(labs))
        self.wait(0.8)
        if len(whisk):
            verdict = T("the 95% intervals overlap: no catch-up effect measured at this AI strength", 24, GREY_L).move_to(sub)
            self.play(Create(whisk), run_time=1.2)
            self.play(Transform(sub, verdict))
            self.wait(1.6)
        else:
            self.wait(1.2)
        A = runs.get("A standard chess")
        D = runs.get("D populist vs populist")
        extra = VGroup()
        if A and D:
            still = T("still chess:", 26, GREEN)
            line = T(f"decisive {A['decisive'] * 100:.0f}% \u2192 {D['decisive'] * 100:.0f}%    \u00b7    "
                     f"average length {A['avgPlies']:.0f} \u2192 {D['avgPlies']:.0f} plies    \u00b7    "
                     f"{D['countsPerGame']:.1f} counts a game", 22, GREY_L)
            extra = fit(VGroup(still, line).arrange(RIGHT, buff=0.3), 13.2).to_edge(DOWN, buff=0.4)
            self.play(Write(still), run_time=0.9)
            self.play(FadeIn(line, shift=UP * 0.1))
        self.wait(2.4)
        self.play(FadeOut(VGroup(head, sub, axis, ticks, bars, labs, whisk, extra, *( [half] if half else []))))

    def outro(self):
        a = T("The ballot is never a weapon.", 46, GOLD)
        self.play(Write(a), run_time=1.8)
        self.wait(1.4)
        title = T("Parliament Chess", 72)
        mit = T("Vote King   ·   MIT License", 28, GREY_L).next_to(title, DOWN, buff=0.4)
        k = glyph("K", True, 1.1).next_to(title, UP, buff=0.4)
        k.set_fill(GOLD, 1)
        self.play(FadeOut(a, shift=UP * 0.4), FadeIn(VGroup(k, title), shift=UP * 0.3), run_time=1.2)
        self.play(FadeIn(mit))
        self.wait(2.5)
        self.play(FadeOut(VGroup(k, title, mit)), run_time=1.2)
