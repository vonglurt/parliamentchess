# Parliament Chess (Vote King)

**Every move FIDE. Every crown elected.**

Parliament Chess is chess with one new office: the crown. File a *Writ* and your own
pieces vote by proximity — pawns in the Commons, Knights, Bishops and Rooks in the
College — on whether the crown should pass from your King to a Candidate. A dethroned
King becomes a pawn, a *Pretender*, who may never again promote higher than a Rook.

It is a parody in the tradition of Rock Paper Scissors Lizard Spock: the base game is
untouched, the extension is the joke, and the joke has to be balanced.

- **Rules:** [RULES.md](RULES.md) (also served at `/?rules=1`)
- **Lab reports:** [docs/parliament-chess-lab-report.md](docs/parliament-chess-lab-report.md)
  (also as [HTML](docs/parliament-chess-lab-report.html)): Reports I–IX, from the rules to the fleet.
  The data behind them is in [docs/data](docs/data) and the figures in [docs/media](docs/media)
- **Film:** `docs/media/parliament_chess.mp4`, a 3Blue1Brown-style explainer made with Manim
- **Player's guide:** `docs/media/players_guide.mp4`, your first game on the real client in 106 seconds (`sh film/render.sh guide`)
- **License:** MIT

## Run it

Requires PHP 8.2+ (tested on 8.5 and 8.6); `pdo_sqlite` adds the opening book. No Composer, no other dependencies.

```bash
make up        # the server in the background: http://localhost:8080 (make up PORT=8081 for another port)
make down      # stop it
make test      # 45 rule tests, then two browsers playing each other through a server of their own
```

`/` is the landing page and `/play.html` the game. From the game's lobby you can:

| | |
| --- | --- |
| **Two browsers** | Create a game; send the second link to your opponent. Each link carries its own token. |
| **Play the machine** | Easy (1 ply), Medium (2 ply) or Hard (3 ply), as a Whig, Populist or Monarchist. |
| **Watch the machines** | Two bots play while you watch; any number of spectators can follow by link. |
| **Replays** | Every recorded game, the demo reel included; step, scrub or autoplay. |
| **Record** | Any live game or replay records straight to WebM (the board is a canvas). |
| **Orrery** | The self-play queues drawn as orbits, with their census tables. |

**Names.** You start as a pumpkin or another large squash (Atlantic Giant, Big Max, Jarrahdale ...)
and can rename yourself in the lobby or mid-game; your name is kept in your browser. Bots are named at
random from sixty nuts, legumes and squash, by their common or their Latin name: *Cicer arietinum*,
Macadamia, *Cucurbita argyrosperma*, Black-eyed Pea ...

**The game record.** During the game and after it, the side panel lists every move with its number,
its player and its time; **CSV**, **JSON** and **PCN** download the whole record (`/api/record?id=`
gives it as JSON: player, notation, UCI and an ISO 8601 UTC timestamp per move).

**Every combination.** Two people (two browsers), a person against a bot (either colour, three
levels, three styles), or two bots with spectators. Every chess rule is in: castling on both sides,
en passant, promotion and underpromotion, the fifty-move rule, threefold repetition, and the
election layer (Writs, counts, the Ledger). `bin/test-browsers.php` plays these through the server.

## Tools

```bash
sh bin/php bin/test.php                       # the rule tests (bin/php is php with the JIT on)
php bin/test-browsers.php                     # two browsers, a spectator, a bot and two bots, through a server
sh bin/php bin/selfplay.php --white medium/populist --black medium/monarchist --games 4 --seed 1
php bin/orrery-local.php orrery/demos         # the demo reel through the Orrery Queue, on one machine
sh bin/php bin/analyze.php orrery/census      # balance statistics with 95% intervals
sh bin/php bin/label.php --games 8 --depth 3  # teacher labels for the net
make export EXPORT=export.html                # the lab report, from the shared doc's export
sh film/render.sh                             # render the Manim film
node film/guide_shots.mjs && sh film/render.sh guide   # the player's guide (screenshots need a server on :8091)
sh bin/php bin/crown-theory.php               # when to move the crown, from the census Writs
```

## Training the second-generation net (GPU)

Search gets geometrically dearer with depth: on census positions a label costs 0.03 s at 2 plies,
0.18 s at 3 and 0.76 s at 4 (`bin/bench-depth.php`), an effective branching factor of 5 to 8.
So depth is paid for once, offline. A teacher searches one ply deeper than the player; a student
net learns, in one look, the teacher's value and its score for every move (action values). It
reads binary "sensors" that see a move or two ahead without searching (`src/AI/Sensors.php`):
attack maps, hanging pieces, King-zone pressure, mobility, threats, and the election (Writs,
Term, whether filing now could crown).

