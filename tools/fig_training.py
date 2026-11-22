"""The training figures for the docs: every number from docs/data/generations.json and models/.

    python3 tools/fig_training.py          # docs/media/training/*.svg, then PNGs via tools/svg2png.mjs

  timeline   each generation's verdict against the champion of the day (z), by the time it was decided
  queue      one generation through the Orrery queue, with the measured lane-minutes per stage
  cost       where each generation's lane time went: labelling, training, the gauntlet
  earlystop  the champion's own training: held-out loss by epoch, the epoch exported, the stop

Light surface and the reference categorical palette (validated: blue, orange, aqua); hover a mark in
the SVG for its numbers. The fleet keeps deciding generations, so rerun this after each verdict.
"""
import datetime as dt
import glob
import json
import os
import statistics
import subprocess
from html import escape

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
OUT = os.path.join(ROOT, "docs", "media", "training")
GENS = json.load(open(os.path.join(ROOT, "docs", "data", "generations.json")))
SURF, INK, INK2, MUTED, GRID, BASE = "#fcfcfb", "#0b0b0b", "#52514e", "#8a8984", "#e9e8e3", "#c9c8c2"
BLUE, ORANGE, AQUA = "#2a78d6", "#eb6834", "#1baf7a"
FONT = "-apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif"


def when(g):
    return dt.datetime.fromisoformat(g["decided"])


def svg(w, h, body, title):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" width="{w}" height="{h}" viewBox="0 0 {w} {h}" font-family="{FONT}">'
            f'<title>{escape(title)}</title><rect width="{w}" height="{h}" fill="{SURF}"/>{body}</svg>\n')


def text(x, y, s, size=15, color=INK2, anchor="start", weight=400, extra=""):
    return f'<text x="{x:.1f}" y="{y:.1f}" font-size="{size}" fill="{color}" text-anchor="{anchor}" font-weight="{weight}" {extra}>{escape(s)}</text>'


def heading(title, sub, w):
    return text(40, 46, title, 24, INK, weight=650) + text(40, 74, sub, 15, INK2)


def champions():
    """Who each generation played: plain Medium until the first election, then the last elected."""
    champ, out = "plain Medium", []
    for g in GENS:
        out.append(champ)
        if g["elected"]:
            champ = g["name"]
    return out


