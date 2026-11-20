#!/bin/sh
# Render the Parliament Chess film.
#   sh film/render.sh      1920x1080, 30 fps  -> film/out/parliament_chess.mp4
#   sh film/render.sh l    480p preview       -> film/out/parliament_chess_preview.mp4
cd "$(dirname "$0")/.." || exit 1
Q="${1:-h}"
manim -q"$Q" --fps 30 --media_dir film/media -o parliament_chess film/parliament.py ParliamentChess || exit 1
mkdir -p film/out
case "$Q" in h) RES=1080p30 ;; k) RES=2160p30 ;; m) RES=720p30 ;; *) RES=480p30 ;; esac
SRC="film/media/videos/parliament/$RES/parliament_chess.mp4"
if [ "$Q" = "h" ]; then
  cp "$SRC" film/out/parliament_chess.mp4 && echo "film/out/parliament_chess.mp4"
else
  cp "$SRC" film/out/parliament_chess_preview.mp4 && echo "film/out/parliament_chess_preview.mp4"
fi
