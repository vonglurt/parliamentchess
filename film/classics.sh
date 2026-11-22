#!/bin/sh
# "The Net Reads the Classics": every Manim chapter, the live recording, the cut.
#   sh film/classics.sh            1080p30 -> film/out/net_classics.mp4, docs/media/net_classics.mp4
#   sh film/classics.sh preview    480p15  -> film/out/net_classics_preview.mp4 (uses the last live recording)
# The chapters read docs/data/classics.json (sh bin/php tools/classics.php) and models/.
# The live chapter needs Chrome and starts its own server on :8094.
cd "$(dirname "$0")/.." || exit 1
SCENES="ColdOpen Title HowItSees Traps Legal Reti Lasker LiveCard Scoreboard Outro"
if [ "$1" = "preview" ]; then Q="-ql"; DIR=480p15; else Q="-qh --fps 30"; DIR=1080p30; fi
for S in $SCENES; do
  manim $Q --media_dir film/media film/classics.py "$S" > "film/media/$S.log" 2>&1 || { echo "$S failed: film/media/$S.log"; exit 1; }
  echo "$S"
done
if [ "$1" != "preview" ] || [ ! -f film/out/classics_live.mp4 ]; then
  DATA=$(mktemp -d)
  PC_DATA_DIR="$DATA" sh bin/php -S 127.0.0.1:8094 -t public public/index.php > /dev/null 2>&1 &
  SERVER=$!
  sleep 1
  node film/classics_capture.mjs film/out/classics_live.mp4
  kill "$SERVER"
  rm -rf "$DATA"
fi
python3 film/classics_cut.py "$DIR"
ffmpeg -v error -y -ss 9 -i "film/media/videos/classics/$DIR/ColdOpen.mp4" -frames:v 1 docs/media/still-net-classics.png
