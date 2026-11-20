#!/usr/bin/env python3
"""Draw who votes in an election, from a real position and the engine's own poll.

    php tools/election_example.php > election.json
    python3 tools/fig_election.py election.json docs/media/fig-election.svg     (and a .png beside it)

Every line on the board is a vote the engine counted: each of the filer's pieces backs whichever is
nearer in king-steps, the Candidate or the King (a tie backs the King); a piece the enemy attacks
abstains. Pawns sit in the Commons (1 vote), Knights and Bishops (2) and Rooks (3) in the College,
and the Queen is the Speaker, who votes only to break a College tie. Plain Python, no libraries.
"""
import json
import subprocess
import sys

SRC, OUT = sys.argv[1], sys.argv[2]
d = json.load(open(SRC))
board, me, ksq, cand, poll = d["b"], d["c"], d["k"], d["cand"], d["poll"]
voters = {int(k): v for k, v in poll["voters"].items()}

W, H = 1360, 830
S = 76                      # square size
BX, BY = 56, 104            # board origin (top-left of a8)
PARCH, PARCH2, INK, INK2 = "#f3ead6", "#ddc9a0", "#1e1a14", "#5b5244"
GOLD, CAND, KING, GREY, RED = "#c9961c", "#2f7a57", "#9b2a3a", "#8e877a", "#c0392b"
SERIF = "Iowan Old Style, Palatino, Georgia, serif"
SANS = "Helvetica Neue, Helvetica, Arial, sans-serif"
LETTER = {1: "P", 2: "N", 3: "B", 4: "R", 5: "Q", 6: "K"}
VOTES = {1: 1, 2: 2, 3: 2, 4: 3}


def xy(sq):
    f, r = sq & 7, sq >> 3
    return BX + f * S + S / 2, BY + (7 - r) * S + S / 2


def name(sq):
    return "abcdefgh"[sq & 7] + str((sq >> 3) + 1)


def steps(a, b):
    return max(abs((a & 7) - (b & 7)), abs((a >> 3) - (b >> 3)))


out = [f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}">',
       f'<rect width="{W}" height="{H}" fill="#fbf7ee"/>',
       f'<text x="{BX}" y="50" font-family="{SERIF}" font-size="32" font-weight="600" fill="{INK}">Who votes, and for whom</text>',
       f'<text x="{BX}" y="80" font-family="{SANS}" font-size="17" fill="{INK2}">A real count from the census: at move {(d["ply"] + 1) // 2}, '
       f'White files a Writ for the {name(cand)} pawn. Every line is a vote the engine counted.</text>']

# The board.
for sq in range(64):
    x, y = xy(sq)
    light = ((sq & 7) + (sq >> 3)) % 2 == 1
    out.append(f'<rect x="{x - S / 2}" y="{y - S / 2}" width="{S}" height="{S}" fill="{PARCH if light else PARCH2}"/>')
for i in range(8):
    out.append(f'<text x="{BX + i * S + S / 2}" y="{BY + 8 * S + 22}" text-anchor="middle" font-family="{SANS}" font-size="15" fill="{INK2}">{"abcdefgh"[i]}</text>')
    out.append(f'<text x="{BX - 16}" y="{BY + i * S + S / 2 + 5}" text-anchor="middle" font-family="{SANS}" font-size="15" fill="{INK2}">{8 - i}</text>')

# Districts: king-step rings around the King and the Candidate would crowd the board, so each
# voter carries its two distances instead (to the Candidate | to the King).
kx, ky = xy(ksq)
cx, cy = xy(cand)
for sq, v in sorted(voters.items()):
    if v == "X":
        continue
    x, y = xy(sq)
    tx, ty, col = (cx, cy, CAND) if v == "C" else (kx, ky, KING)
    w = 2 + 1.6 * VOTES.get(abs(board[sq]), 1) if abs(board[sq]) != 5 else 2.5
    dash = ' stroke-dasharray="7 6"' if abs(board[sq]) == 5 else ""
    # A gentle arc, so a vote never runs through the piece standing between voter and target.
    mx, my, dx, dy = (x + tx) / 2, (y + ty) / 2, tx - x, ty - y
    qx, qy = mx - dy * 0.22, my + dx * 0.22
    out.append(f'<path d="M{x},{y} Q{qx:.1f},{qy:.1f} {tx},{ty}" fill="none" stroke="{col}" stroke-width="{w:.1f}" stroke-linecap="round" opacity="0.8"{dash}/>')

