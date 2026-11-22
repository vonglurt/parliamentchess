"""Cut "The Net Reads the Classics": the Manim chapters, the live capture and the transitions, in one MP4.

    python3 film/classics_cut.py [QUALITY]      # QUALITY 1080p30 (default) or 480p15, Manim's folder name

Reads film/media/videos/classics/QUALITY/*.mp4 and film/out/classics_live.mp4 (+ .json, the ply times
film/classics_capture.mjs wrote). The live stretch between ffFrom and ffTo plays at 4x. Writes
film/out/net_classics.mp4, docs/media/net_classics.mp4 (smaller), a poster still, and the chapter times.
"""
import json
import os
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.join(HERE, "..")
Q = sys.argv[1] if len(sys.argv) > 1 else "1080p30"
VID = os.path.join(HERE, "media", "videos", "classics", Q)
OUT = os.path.join(HERE, "out")
LIVE = os.path.join(OUT, "classics_live.mp4")
W, H = (1920, 1080) if Q.startswith("1080") else (854, 480)


def dur(path):
    return float(subprocess.check_output(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", path]).decode())


times = json.load(open(LIVE.replace(".mp4", ".json")))
live_len = dur(LIVE)
ff_from, ff_to = times["ffFrom"], times["ffTo"]
# (input, trim start, trim end or None, speed, chapter title or None, transition into the next piece)
pieces = [
    ("ColdOpen", None, None, 1, "Cold open: Morphy's 16.Qb8+", "fadewhite"),
    ("Title", None, None, 1, None, "fadeblack"),
    ("HowItSees", None, None, 1, "1. How the net sees", "smoothleft"),
    ("Traps", None, None, 1, "2. The traps: Fool's and Scholar's Mate", "smoothleft"),
    ("Legal", None, None, 1, "3. Légal's Mate, 1750", "smoothleft"),
    ("Reti", None, None, 1, "4. Réti–Tartakower, 1910", "smoothleft"),
    ("Lasker", None, None, 1, "5. The King hunt: Lasker–Thomas, 1912", "fadeblack"),
    ("LiveCard", None, None, 1, "6. Live: the Opera Game, 1858", "pixelize"),
    (LIVE, 0, ff_from, 1, None, None),
    (LIVE, ff_from, ff_to, 4, None, None),
    (LIVE, ff_to, live_len, 1, None, "pixelize"),
    ("Scoreboard", None, None, 1, "7. The scoreboard", "fadeblack"),
    ("Outro", None, None, 1, "It beats its maker", None),
]
XF = 0.45  # transition length
args, chains, labels = ["ffmpeg", "-y", "-loglevel", "error"], [], []
lengths = []
for n, (src, a, b, speed, _, _) in enumerate(pieces):
    path = src if src.endswith(".mp4") else os.path.join(VID, src + ".mp4")
    args += ["-i", path]
    full = dur(path)
    a = a or 0.0
    b = full if b is None else b
    f = f"[{n}:v]trim={a:.3f}:{b:.3f},setpts=(PTS-STARTPTS)/{speed},fps=30,scale={W}:{H}:force_original_aspect_ratio=decrease,pad={W}:{H}:(ow-iw)/2:(oh-ih)/2,format=yuv420p,settb=1/30[v{n}]"
    chains.append(f)
    lengths.append((b - a) / speed)
# Join: pieces with a transition get an xfade; the three live pieces are joined by plain concatenation.
cur, cur_len, k = "v0", lengths[0], 0
chapters = []
t_start = 0.0
if pieces[0][4]:
    chapters.append((0.0, pieces[0][4]))
for n in range(1, len(pieces)):
    prev_tr = pieces[n - 1][5]
    out = f"j{n}"
    if prev_tr:
        off = cur_len - XF
        chains.append(f"[{cur}][v{n}]xfade=transition={prev_tr}:duration={XF}:offset={off:.3f}[{out}]")
        start = off
        cur_len = cur_len + lengths[n] - XF
    else:
        chains.append(f"[{cur}][v{n}]concat=n=2:v=1:a=0,settb=1/30,fps=30[{out}]")
        start = cur_len
        cur_len = cur_len + lengths[n]
    if pieces[n][4]:
        chapters.append((start, pieces[n][4]))
    cur = out
os.makedirs(OUT, exist_ok=True)
master = os.path.join(OUT, "net_classics.mp4" if Q.startswith("1080") else "net_classics_preview.mp4")
args += ["-filter_complex", ";".join(chains), "-map", f"[{cur}]", "-c:v", "libx264", "-preset", "slow", "-crf", "18", "-pix_fmt", "yuv420p",
         "-movflags", "+faststart", master]
subprocess.run(args, check=True)
print(f"{master}: {dur(master):.1f} s")
fmt = lambda t: f"{int(t // 60)}:{int(t % 60):02d}"
txt = "\n".join(f"{fmt(t)} {name}" for t, name in chapters)
open(os.path.join(OUT, "net_classics.chapters.txt"), "w").write(txt + "\n")
print(txt)
if Q.startswith("1080"):
    site = os.path.join(ROOT, "docs", "media", "net_classics.mp4")
    subprocess.run(["ffmpeg", "-v", "error", "-y", "-i", master, "-c:v", "libx264", "-crf", "27", "-preset", "slow", "-pix_fmt", "yuv420p",
                    "-movflags", "+faststart", site], check=True)
    json.dump([{"t": round(t, 1), "title": name} for t, name in chapters], open(os.path.join(ROOT, "docs", "data", "net-classics-chapters.json"), "w"), indent=1)
    print(f"{site}: {os.path.getsize(site) / 1e6:.1f} MB")