# --------------------------------------------------------------------------------------------
def timeline():
    W, H, L, R, T, B = 1200, 560, 80, 1080, 130, 470
    t0, t1 = when(GENS[0]), when(GENS[-1])
    pad = (t1 - t0) * 0.04
    t0, t1 = t0 - pad, t1 + pad
    X = lambda t: L + (R - L) * (t - t0) / (t1 - t0)
    zmin, zmax = -4, 4.5
    Y = lambda z: B - (B - T) * (z - zmin) / (zmax - zmin)
    elected = [g for g in GENS if g["elected"]]
    body = heading(f"{len(GENS)} generations decided, {len(elected)} elected",
                   "Each new net plays the champion of the day, 200 games; it takes the crown only if it wins by more than two standard errors.", W)
    # The champion of the day, as bands.
    ch = champions()
    spans, start = [], 0
    for i in range(1, len(GENS) + 1):
        if i == len(GENS) or ch[i] != ch[start]:
            spans.append((start, i - 1, ch[start]))
            start = i
    for k, (a, b, name) in enumerate(spans):
        xa = L if a == 0 else (X(when(GENS[a - 1])) + X(when(GENS[a]))) / 2
        xb = R if b == len(GENS) - 1 else (X(when(GENS[b])) + X(when(GENS[b + 1]))) / 2
        body += f'<rect x="{xa:.1f}" y="{T - 22}" width="{xb - xa:.1f}" height="{B - T + 22}" fill="{"#f3f2ee" if k % 2 == 0 else SURF}"/>'
        label = f"against {name}" if xb - xa > 150 else f"vs {name.split()[-1]}"
        body += text(xa + 10, T - 6, label, 13, INK2)
    for z in range(-4, 5, 2):
        body += f'<line x1="{L}" x2="{R}" y1="{Y(z):.1f}" y2="{Y(z):.1f}" stroke="{GRID}"/>' + text(L - 12, Y(z) + 5, f"{z:+d}" if z else "0", 13, MUTED, "end")
    body += f'<line x1="{L}" x2="{R}" y1="{Y(0):.1f}" y2="{Y(0):.1f}" stroke="{BASE}" stroke-width="1.5"/>'
    body += f'<line x1="{L}" x2="{R}" y1="{Y(2):.1f}" y2="{Y(2):.1f}" stroke="{INK2}" stroke-dasharray="6 5"/>'
    body += text(R + 8, Y(2) + 5, "the bar, z = 2", 13, INK, weight=600) + text(R + 8, Y(0) + 5, "even", 13, INK2)
    body += text(L - 60, (T + B) / 2, "z against the champion", 13, MUTED, "middle", extra=f'transform="rotate(-90 {L - 60} {(T + B) / 2})"')
    # Time ticks: every six hours, UTC.
    tick = t0.replace(minute=0, second=0, microsecond=0)
    tick += dt.timedelta(hours=(6 - tick.hour % 6) % 6 or 6)
    while tick < t1:
        x = X(tick)
        body += f'<line x1="{x:.1f}" x2="{x:.1f}" y1="{B}" y2="{B + 6}" stroke="{BASE}"/>'
        body += text(x, B + 24, tick.strftime("%b %-d, %H:%M"), 13, MUTED, "middle")
        tick += dt.timedelta(hours=6)
    body += text((L + R) / 2, B + 52, "when the verdict came, UTC (each generation takes about 40 minutes on the fleet)", 13, MUTED, "middle")
    for g in GENS:
        x, y = X(when(g)), Y(max(zmin, min(zmax, g["z"])))
        tip = (f'{g["name"]}: {100 * g["score"]:.1f}% over {g["games"]} games, z {g["z"]:+.2f}, '
               f'{"elected" if g["elected"] else "not elected"} · {when(g).strftime("%b %-d %H:%M")} UTC')
        if g["elected"]:
            body += f'<g><title>{escape(tip)}</title><circle cx="{x:.1f}" cy="{y:.1f}" r="8" fill="{BLUE}" stroke="{SURF}" stroke-width="2"/></g>'
            if g["z"] > 3:  # near the top: the label goes to the left of the dot
                body += text(x - 16, y - 2, f'{g["name"]} elected', 14, INK, "end", 650)
                body += text(x - 16, y + 16, f'{100 * g["score"]:.1f}%, z {g["z"]:+.2f}', 13, INK2, "end")
            else:
                body += text(x, y - 18, f'{g["name"]} elected', 14, INK, "middle", 650)
                body += text(x, y - 36, f'{100 * g["score"]:.1f}%, z {g["z"]:+.2f}', 13, INK2, "middle")
        else:
            body += f'<g><title>{escape(tip)}</title><circle cx="{x:.1f}" cy="{y:.1f}" r="6" fill="#a9a8a2" stroke="{SURF}" stroke-width="2"/></g>'
    # Name the first and the latest, and the lowest, so the reader can find their way.
    for g in (GENS[0], GENS[-1]):
        if not g["elected"]:
            x, y = X(when(g)), Y(max(zmin, min(zmax, g["z"])))
            body += text(x, y + 22, g["name"], 12, INK2, "middle")
    return svg(W, H + 10, body, "The generations' verdicts")


# --------------------------------------------------------------------------------------------
def cost():
    rows = GENS
    W, L, R, T, rh = 1200, 120, 1000, 150, 24
    H = T + rh * len(rows) + 70
    mx = max(sum(g["laneSeconds"].values()) for g in rows) / 60
    step = 20
    top = step * (int(mx / step) + 1)
    X = lambda m: L + (R - L) * m / top
    body = heading("Where a generation's time goes",
                   "Lane-minutes per stage: the fleet's machines added up. Labelling dominates; one GPU trains in a few minutes.", W)
    keys = [("label", "label: the teacher's games", BLUE), ("train", "train: one GPU", ORANGE), ("gauntlet", "gauntlet: 200 games", AQUA)]
    lx = 40
    for k, name, col in keys:
        body += f'<rect x="{lx}" y="98" width="14" height="14" rx="3" fill="{col}"/>' + text(lx + 22, 110, name, 14, INK)
        lx += 34 + len(name) * 7.6
    for m in range(0, top + 1, step):
        body += f'<line x1="{X(m):.1f}" x2="{X(m):.1f}" y1="{T - 8}" y2="{T + rh * len(rows)}" stroke="{GRID}"/>'
        body += text(X(m), T + rh * len(rows) + 22, f"{m}", 13, MUTED, "middle")
    body += text((L + R) / 2, T + rh * len(rows) + 46, "lane-minutes", 13, MUTED, "middle")
    body += text(R + 20, T - 14, "wall clock", 13, MUTED)
    for i, g in enumerate(rows):
        y = T + i * rh
        body += text(L - 14, y + 16, g["name"], 13, INK if g["elected"] else INK2, "end", 650 if g["elected"] else 400)
        x = L
        for k, name, col in keys:
            v = g["laneSeconds"].get(k, 0) / 60
            w = max(0.0, (R - L) * v / top - 2)
            if w > 0:
                tip = f'{g["name"]} {k}: {v:.1f} lane-minutes'
                body += f'<g><title>{escape(tip)}</title><rect x="{x:.1f}" y="{y + 4}" width="{w:.1f}" height="{rh - 8}" rx="3" fill="{col}"/></g>'
            x += w + 2
        body += text(R + 20, y + 16, f'{g["wallSeconds"] / 60:.0f} min' + ("   elected" if g["elected"] else ""), 13, INK if g["elected"] else INK2,
                     weight=650 if g["elected"] else 400)
    return svg(W, H, body, "Lane time per generation")