# Pieces.
for sq in range(64):
    p = board[sq]
    if p == 0:
        continue
    x, y = xy(sq)
    t = abs(p)
    mine = (p > 0) == (me > 0)
    if not mine:
        out.append(f'<circle cx="{x}" cy="{y}" r="15" fill="#d9d2c3" stroke="#b5ad9c"/>'
                   f'<text x="{x}" y="{y + 5}" text-anchor="middle" font-family="{SANS}" font-size="14" font-weight="600" fill="#8e877a">{LETTER[t].lower()}</text>')
        continue
    if sq == ksq:
        ring, fill, label = GOLD, KING, "K"
    elif sq == cand:
        ring, fill, label = GOLD, CAND, "P"
    else:
        v = voters.get(sq)
        fill = {"C": CAND, "I": KING, "X": GREY}.get(v, GREY)
        ring, label = "none", LETTER[t]
    r = 26 if sq in (ksq, cand) else 22
    if ring != "none":
        out.append(f'<circle cx="{x}" cy="{y}" r="{r + 6}" fill="none" stroke="{ring}" stroke-width="4"/>')
    out.append(f'<circle cx="{x}" cy="{y}" r="{r}" fill="{fill}" stroke="{INK}" stroke-width="1.5"/>'
               f'<text x="{x}" y="{y + 7}" text-anchor="middle" font-family="{SERIF}" font-size="21" font-weight="700" fill="#fffaf0">{label}</text>')
    if sq in voters and voters[sq] != "X" and t in VOTES:
        out.append(f'<circle cx="{x + 18}" cy="{y - 18}" r="10" fill="#fffaf0" stroke="{INK}" stroke-width="1"/>'
                   f'<text x="{x + 18}" y="{y - 13.5}" text-anchor="middle" font-family="{SANS}" font-size="12" font-weight="700" fill="{INK}">{VOTES[t]}</text>')
    if sq in voters and sq not in (ksq, cand):
        out.append(f'<text x="{x}" y="{y + S / 2 - 3}" text-anchor="middle" font-family="{SANS}" font-size="12" fill="{INK}">'
                   f'{steps(sq, cand)} | {steps(sq, ksq)}</text>')
    if voters.get(sq) == "X":
        out.append(f'<path d="M{x - 13},{y - 13} L{x + 13},{y + 13} M{x + 13},{y - 13} L{x - 13},{y + 13}" stroke="{RED}" stroke-width="4" stroke-linecap="round"/>')
out.append(f'<text x="{kx}" y="{ky - 36}" text-anchor="middle" font-family="{SANS}" font-size="13" font-weight="700" fill="{KING}">King</text>')
out.append(f'<text x="{cx}" y="{cy - 36}" text-anchor="middle" font-family="{SANS}" font-size="13" font-weight="700" fill="{CAND}">Candidate</text>')

