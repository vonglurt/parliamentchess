<?php
declare(strict_types=1);

/*
 * Label positions for the second-generation net: play games with a fast player,
 * and label sampled positions with a deeper "teacher" search.
 *
 *   php bin/label.php --games 20 --depth 3 --seed 1 --out DIR [--player medium] [--every 3]
 *                     [--from-ply 8] [--opening-random 4] [--job ID] [--workers N]
 *
 * --workers N (0 = one per core) runs N copies at once, each --games games with its own seeds,
 * and merges their shards: an Orrery lane is a whole machine, so the job uses every core.
 *
 * Each record (one JSON line, gzipped shard DIR/shard.jsonl.gz):
 *   f   active Sensors v2 features (side to move's view)
 *   s   teacher score, centipawns, side to move's view (mates clamped to +-2000)
 *   h   the hand-written static evaluation of the same position (what a residual net corrects)
 *   r   final game result for the side to move (1, 0, -1)
 *   p   action values: [policy index, score, flags] for every legal root move
 *       (flags: 1 promotion, 2 Writ, 4 castling, 8 capture, 16 a Writ whose poll already crowns)
 *   --no-book: the players open without the opening book (the fleet's label jobs use it)
 * The teacher sees depth plies plus quiescence; the student learns to see it in one look.
 * DIR/summary.json records counts and timing, the generation's labelling cost.
 */
require __DIR__ . '/../src/autoload.php';

use Parliament\AI\Evaluator;
use Parliament\AI\Players;
use Parliament\AI\Search;
use Parliament\AI\Sensors;
use Parliament\Engine\Game;