# --------------------------------------------------------------------------------------------
def earlystop():
    champ = [g for g in GENS if g["elected"]][-1]["name"]
    meta = json.load(open(os.path.join(ROOT, "models", f"{champ}.json")))["meta"]
    hist = meta["history"]
    best = meta.get("bestEpoch")
    W, H, L, R, T, B = 1200, 520, 90, 1000, 120, 430
    ys = [h["valLoss"] for h in hist]
    lo, hi = min(ys), max(ys)
    span = hi - lo
    lo, hi = lo - span * 0.15, hi + span * 0.15
    X = lambda e: L + (R - L) * e / max(1, len(hist) - 1)
    Y = lambda v: B - (B - T) * (v - lo) / (hi - lo)
    body = heading(f"How the champion ({champ}) stopped training",
                   f"Held-out loss (value error + policy cross-entropy) on {meta['held_out']:,} positions it never trained on, by epoch.", W)
    import math
    step = 0.01 if hi - lo > 0.03 else 0.005
    lo, hi = math.floor(lo / step) * step, math.ceil(hi / step) * step
    for k in range(int(round((hi - lo) / step)) + 1):
        v = lo + step * k
        body += f'<line x1="{L}" x2="{R}" y1="{Y(v):.1f}" y2="{Y(v):.1f}" stroke="{GRID}"/>' + text(L - 12, Y(v) + 5, f"{v:.3f}", 13, MUTED, "end")
    for e in range(len(hist)):
        body += text(X(e), B + 24, str(e), 13, MUTED, "middle")
    body += text((L + R) / 2, B + 52, "epoch (0 is the warm start, before any training)", 13, MUTED, "middle")
    pts = " ".join(f"{X(e):.1f},{Y(v):.1f}" for e, v in enumerate(ys))
    body += f'<polyline points="{pts}" fill="none" stroke="{BLUE}" stroke-width="2.5" stroke-linejoin="round"/>'
    for e, v in enumerate(ys):
        tip = f"epoch {e}: held-out loss {v:.5f}" + (f", policy top-1 {hist[e]['valPolicyTop1']:.3f}" if 'valPolicyTop1' in hist[e] else "")
        r = 8 if e == best else 5
        body += f'<g><title>{escape(tip)}</title><circle cx="{X(e):.1f}" cy="{Y(v):.1f}" r="{r}" fill="{BLUE}" stroke="{SURF}" stroke-width="2"/></g>'
    if best is not None:
        body += text(X(best), Y(ys[best]) + 30, f"epoch {best}: the best, exported", 14, INK, "middle", 650)
    last = len(hist) - 1
    if meta.get("stoppedEarly"):
        body += f'<line x1="{X(last):.1f}" x2="{X(last):.1f}" y1="{T}" y2="{B}" stroke="{INK2}" stroke-dasharray="6 5"/>'
        body += text(X(last) + 10, T + 14, f"stopped: {meta.get('patience', 3)} epochs", 13, INK, weight=600)
        body += text(X(last) + 10, T + 32, "without a better one", 13, INK2)
    return svg(W + 120, H, body, "Early stopping")


