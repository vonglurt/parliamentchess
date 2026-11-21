"""Parliament Chess — a player's guide (Manim Community, no LaTeX).

    node film/guide_shots.mjs film/guide-shots     # screenshots of a real game (a server on :8091)
    sh film/render.sh guide                        # 1080p30 -> film/out/players_guide.mp4

Every screen in it is a screenshot of the real client during a real game, taken by
film/guide_shots.mjs over Chrome's DevTools protocol; rects.json says where each panel was, so
the highlights land on the panels. The crown-move chart reads docs/data/crown-theory.json.
MIT License.
"""
import json
import os
import textwrap

from manim import (
    DOWN, LEFT, ORIGIN, RIGHT, UP, WHITE, Create, FadeIn, FadeOut, ImageMobject, LaggedStart, Line,
    Rectangle, RoundedRectangle, Scene, Text, VGroup, Write, config,
)

config.background_color = "#000000"
GOLD = "#F0C75E"
GREEN = "#83C167"
RED = "#FC6255"
BLUE = "#58C4DD"
GREY_L = "#BBBBBB"
GREY_D = "#444444"
SERIF = "Palatino"
MONO = "Menlo"
HERE = os.path.dirname(os.path.abspath(__file__))
SHOTS = os.environ.get("GUIDE_SHOTS", os.path.join(HERE, "guide-shots"))
FIG = os.path.join(HERE, "..", "docs", "media", "fig-election.png")
THEORY = os.path.join(HERE, "..", "docs", "data", "crown-theory.json")
RECTS = json.load(open(os.path.join(SHOTS, "rects.json")))

IMG_W = 9.6                  # screenshot width on screen (frame is 14.2 x 8)
IMG_C = LEFT * 2.15 + DOWN * 0.15
COL_X = 3.05                 # left edge of the caption column
COL_W = 3.85


def T(s, size=30, color=WHITE, font=SERIF, **kw):
    return Text(s, font=font, font_size=size, color=color, **kw)


def wrap(s, width=27):
    return "\n".join(textwrap.wrap(s, width))