$o = getopt('', ['games:', 'depth:', 'seed:', 'out:', 'player:', 'every:', 'from-ply:', 'opening-random:', 'job:', 'max-plies:', 'workers:', 'no-book']);
// --no-book: the players open without the book, so every lane labels alike (lanes without SQLite have none).
$noBook = isset($o['no-book']);
$games = (int)($o['games'] ?? 10);
$depth = (int)($o['depth'] ?? 3);
$seed = (int)($o['seed'] ?? 1);
$out = $o['out'] ?? pc_root() . '/data/labels/run-' . date('Ymd-His');
$player = $o['player'] ?? 'medium/populist';
$every = (int)($o['every'] ?? 3);
$fromPly = (int)($o['from-ply'] ?? 8);
$randomPlies = (int)($o['opening-random'] ?? 4);
$maxPlies = (int)($o['max-plies'] ?? 200);
if (!is_dir($out)) {
    mkdir($out, 0775, true);
}
$workers = isset($o['workers']) ? (int)$o['workers'] : 1;
if ($workers === 0) {
    $workers = \Parliament\Engine\Workers::cores();
}
if ($workers > 1) {
    $t0 = microtime(true);
    $codes = \Parliament\Engine\Workers::run(__FILE__, $workers, function (int $i) use ($o, $out, $seed, $depth, $games, $player, $every, $fromPly, $randomPlies, $maxPlies, $noBook): array {
        return [...($noBook ? ['--no-book'] : []), '--games', (string)$games, '--depth', (string)$depth, '--seed', (string)($seed * 100 + $i), '--player', $player,
            '--every', (string)$every, '--from-ply', (string)$fromPly, '--opening-random', (string)$randomPlies,
            '--max-plies', (string)$maxPlies, '--job', ($o['job'] ?? 'local') . "-w$i", '--out', "$out/w$i"];
    }, "$out/logs");
    // Gzip members concatenate into one valid gzip file: the shards merge by appending.
    $merged = fopen("$out/shard.jsonl.gz", 'wb');
    $sum = ['job' => $o['job'] ?? null, 'games' => 0, 'labels' => 0, 'depth' => $depth, 'player' => $player, 'workers' => $workers,
        'failedWorkers' => count(array_filter($codes)), 'sensors' => \Parliament\AI\Sensors::VERSION, 'inputs' => \Parliament\AI\Sensors::INPUTS,
        'results' => [], 'cpuSeconds' => 0.0];
    for ($i = 0; $i < $workers; $i++) {
        if (is_file("$out/w$i/shard.jsonl.gz")) {
            fwrite($merged, (string)file_get_contents("$out/w$i/shard.jsonl.gz"));
            $w = json_decode((string)file_get_contents("$out/w$i/summary.json"), true) ?: [];
            $sum['games'] += $w['games'] ?? 0;
            $sum['labels'] += $w['labels'] ?? 0;
            $sum['cpuSeconds'] += $w['seconds'] ?? 0;
            foreach ($w['results'] ?? [] as $k => $v) {
                $sum['results'][$k] = ($sum['results'][$k] ?? 0) + $v;
            }
            unlink("$out/w$i/shard.jsonl.gz");
        }
    }
    fclose($merged);
    $sum['seconds'] = round(microtime(true) - $t0, 1);
    $sum['labelsPerLaneHour'] = (int)round($sum['labels'] / max(1e-9, $sum['seconds']) * 3600);
    $sum['cpuSeconds'] = round($sum['cpuSeconds'], 1);
    file_put_contents("$out/summary.json", json_encode($sum, JSON_PRETTY_PRINT));
    echo json_encode($sum) . "\n";
    exit($sum['labels'] > 0 ? 0 : 1);
}
$gz = gzopen("$out/shard.jsonl.gz", 'wb6');
$book = $noBook ? null : Players::book();
$handEval = new Evaluator(); // the static evaluation a residual net learns to correct
$t0 = microtime(true);
$teacherMs = 0.0;
$n = 0;
$results = [];
for ($i = 0; $i < $games; $i++) {
    $gs = $seed * 1000 + $i;
    $rng = new \Random\Randomizer(new \Random\Engine\Mt19937($gs));
    $players = [1 => Players::make($player, $book, $gs), -1 => Players::make($player, $book, $gs + 1)];
    $teacher = new Search(new Evaluator(), null, $depth, 0, $gs);
    $teacher->maxNodes = PHP_INT_MAX;
    $g = new Game();
    $pending = [];
    for ($k = 0; $k < $randomPlies && $g->status === 'playing'; $k++) {
        $legal = $g->pos->legalMoves();
        $g->playInt($legal[$rng->getInt(0, count($legal) - 1)]);
    }
    while ($g->status === 'playing' && count($g->log) < $maxPlies) {
        $ply = count($g->log);
        if ($ply >= $fromPly && ($ply - $fromPly) % $every === 0) {
            $t = microtime(true);
            $info = $teacher->choose($g);
            $teacherMs += (microtime(true) - $t) * 1000;
            $pv = [];
            foreach ($teacher->rootScores as $m => $sc) {
                // Flags mark the non-standard moves a trainer may reward: 1 promotion, 2 Writ, 4 castling,
                // 8 capture, 16 a Writ whose count would already crown its Candidate (the crown-move
                // theory's first rule: in 1,712 census Writs, 79% of these moved the crown, 5.5% of the rest).
                $fl = $m >> 16;
                $flags = (($m >> 12) & 7 ? 1 : 0) | ($fl & \Parliament\Engine\Position::F_WRIT ? 2 : 0)
                    | ($fl & \Parliament\Engine\Position::F_CASTLE ? 4 : 0) | ($fl & \Parliament\Engine\Position::F_CAPTURE ? 8 : 0);
                if ($flags & 2 && in_array($g->pos->poll($g->pos->side, ($m >> 6) & 63)['outcome'], ['coronation', 'contested', 'swap'], true)) {
                    $flags |= 16;
                }
                $pv[] = [Sensors::moveIndex($g->pos, $m), max(-2000, min(2000, $sc)), $flags];
            }
            if ($pv) {
                $pending[] = ['g' => sprintf('%s-%d', $o['job'] ?? 'local', $gs), 'f' => Sensors::features($g->pos), 's' => max(-2000, min(2000, $info['score'])),
                    'h' => max(-2000, min(2000, $handEval->evaluate($g->pos))),
                    'side' => $g->pos->side, 'p' => $pv, 'ply' => $ply];
            }
        }
        $info = $players[$g->pos->side]->choose($g);
        if (!$info['move']) {
            break;
        }
        $g->playInt($info['move']);
    }
    $winner = $g->status === 'playing' ? null : $g->winner;
    $results[] = $winner === null ? '1/2' : ($winner === 1 ? '1-0' : '0-1');
    foreach ($pending as $rec) {
        $rec['r'] = $winner === null ? 0 : ($winner === $rec['side'] ? 1 : -1);
        unset($rec['side']);
        gzwrite($gz, json_encode($rec) . "\n");
        $n++;
    }
    fprintf(STDERR, "game %d/%d %s: %d labels so far, %.1fs\n", $i + 1, $games, end($results), $n, microtime(true) - $t0);
}
gzclose($gz);
$secs = microtime(true) - $t0;
$summary = ['job' => $o['job'] ?? null, 'games' => $games, 'labels' => $n, 'depth' => $depth, 'player' => $player,
    'seconds' => round($secs, 1), 'teacherSeconds' => round($teacherMs / 1000, 1),
    'labelsPerLaneHour' => (int)round($n / max(1e-9, $secs) * 3600), 'sensors' => Sensors::VERSION,
    'inputs' => Sensors::INPUTS, 'results' => array_count_values($results)];
file_put_contents("$out/summary.json", json_encode($summary, JSON_PRETTY_PRINT));
echo json_encode($summary) . "\n";