# --------------------------------------------------------------------------------------------
def queue():
    W, H = 1320, 520
    med = lambda k: statistics.median(g["laneSeconds"].get(k, 0) / 60 for g in GENS[1:])
    walls = statistics.median(g["wallSeconds"] / 60 for g in GENS[1:])
    labels = statistics.median(g.get("labels", 0) for g in GENS[1:])
    hosts = sorted({h for g in GENS for h in g.get("hosts", [])})
    body = heading("One generation through the Orrery queue",
                   f"The planner adds the jobs; the fleet's lanes take them. Medians over {len(GENS) - 1} generations: {walls:.0f} minutes from first job to verdict.", W)
    boxes = [
        ("plan", "plan.json", ["the generations to run,", "throttle, the election bar"], 40, BASE),
        ("prep", "pc-prep", ["once per lane and version:", "git archive HEAD, tests,", "Rust build, PyTorch venv"], 250, BASE),
        ("label", "pc-label  x30", ["CPU lanes: a 3-ply teacher", f"labels ~{labels / 1000:.0f}k positions", f"{med('label'):.0f} lane-min"], 460, BLUE),
        ("train", "pc-train  x1", ["a GPU lane (GTX 1080 Ti):", "PyTorch, early stopping", f"{med('train'):.1f} lane-min"], 670, ORANGE),
        ("gaunt", "pc-gauntlet", ["CPU lanes: 200 games,", "challenger vs champion", f"{med('gauntlet'):.0f} lane-min"], 880, AQUA),
        ("vote", "the verdict", ["elected if z > 2 and it", "holds an election; then", "commit + push to main"], 1090, INK),
    ]
    y0, bw, bh = 150, 190, 150
    for key, name, lines, x, col in boxes:
        body += f'<rect x="{x}" y="{y0}" width="{bw}" height="{bh}" rx="12" fill="{SURF}" stroke="{col}" stroke-width="{3 if col not in (BASE,) else 1.5}"/>'
        body += f'<rect x="{x}" y="{y0}" width="{bw}" height="8" rx="4" fill="{col}"/>'
        body += text(x + 16, y0 + 38, name, 17, INK, weight=650)
        for k, ln in enumerate(lines):
            body += text(x + 16, y0 + 66 + k * 22, ln, 13.5, INK2 if k < len(lines) - 1 or "lane-min" not in ln else INK,
                         weight=650 if "lane-min" in ln else 400)
    for k in range(len(boxes) - 1):
        xa = boxes[k][3] + bw
        xb = boxes[k + 1][3]
        y = y0 + bh / 2
        body += f'<line x1="{xa + 4}" x2="{xb - 10}" y1="{y}" y2="{y}" stroke="{INK2}" stroke-width="2"/>'
        body += f'<path d="M{xb - 12},{y - 6} L{xb - 2},{y} L{xb - 12},{y + 6} Z" fill="{INK2}"/>'
    # The loop: the next generation labels with the champion.
    ly = y0 + bh + 60
    body += (f'<path d="M{1090 + bw / 2},{y0 + bh + 4} L{1090 + bw / 2},{ly} L{460 + bw / 2},{ly} L{460 + bw / 2},{y0 + bh + 12}" '
             f'fill="none" stroke="{INK2}" stroke-width="2" stroke-dasharray="7 5"/>')
    body += f'<path d="M{460 + bw / 2 - 6},{y0 + bh + 16} L{460 + bw / 2},{y0 + bh + 4} L{460 + bw / 2 + 6},{y0 + bh + 16} Z" fill="{INK2}"/>'
    body += text((460 + 1090 + bw) / 2, ly + 24, "the planner appends the next generation: it labels with the champion's games and warm-starts from its weights", 14, INK, "middle")
    body += text((460 + 1090 + bw) / 2, ly + 46, "(expert iteration); an elected net lands in models/, every verdict in docs/data/generations.json and lineage.tsv", 13, INK2, "middle")
    body += text(40, H - 46, f"Lanes that ran Parliament jobs: {', '.join(hosts)}.", 13, INK2)
    body += text(40, H - 22, "The planner runs beside treadcore's (multi.py) and keeps at most a few Parliament jobs waiting, so the other projects' queues barely move.", 13, INK2)
    return svg(W, H, body, "The Orrery queue")


def main():
    os.makedirs(OUT, exist_ok=True)
    figs = {"timeline": timeline(), "queue": queue(), "cost": cost(), "earlystop": earlystop()}
    for name, s in figs.items():
        path = os.path.join(OUT, f"fig-gen-{name}.svg")
        open(path, "w").write(s)
        png = path[:-4] + ".png"
        r = subprocess.run(["node", os.path.join(ROOT, "tools", "svg2png.mjs"), path, png, "2"], capture_output=True, text=True)
        print(png if r.returncode == 0 else f"{path} (no PNG: {r.stderr.strip()[:200]})")
    elected = [g["name"] for g in GENS if g["elected"]]
    stats = {"generations": len(GENS), "elected": elected, "champion": elected[-1] if elected else None,
             "labels": sum(g.get("labels", 0) for g in GENS),
             "laneHours": round(sum(sum(g["laneSeconds"].values()) for g in GENS) / 3600, 1),
             "medianWallMinutes": round(statistics.median(g["wallSeconds"] / 60 for g in GENS[1:]), 1),
             "first": GENS[0]["decided"], "last": GENS[-1]["decided"]}
    json.dump(stats, open(os.path.join(ROOT, "docs", "data", "training-summary.json"), "w"), indent=1)
    print(json.dumps(stats))


if __name__ == "__main__":
    main()