class PlayersGuide(Scene):
    def shot(self, name):
        img = ImageMobject(os.path.join(SHOTS, name + ".png"))
        img.scale_to_fit_width(IMG_W).move_to(IMG_C)
        img.rects = RECTS[name]
        return img

    @staticmethod
    def pt(img, x, y):
        return [img.get_left()[0] + x / 1600 * img.width, img.get_top()[1] - y / 1000 * img.height, 0]

    def box(self, img, r, color=GOLD, pad=6):
        x, y, w, h = r
        a, b = self.pt(img, x - pad, y - pad), self.pt(img, x + w + pad, y + h + pad)
        rect = Rectangle(width=b[0] - a[0], height=a[1] - b[1], stroke_color=color, stroke_width=6)
        return rect.move_to([(a[0] + b[0]) / 2, (a[1] + b[1]) / 2, 0]).set_z_index(3)

    def zoom(self, img, r, height=5.6, to=IMG_C):
        x, y, w, h = r
        p = self.pt(img, x + w / 2, y + h / 2)
        k = height / (h / 1000 * img.height)
        return img.animate.scale(k, about_point=p).shift([to[0] - p[0], to[1] - p[1], 0])

    def say(self, step, title, body, wait=0.0):
        """The caption column: a step number, a title and a few wrapped lines."""
        name = T(title, 36, WHITE, weight="BOLD")
        if name.width > COL_W:  # a long title shrinks alone, never the words under it
            name.scale_to_fit_width(COL_W)
        head = VGroup(T(step, 22, GOLD), name).arrange(DOWN, aligned_edge=LEFT, buff=0.12)
        lines = T(wrap(body), 25, GREY_L, line_spacing=0.9)
        if lines.width > COL_W:
            lines.scale_to_fit_width(COL_W)
        g = VGroup(head, lines).arrange(DOWN, aligned_edge=LEFT, buff=0.32)
        g.next_to([COL_X, 3.45, 0], DOWN + RIGHT, buff=0).set_z_index(5)
        if getattr(self, "cap", None) is not None:
            self.play(FadeOut(self.cap, shift=UP * 0.2), FadeIn(g, shift=UP * 0.2), run_time=0.6)
        else:
            self.play(FadeIn(g, shift=UP * 0.2), run_time=0.6)
        self.cap = g
        if wait:
            self.wait(wait)

    def note(self, text, wait=2.5):
        """A further line under the caption."""
        n = T(wrap(text), 25, WHITE, line_spacing=0.9)
        if n.width > COL_W:
            n.scale_to_fit_width(COL_W)
        n.next_to(self.cap, DOWN, aligned_edge=LEFT, buff=0.35).set_z_index(5)
        self.play(FadeIn(n, shift=UP * 0.15), run_time=0.5)
        self.cap = VGroup(self.cap, n)
        self.wait(wait)

    def swap(self, old, new, t=0.7):
        self.play(FadeOut(old), FadeIn(new), run_time=t)
        return new

    def construct(self):
        # A black mask over the caption column, so a zoomed screenshot never runs under the words.
        mask = Rectangle(width=4.5, height=8.2, fill_color="#000000", fill_opacity=1, stroke_width=0)
        mask.move_to([COL_X + 2.0, 0, 0]).set_z_index(4)
        self.cap = None

        # Title.
        title = T("Parliament Chess", 70, WHITE)
        sub = T("A player's guide", 44, GOLD)
        tag = T("every move FIDE, every crown elected", 28, GREY_L)
        VGroup(title, sub, tag).arrange(DOWN, buff=0.3)
        self.play(Write(title), run_time=1.4)
        self.play(FadeIn(sub, shift=UP * 0.2), FadeIn(tag), run_time=0.8)
        self.wait(1.4)
        self.play(FadeOut(VGroup(title, sub, tag)), run_time=0.6)
        self.add(mask)

        # 1. Start the server.
        term = VGroup(*[T(s, 26, c, font=MONO) for s, c in [
            ("$ git clone https://github.com/vonglurt/parliamentchess.git", GREY_L), ("$ cd parliamentchess", GREY_L),
            ("$ make up", GOLD), ("up: http://localhost:8080", GREEN)]]).arrange(DOWN, aligned_edge=LEFT, buff=0.22)
        frame = RoundedRectangle(corner_radius=0.15, width=term.width + 0.8, height=term.height + 0.7, stroke_color=GREY_D, fill_color="#0b0b0b", fill_opacity=1)
        tg = VGroup(frame, term.move_to(frame)).scale_to_fit_width(IMG_W).move_to(IMG_C)
        self.say("1", "Start the server", "One command starts it in the background; make down stops it. Then open localhost:8080 in a browser.")
        self.play(FadeIn(frame), LaggedStart(*[FadeIn(l, shift=RIGHT * 0.2) for l in term], lag_ratio=0.45), run_time=2.2)
        self.wait(1.2)
        img = self.shot("01-landing")
        self.play(FadeOut(tg), FadeIn(img), run_time=0.8)
        self.note("The landing page: Play now opens the game, and the rules, the lab report and the film are a click away.", 3)

        # 2. The lobby.
        new = self.shot("02-lobby")
        img = self.swap(img, new)
        r = img.rects
        self.say("2", "The lobby", "You start as a pumpkin or a large squash. Change your name here or in any game.")
        hl = self.box(img, r["namebar"])
        self.play(Create(hl), run_time=0.6)
        self.wait(1.6)
        for i, text in enumerate(["Two browsers: you and a friend, each with your own link.",
                                  "Play the machine: three levels, three political styles, either colour.",
                                  "Watch the machines: two bots play while you watch."]):
            nh = self.box(img, r["cards"][i])
            self.play(hl.animate.become(nh), run_time=0.5)
            if i == 0:
                self.note(text, 1.6)
            else:
                self.note(text, 1.6)
        self.play(FadeOut(hl), run_time=0.3)

        # 3. Two browsers.
        img = self.swap(img, self.shot("03-created"))
        self.say("3", "Invite a player", "Create game gives two links: send your opponent theirs. The watch link is for spectators, as many as you like.")
        hl = self.box(img, img.rects["links"])
        self.play(Create(hl), run_time=0.6)
        self.wait(2.6)
        self.play(FadeOut(hl), run_time=0.3)

        # 4. Chess, move for move.
        img = self.swap(img, self.shot("04-floor-open"))
        r = img.rects
        self.say("4", "Chess, move for move", "Click a piece, then its square. Castling, en passant and promotion are all as in FIDE chess.")
        hl = self.box(img, r["canvas"])
        self.play(Create(hl), run_time=0.6)
        self.wait(1.8)
        self.play(hl.animate.become(self.box(img, r["writBtn"])), run_time=0.5)
        self.note("From move 10 the floor is open: on your turn you may File a Writ instead of moving.", 2.4)
        self.play(FadeOut(hl), run_time=0.3)

        # 5. File a Writ.
        img = self.swap(img, self.shot("05-writ-mode"))
        self.say("5", "File a Writ", "Gold squares are the pieces that may stand: not the King, not a Queen, never crowned, not under attack.")
        hl = self.box(img, img.rects["canvas"])
        self.play(Create(hl), run_time=0.6)
        self.wait(2.2)
        self.play(FadeOut(hl), run_time=0.3)
        img = self.swap(img, self.shot("06-preview"))
        r = img.rects
        self.note("Pick one and the board shows who would vote for whom if the count were now.", 2.2)
        self.play(self.zoom(img, r["writBox"], 6.4), run_time=1.2)
        self.say("5", "The forecast", "For the c1 Bishop: Commons 4 to 1, College 7 to 5. A Coronation, if nothing changes.")
        self.wait(1.6)
        self.note("Filing costs your whole turn.", 1.8)
        img2 = self.shot("07-filed")
        self.play(FadeOut(img), FadeIn(img2), run_time=0.8)
        img = img2

        # 6. The campaign.
        img = self.swap(img, self.shot("08-campaign"))
        self.say("6", "The campaign", "Your opponent gets one move to answer: attack the Candidate's square, capture a voter, or attack voters so they abstain.")
        hl = self.box(img, img.rects["houses"])
        self.play(Create(hl), run_time=0.6)
        self.wait(2.8)
        self.play(FadeOut(hl), run_time=0.3)

        # 7. The count.
        img = self.swap(img, self.shot("09-crowned"))
        r = img.rects
        self.say("7", "The count", "Black's Be6 attacked the b3 Bishop, so it abstained: the College tied 5 to 5.")
        hl = self.box(img, r["count"])
        self.play(Create(hl), run_time=0.6)
        self.wait(1.6)
        self.note("The Queen is the Speaker. She stood nearer c1 and broke the tie: Coronation.", 2.4)
        self.play(FadeOut(hl), run_time=0.3)
        c1 = self.box(img, [250 + 2 * 90, 76 + 7 * 90, 90, 90], GOLD, 2)
        g1 = self.box(img, [250 + 6 * 90, 76 + 7 * 90, 90, 90], RED, 2)
        self.say("7", "The crown moves", "The Bishop on c1 is now the King. The old King on g1 is a Pretender pawn: it may promote only to Rook, Bishop or Knight.")
        self.play(Create(c1), Create(g1), run_time=0.7)
        self.wait(3.2)
        self.play(FadeOut(c1), FadeOut(g1), run_time=0.3)

        # How pieces vote: the diagram.
        fig = ImageMobject(FIG).scale_to_fit_width(IMG_W).move_to(IMG_C)
        self.play(FadeOut(img), FadeIn(fig), run_time=0.8)
        self.say("Rules", "How a piece votes", "Each of your pieces backs whichever is nearer in king-steps, the Candidate or the King. Pawns are the Commons, 1 vote each; Knight 2, Bishop 2 and Rook 3 are the College.")
        self.wait(4.2)
        self.play(FadeOut(fig), run_time=0.5)

        # 8. When to move the crown.
        th = json.load(open(THEORY))
        def rate(group, key):
            return th[group][key]["mean"], th[group][key]["n"]
        a, b = th["crownedRateByPhase"]["1 moves 10-19"], th["crownedRateByPhase"]["2 moves 20-29"]
        early = ((a["mean"] * a["n"] + b["mean"] * b["n"]) / (a["n"] + b["n"]), a["n"] + b["n"])
        rows = [("Count already won at filing", rate("crownedRateByPollWon", "poll already won")),
                ("Count not yet won", rate("crownedRateByPollWon", "poll not won")),
                ("King not under fire", rate("crownedRateByDanger", "0 none")),
                ("3+ King squares attacked", rate("crownedRateByDanger", "2 three or more")),
                ("Filed at moves 10-29", early),
                ("Filed at move 45 or later", rate("crownedRateByPhase", "4 move 45+"))]
        self.say("8", "When to move the crown", f"From {th['writs']:,} Writs in {th['games']:,} self-play games: how often the crown actually moved.")
        chart = VGroup()
        base_x, top_y, full = -2.3, 2.6, 4.2
        for i, (label, (m, n)) in enumerate(rows):
            y = top_y - i * 0.88 - (0.25 if i >= 2 else 0) - (0.25 if i >= 4 else 0)
            good = i % 2 == 0
            lab = T(label, 22, WHITE if good else GREY_L).next_to([base_x - 0.2, y, 0], LEFT, buff=0)
            track = Rectangle(width=full, height=0.42, stroke_width=0, fill_color=GREY_D, fill_opacity=0.5)
            track.move_to([base_x + full / 2, y, 0])
            bar = Rectangle(width=max(0.02, full * m), height=0.42, stroke_width=0, fill_color=GREEN if good else RED, fill_opacity=0.9)
            bar.move_to([base_x + full * m / 2, y, 0])
            val = T(f"{m * 100:.0f}%", 24, WHITE).next_to(bar, RIGHT, buff=0.12)
            chart.add(VGroup(lab, track, bar, val))
        for g in chart:
            g[2].save_state()
            g[2].stretch(0.001, 0, about_edge=LEFT)
        self.play(LaggedStart(*[FadeIn(VGroup(g[0], g[1], g[3])) for g in chart], lag_ratio=0.1), run_time=0.8)
        self.play(LaggedStart(*[g[2].animate.restore() for g in chart], lag_ratio=0.15), run_time=1.6)
        self.wait(1.0)
        self.note("Count first: file only a Writ that already wins. Crown before the storm, while your King is quiet. Crown early.", 4.2)
        self.play(FadeOut(chart), run_time=0.5)

        # 9. The record.
        img = self.shot("11-record")
        self.play(FadeIn(img), run_time=0.6)
        r = img.rects
        self.say("9", "The game record", "Every move with its player and its time, during the game and after it. CSV, JSON and PCN download the whole record.")
        hl = self.box(img, r["record"])
        self.play(Create(hl), run_time=0.6)
        self.wait(2.4)
        self.play(hl.animate.become(self.box(img, r["ledger"])), run_time=0.5)
        self.note("The Ledger keeps every piece's career: the Bishop crowned on c1, the King dethroned on g1.", 2.6)
        self.play(FadeOut(hl), run_time=0.3)

        # 10. The machines.
        img = self.swap(img, self.shot("12-bot"))
        self.say("10", "Play the machine", "Bots are named after nuts, legumes and squash, by common or Latin name: Ceratonia siliqua is the carob.")
        hl = self.box(img, img.rects["houses"])
        self.play(Create(hl), run_time=0.6)
        self.wait(2.6)
        self.play(FadeOut(hl), run_time=0.3)
        img = self.swap(img, self.shot("13-watch"))
        self.say("10", "Watch the machines", "Two styles of politics, one board. Any game, live or replayed, records to video from the game page.")
        self.wait(3.0)

        # Outro.
        self.play(FadeOut(img), FadeOut(self.cap), run_time=0.6)
        self.remove(mask)
        end = VGroup(T("make up", 40, GOLD, font=MONO), T("make test", 40, GOLD, font=MONO), T("make down", 40, GOLD, font=MONO)).arrange(DOWN, buff=0.25)
        url = T("github.com/vonglurt/parliamentchess", 32, GREY_L)
        fin = T("Every move FIDE. Every crown elected.", 40, WHITE)
        VGroup(fin, end, url).arrange(DOWN, buff=0.55)
        self.play(FadeIn(fin, shift=UP * 0.2), run_time=0.8)
        self.play(LaggedStart(*[FadeIn(m) for m in end], lag_ratio=0.3), FadeIn(url), run_time=1.2)
        self.wait(2.6)
        self.play(FadeOut(VGroup(fin, end, url)), run_time=0.8)
