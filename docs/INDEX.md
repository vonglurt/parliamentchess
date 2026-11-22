# Parliament Chess: contents

Everything in the project, and where to find it. Start with the website's three doors, then go as
deep as you like.

## Start here

| | |
| --- | --- |
| [The website](https://vonglurt.github.io/parliamentchess/) | The splash page: play it, what it is, the trained net |
| [The player's guide](guide.html) | Run it in a minute, your first game, Writs, the count, when to move the crown |
| [What is this project?](about.html) | The idea, whether it is balanced, how it is built, every film |
| [Parliament Chess's own net](net.html) | How it sees, six classics through its eyes, how the fleet trained it |
| [README](../README.md) | The same story on GitHub, with every command |
| [RULES.md](../RULES.md) | The rules of the realm, in full |

## Documentation

| Document | What it covers |
| --- | --- |
| [How it works (TECHNICAL.md)](TECHNICAL.md) | The engine, the bots, the net, training, the Orrery queue, server and client, measuring, films, tests |
| [The lab reports](parliament-chess-lab-report.md) ([HTML](parliament-chess-lab-report.html)) | Reports I–XIII, IEEE style, with every measurement and the negative results |
| This page (INDEX.md) | The contents |

### The lab reports, one line each

| Report | Subject |
| --- | --- |
| I | The concept: the Ledger, the electorate, the election turn by turn |
| II | Prior art and the chosen system (the Golden Bull model) |
| III | Balance analysis: timing, exploits closed, the Queen question |
| IV | Implementation plan and architecture |
| V | The AI: Book-Return Search and the first value net |
| VI | The Orrery queue: the demo reel and the balance census |
| VII | The film, conclusions and future work |
| VIII | GPU training and generation timing |
| IX | Parliament Chess in the shared Orrery queue |
| X | Teaching the net to govern |
| XI | Faster generations: a packed trainer, shared GPUs, a Rust labeller |
| XII | The net's mind: a live trace from inputs to move |
| XIII | Early stopping, the first election since generation 5, and each side's calculation as a matrix |

## Films

| Film | Length | |
| --- | --- | --- |
| [Parliament Chess](media/parliament_chess.mp4) | 91 s | The idea and the evidence, 3Blue1Brown style (Manim) |
| [The player's guide](media/players_guide.mp4) | 106 s | Your first game on the real client |
| [How a neural net plays](media/net_mind.mp4) | 192 s | The champion against Medium, everything it computes |
| [The Net Reads the Classics](media/net_classics.mp4) | about 6 min | Six famous short games through the net's mind: Manim chapters and a live recording |

## Data

| File | What it holds | Made by |
| --- | --- | --- |
| [data/generations.json](data/generations.json) | Every generation's verdict: score, z, elections, labels, lane time, hosts | the fleet, after each verdict |
| [data/lineage.tsv](data/lineage.tsv) | The same, one line a generation | the fleet |
| [data/training-summary.json](data/training-summary.json) | Totals: generations, elected, labels, lane-hours | `tools/fig_training.py` |
| [data/classics.json](data/classics.json) | Six classics: every position's trace, the net's rank of history's move, three players' picks | `tools/classics.php` |
| [data/net-classics-chapters.json](data/net-classics-chapters.json) | The classics film's chapter times | `film/classics_cut.py` |
| [data/brain-stats.json](data/brain-stats.json) | What the net's mind shows across real games | `tools/brain_stats.php` |
| [data/crown-theory.json](data/crown-theory.json) | When a Writ moves the crown | `bin/crown-theory.php` |
| [data/census-analysis.json](data/census-analysis.json), [data/netcheck-analysis.json](data/netcheck-analysis.json) | Balance census and net check statistics | `bin/analyze.php` |
| [../models/](../models) | The elected nets (`g005.json`, `g015.json`): weights and training history | the fleet |

## Figures

| Figure | Shows |
| --- | --- |
| [media/training/fig-gen-timeline.png](media/training/fig-gen-timeline.png) | Each generation's verdict against the champion of the day |
| [media/training/fig-gen-queue.png](media/training/fig-gen-queue.png) | One generation through the Orrery queue |
| [media/training/fig-gen-cost.png](media/training/fig-gen-cost.png) | Lane time per stage, per generation |
| [media/training/fig-gen-earlystop.png](media/training/fig-gen-earlystop.png) | The champion's held-out loss by epoch |
| [media/fig-election.png](media/fig-election.png) | Who votes in a real count |
| [media/fig-crown-theory.png](media/fig-crown-theory.png) | How often the crown moved after a Writ |
| [media/net-mind-sidebar.png](media/net-mind-sidebar.png), [media/net-mind-matrix.png](media/net-mind-matrix.png) | MIND, beside and under the board |
| [media/guide/](media/guide) | Screens of the real client used by the guide |

## Source code

| Folder | What is in it |
| --- | --- |
| [src/Engine](../src/Engine) | `Position` (every rule, Writs, the count, the Ledger), `Game` (log, SAN, PCN), `Recording`, `Workers` |
| [src/AI](../src/AI) | `Search` (Book-Return Search), `Evaluator`, `KnownSpace` (SQLite book), `Sensors`, `Net`, `Net2`, `Players` |
| [src/Server](../src/Server) | `Api` (JSON routes), `Store` (one file per game), `View`, `Names` (nuts, legumes, squash), `Classics` (famous games) |
| [public/](../public) | `index.php` (router), `index.html` (landing), `play.html` and `assets/app.js` (the game, MIND), `assets/app.css` |
| [bin/](../bin) | `serve`, `php`, `test`, `test-browsers`, `selfplay`, `label`, `train`, `analyze`, `crown-theory`, `bench-*`, `orrery-plan`, `orrery-gen`, `orrery-local` |
| [train/](../train) | `train_torch.py` and its requirements (`requirements-pascal.txt` for GTX 10-series cards) |
| [rust/](../rust) | The labeller in Rust: rules, evaluation, search, sensors, the net; `pc-label`, `pc-parity` |
| [tools/](../tools) | `classics.php`, `brain_stats.php`, `fig_training.py`, `svg2png.mjs`, `report2docs.py`, `rust_parity.php`, figure scripts |
| [film/](../film) | The Manim films (`parliament.py`, `guide.py`, `classics.py`), the browser recordings (`brain_demo.mjs`, `classics_capture.mjs`), the cut |
| [orrery/](../orrery) | Local queue folders: `demos` (with results), `census` (reports), `gen`, `netcheck` |
| [docs/](.) | GitHub Pages: the website, the lab reports, media and data |

## Commands

```bash
make up / make down / make test                 # play, stop, test
sh bin/php tools/classics.php                   # the classics through the net (3 minutes)
python3 tools/fig_training.py                   # the training figures from the latest verdicts
sh film/classics.sh                             # the classics film (Manim, a live recording, the cut)
php bin/orrery-local.php orrery/demos           # the demo reel on one machine
```