```bash
sh bin/php bin/label.php --games 8 --depth 3 --out data/labels/x          # teacher labels (CPU)
.venv/bin/python train/train_torch.py --data 'data/labels/*/shard.jsonl.gz' # train (CUDA, MPS or CPU)
php bin/orrery-local.php orrery/gen                                       # a whole generation as Orrery jobs
```

`bin/orrery-gen.php` is the generation planner: label jobs on CPU lanes, one `needs: gpu` train job
(the keeper `send`s it every shard), gauntlet jobs on CPU lanes, then an election. The challenger
becomes champion only if it beats the incumbent by more than two standard errors, crucible's ladder
rule, and later generations label with the champion (expert iteration). `lineage.tsv` and
`generations.json` record each generation's score, election and lane-seconds per stage. Players
`medium+net2` use the trained weights: a residual net (the default, `--mode residual`) adds its
correction to the hand-written evaluation, a value net replaces it, and either orders moves with its
policy head; `medium+net2policy` only orders moves. On the fleet's Pascal cards install
`train/requirements-pascal.txt` (PyTorch 2.14.1+cu126, the last build with Pascal kernels) and run
eagerly. `orrery/gen/plan.fleet.json` is a fleet-scale template for the next two generations.

## The Orrery Queue

Parliament Chess is a project of the grove fleet, beside treadcore and crucible, and has a card and
a column on the console's Projects page.

| Where | What |
| --- | --- |
| grove `config.json` | project `parliament-chess`, kinds `pc-prep`, `pc-label`, `pc-train`, `pc-gauntlet`; capability `php` on the lanes with PHP (columbia, saskatchewan, saskatchewan-2, jaguaribe) |
| grove `config.json` `planner` | `orrery/tools/plans/multi.py`, which asks treadcore's planner exactly as before and then `bin/orrery-gen.php`; a failing Parliament planner is reported and left out, never stopping treadcore's |
| grove `parliament/` | `plan.json` (generations to run, and `throttle.queued`, the most Parliament jobs waiting at once), `generations.json` and `lineage.tsv` (each election and its lane-seconds), `champion.json`, `dist/` (the code shipped to lanes) |
| each lane | `pc-prep` once per code version: the repository in `~/parliament-chess`, the test suite on PHP lanes, a venv with PyTorch 2.14.1+cu126 on CUDA lanes; every other job waits on it (`after_host`) |

Label and gauntlet jobs fan out a worker per core (`--workers`), since an Orrery lane is a whole
machine. The planner keeps at most `throttle.queued` of them waiting, so the keeper's low water,
which counts every queued job a CPU lane could take, barely moves for treadcore. Pause, resume and
assign it like the others: `fleet-queue.py DIR project parliament-chess pause`, or the console's buttons.

Local queue folders (`orrery/demos`, `orrery/census`, `orrery/gen`) run without the fleet through
`bin/orrery-local.php DIR`, a one-machine keeper that keeps the same files.

## Layout

```
Makefile     up, down, status, logs, test, export
src/Engine   Position (rules, Writs, the Count, the Ledger), Game (log, notation), Recording, Workers
src/AI       Search (Book-Return Search), Evaluator, KnownSpace (SQLite B-tree book), Net, Net2, Sensors, Players
src/Server   Api, Store, View, Names (sixty nuts, legumes and squash; the pumpkins)
public/      index.php (router), index.html (landing), play.html (the game), assets/app.js, assets/app.css
bin/         serve, php, test, test-browsers, selfplay, label, train, analyze, orrery-plan, orrery-gen, orrery-local
train/       train_torch.py and its requirements (requirements-pascal.txt for GTX 10-series cards)
orrery/      queue folders: demos (with results), census (reports), gen, netcheck
film/        the Manim scene, its render script, and the rendered film
docs/        GitHub Pages: index.html, the lab report (md, html), media, analysis data
tools/       report2docs.py: the lab report from the shared doc into docs/
```

## The AI: Book-Return Search

A medium player that thinks two plies ahead and leans on what is already known. In the book
(opening theory plus every recorded game, stored as a SQLite `WITHOUT ROWID` table, which is a
clustered B-tree keyed by Zobrist hash) it plays a book move. Out of it, it runs a shallow
alpha-beta with quiescence, and at every frontier node it asks the book again, blending known
outcomes into the score, which pulls play back toward the known tree. A pending election is scored
by running its count exactly, so a Writ is judged by what it will actually do.

## Notation

PCN is PGN with two additions: a Writ is a move, `W@Nf5` (`W@e4` for a pawn), and each count is
a comment: `{Black count for d7: Commons 3-0, College 7-0 => coronation}`.