# The rules and the tally.
X0 = BX + 8 * S + 56
lines = [
    ("h", "How a piece votes"),
    ("p", "Only your own pieces vote. The King and the Candidate don't."),
    ("p", "Each backs whichever is nearer in king-steps, the Candidate or"),
    ("p", "the King; a tie backs the King. Under each piece: steps to the"),
    ("p", "Candidate | steps to the King."),
    ("p", "A piece the enemy attacks abstains (crossed out)."),
    ("g", ""),
    ("h", "Two chambers"),
    ("p", "Commons: pawns, 1 vote each. A tie goes to the King."),
    ("p", "College: Knight 2, Bishop 2, Rook 3 (the badge on each piece)."),
    ("p", "The Queen is the Speaker: she votes only to break a College tie."),
]
y = 132
for kind, text in lines:
    if kind == "h":
        out.append(f'<text x="{X0}" y="{y}" font-family="{SERIF}" font-size="22" font-weight="600" fill="{INK}">{text}</text>')
        y += 30
    elif kind == "g":
        y += 10
    else:
        out.append(f'<text x="{X0}" y="{y}" font-family="{SANS}" font-size="16" fill="{INK}">{text}</text>')
        y += 24

com, col = poll["commons"], poll["college"]
spk = poll["speaker"]
outc = {"coronation": "Coronation", "contested": "Contested Coronation", "swap": "Royal Swap", "defeat": "Defeat"}[poll["outcome"]]
y += 16
out.append(f'<rect x="{X0 - 16}" y="{y - 26}" width="{W - X0 - 24}" height="292" rx="12" fill="{PARCH}" stroke="{PARCH2}"/>')
out.append(f'<text x="{X0}" y="{y + 4}" font-family="{SERIF}" font-size="22" font-weight="600" fill="{INK}">This count</text>')
rows = [
    ("Commons", f"Candidate {com[0]} : {com[1]} King", "a tie, so the King" if com[0] == com[1] else ("the Candidate" if com[0] > com[1] else "the King")),
    ("College", f"Candidate {col[0]} : {col[1]} King", "the Candidate" if col[0] > col[1] else ("the King" if col[0] < col[1] else "a tie, so the Speaker decides")),
    ("Speaker", "the Queen, " + ("nearer the Candidate" if spk == 0 else "3 steps from each, so the King"), "not needed" if col[0] != col[1] else "decides"),
]
y += 40
for a, b, c in rows:
    out.append(f'<text x="{X0}" y="{y}" font-family="{SANS}" font-size="16" font-weight="700" fill="{INK}">{a}</text>'
               f'<text x="{X0 + 92}" y="{y}" font-family="{SANS}" font-size="16" fill="{INK}">{b}</text>'
               f'<text x="{X0 + 92}" y="{y + 20}" font-family="{SANS}" font-size="14" fill="{INK2}">wins: {c}</text>')
    y += 48
out.append(f'<text x="{X0}" y="{y + 4}" font-family="{SERIF}" font-size="21" font-weight="700" fill="{CAND}">{outc}</text>')
out.append(f'<text x="{X0}" y="{y + 28}" font-family="{SANS}" font-size="15" fill="{INK}">The College alone crowns the {name(cand)} pawn; the opponent gains a Writ;</text>')
out.append(f'<text x="{X0}" y="{y + 48}" font-family="{SANS}" font-size="15" fill="{INK}">the {name(ksq)} King becomes a Pretender pawn.</text>')

# Legend under the board.
ly = BY + 8 * S + 50
for i, (c, t) in enumerate([(CAND, "votes for the Candidate"), (KING, "votes for the King"), (GREY, "abstains: attacked"), ("#d9d2c3", "the opponent's pieces")]):
    lx = BX + i * 205
    out.append(f'<circle cx="{lx + 9}" cy="{ly - 5}" r="9" fill="{c}" stroke="{INK}" stroke-width="1"/>'
               f'<text x="{lx + 24}" y="{ly}" font-family="{SANS}" font-size="14" fill="{INK}">{t}</text>')
out.append(f'<text x="{BX}" y="{ly + 30}" font-family="{SANS}" font-size="13" fill="{INK2}">'
           f'Position: {d["file"].replace(".json", "")}, ply {d["ply"]}. The dashed line is the Speaker.</text>')
out.append("</svg>")
open(OUT, "w").write("\n".join(out))
png = OUT[:-4] + ".png"
subprocess.run(["rsvg-convert", "-z", "1.5", "-o", png, OUT], check=True)
print(OUT, png)
