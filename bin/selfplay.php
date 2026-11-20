<?php
declare(strict_types=1);

/*
 * Self-play: two AI players, N games, recordings and a census summary.
 *
 *   php bin/selfplay.php --white medium --black medium/populist --games 4 --seed 1 --out data/recordings/x
 *       [--fen FEN] [--no-elections] [--max-plies 240] [--title T] [--learn] [--quiet] [--no-book]
 *       [--opening-random N]   play N random legal plies first (variety; still inside the Coronation Peace)
 *       [--moves "e4 e5 W@d3"]  a scripted start, played before the AIs take over
 *       [--queen-eligible]      the Regency variant: Queens may stand for the crown
 *       [--workers N]           N copies at once (0 = one per core), each playing --games games;
 *                               game numbers (colours, seeds) continue across workers via --offset
 *
 * Writes OUT/<id>.json and OUT/<id>.pcn per game and OUT/summary.json for the batch.
 * The Orrery planner runs exactly this command on each lane.
 */
require __DIR__ . '/../src/autoload.php';

use Parliament\AI\Players;
use Parliament\Engine\Game;
use Parliament\Engine\Recording;

$o = getopt('', ['white:', 'black:', 'games:', 'seed:', 'out:', 'fen:', 'no-elections', 'max-plies:', 'title:',
    'learn', 'quiet', 'no-book', 'opening-random:', 'job:', 'queen-eligible', 'moves:', 'workers:', 'offset:']);
$white = $o['white'] ?? 'medium';
$black = $o['black'] ?? 'medium';
$games = (int)($o['games'] ?? 1);
$seed = (int)($o['seed'] ?? 1);
$out = $o['out'] ?? pc_root() . '/data/recordings/selfplay-' . date('Ymd-His');
$fen = $o['fen'] ?? null;
$opt = ['elections' => !isset($o['no-elections']), 'queenEligible' => isset($o['queen-eligible'])];
$maxPlies = (int)($o['max-plies'] ?? 240);
$quiet = isset($o['quiet']);
$book = isset($o['no-book']) ? null : Players::book();
$randomPlies = (int)($o['opening-random'] ?? 0);

$workers = isset($o['workers']) ? (int)$o['workers'] : 1;
if ($workers === 0) {
    $workers = \Parliament\Engine\Workers::cores();
}
if ($workers > 1) {
    $t0 = microtime(true);
    // Every worker gets this command line, less the flags each sets for itself.
    $args = [];
    $skip = false;
    foreach (array_slice($argv, 1) as $a) {
        if ($skip) {
            $skip = false;
            continue;
        }
        if (in_array($a, ['--workers', '--out', '--offset'], true)) {
            $skip = true;
            continue;
        }
        if (preg_match('/^--(workers|out|offset)=/', $a)) {
            continue;
        }
        $args[] = $a;
    }
    $codes = \Parliament\Engine\Workers::run(__FILE__, $workers, fn(int $i) => array_merge($args, ['--offset', (string)($i * $games), '--out', "$out/w$i"]), "$out/logs");
    $all = [];
    $files = [];
    for ($i = 0; $i < $workers; $i++) {
        $w = json_decode((string)@file_get_contents("$out/w$i/summary.json"), true);
        if (!$w) {
            continue;
        }
        foreach ($w['files'] as $f) {
            rename("$out/w$i/$f", "$out/$f");
            @rename("$out/w$i/" . substr($f, 0, -5) . '.pcn', "$out/" . substr($f, 0, -5) . '.pcn');
            $files[] = $f;
        }
        $all = array_merge($all, $w['games']);
    }
    $summary = ['job' => $o['job'] ?? null, 'white' => $white, 'black' => $black, 'seed' => $seed, 'elections' => $opt['elections'],
        'fen' => $fen, 'workers' => $workers, 'failedWorkers' => count(array_filter($codes)), 'seconds' => round(microtime(true) - $t0, 1),
        'files' => $files, 'census' => Recording::census($all), 'games' => $all];
    file_put_contents($out . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT));
    echo json_encode($summary['census']) . "\n";
    exit($files ? 0 : 1);
}
$offset = (int)($o['offset'] ?? 0);
$stats = [];
$files = [];
$t0 = microtime(true);
for ($i = $offset; $i < $offset + $games; $i++) {
    $gs = $seed * 1000 + $i;
    $rng = new \Random\Randomizer(new \Random\Engine\Mt19937($gs));
    // Alternate colours each game so neither style keeps White.
    [$ws, $bs] = $i % 2 === 0 ? [$white, $black] : [$black, $white];
    $players = [1 => Players::make($ws, $book, $gs), -1 => Players::make($bs, $book, $gs + 7919)];
    $g = new Game($fen, $opt);
    foreach (preg_split('/\s+/', trim($o['moves'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $mv) {
        if ($g->status === 'playing') {
            $g->play($mv);
            $g->log[count($g->log) - 1]['source'] = 'script';
        }
    }
    for ($k = 0; $k < $randomPlies && $g->status === 'playing'; $k++) {
        $legal = $g->pos->legalMoves();
        $g->playInt($legal[$rng->getInt(0, count($legal) - 1)], null, ['source' => 'random']);
    }
    while ($g->status === 'playing' && count($g->log) < $maxPlies) {
        $ai = $players[$g->pos->side];
        $info = $ai->choose($g);
        if (!$info['move']) {
            break;
        }
        $g->playInt($info['move'], null, ['source' => $info['source'], 'score' => $info['score'] ?? 0,
            'nodes' => $info['nodes'] ?? 0, 'ms' => $info['ms'] ?? 0]);
        $last = end($g->log);
        if (!$quiet && $last['count']) {
            fwrite(STDERR, sprintf("  game %d ply %d: %s\n", $i + 1, $last['ply'], Game::describeCount($last['count'])));
        }
    }
    if ($g->status === 'playing') {
        $g->finish('draw', null, 'adjudicated: ply limit');
    }
    $id = sprintf('%s-g%02d-s%d', preg_replace('/[^a-z0-9]+/i', '-', $o['job'] ?? 'selfplay'), $i + 1, $gs);
    $rec = Recording::make($g, ['id' => $id, 'title' => $o['title'] ?? "$ws vs $bs", 'white' => $ws, 'black' => $bs,
        'seed' => $gs, 'job' => $o['job'] ?? null, 'elections' => $opt['elections']]);
    $files[] = basename(Recording::save($out, $rec));
    $stats[] = $rec['stats'];
    if ($book && isset($o['learn'])) {
        $book->learn($g);
    }
    if (!$quiet) {
        fprintf(STDERR, "game %d/%d %s (%s) %s vs %s, %d plies, %s writs, %.1fs\n", $i + 1, $offset + $games, $g->result(),
            $g->termination, $ws, $bs, count($g->log), json_encode($rec['stats']['writs']), microtime(true) - $t0);
    }
}
$summary = ['job' => $o['job'] ?? null, 'white' => $white, 'black' => $black, 'seed' => $seed, 'elections' => $opt['elections'],
    'fen' => $fen, 'seconds' => round(microtime(true) - $t0, 1), 'files' => $files,
    'census' => Recording::census($stats), 'games' => $stats];
file_put_contents($out . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT));
echo json_encode($summary['census']) . "\n";
