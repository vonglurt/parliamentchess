#!/bin/sh
# Render the Parliament Chess film.
#   sh film/render.sh      1920x1080, 30 fps  -> film/out/parliament_chess.mp4
#   sh film/render.sh l    480p preview       -> film/out/parliament_chess_preview.mp4
#   sh film/render.sh guide  the player's guide -> film/out/players_guide.mp4, docs/media/players_guide.mp4
#                            (take the screenshots first: see film/guide_shots.mjs)
cd "$(dirname "$0")/.." || exit 1
if [ "$1" = "guide" ]; then
  manim -qh --fps 30 --media_dir film/media -o players_guide film/guide.py PlayersGuide || exit 1
  mkdir -p film/out
  cp film/media/videos/guide/1080p30/players_guide.mp4 film/out/players_guide.mp4
  ffmpeg -v error -y -i film/out/players_guide.mp4 -c:v libx264 -crf 28 -preset slow -pix_fmt yuv420p -movflags +faststart docs/media/players_guide.mp4
  ffmpeg -v error -y -ss 44 -i film/out/players_guide.mp4 -frames:v 1 docs/media/still-guide.png
  echo "film/out/players_guide.mp4 docs/media/players_guide.mp4"
  exit 0
fi
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
