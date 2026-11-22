<?php
declare(strict_types=1);
/*
 * The classics through the net's eyes: every position of six famous short games (src/Server/Classics.php)
 * is read by a net exactly as the server reads a person's move (Net2::trace at the position the mover
 * faced, the historic move marked), and three players are asked what they would have played there:
 *
 *   net     medium+net2:NET   the lobby's "Play the neural net": 2 plies, the net's evaluation and policy
 *   deep    hard+net2:NET     the same net, one ply deeper
 *   plain   medium            the hand-written evaluation alone, 2 plies (no net)
 *
 * No book: every answer is the search's. Seeds are fixed, so the file is reproducible.
 *
 *   sh bin/php tools/classics.php [--net g015] [--out docs/data/classics.json] [--only opera]
 */
require __DIR__ . '/../src/autoload.php';

use Parliament\AI\Evaluator;
use Parliament\AI\Players;
use Parliament\Engine\Game;
use Parliament\Engine\Position;
use Parliament\Server\Classics;

$o = getopt('', ['net:', 'out:', 'only:']);
$netName = $o['net'] ?? 'g015';
$out = $o['out'] ?? pc_root() . '/docs/data/classics.json';
$only = isset($o['only']) ? explode(',', $o['only']) : null;

$specs = ['net' => "medium+net2:$netName", 'deep' => "hard+net2:$netName", 'plain' => 'medium'];
$r3 = fn(array $a) => array_map(fn($v) => round((float)$v, 3), $a);
$games = [];
$t00 = microtime(true);
foreach (Classics::GAMES as $key => $_) {
    if ($only && !in_array($key, $only, true)) {
        continue;
    }
    $c = Classics::get($key);
    $g = new Game();
    $plies = [];
    foreach ($c['moves'] as $n => $text) {
        $p = $g->pos;
        $m = $g->parse($text);
        if ($m === null) {
            fwrite(STDERR, "$key: illegal move $text at ply " . ($n + 1) . "\n");
            exit(1);
        }
        $moves = $p->legalMoves();
        $row = ['ply' => $n + 1, 'side' => $p->side, 'fen' => $p->toFen(), 'uci' => Game::uci($m), 'legal' => count($moves)];
        // What each player would play here (fixed seeds: the same answers every run).
        $picks = [];
        $searches = [];
        foreach ($specs as $who => $spec) {
            $ai = Players::make($spec, null, 1000 * crc32($key) % 100000 + $n);
            $info = $ai->choose($g);
            $searches[$who] = $ai;
            $picks[$who] = ['san' => Game::san($p, $info['move']), 'uci' => Game::uci($info['move']), 'score' => $info['score'],
                'mate' => abs($info['best'] ?? $info['score']) > 5000, 'ms' => $info['ms'], 'nodes' => $info['nodes'],
                'agree' => $info['move'] === $m, 'histScore' => $ai->rootScores[$m] ?? null];
        }
        // The reading: the net's trace at this position, scored by the lobby net's own search.
        $net = $searches['net'];
        $t = $net->ev->net2->trace($p, $moves, $m, 48, 150, 6);
        $ranked = [];
        foreach ($t['policy'] as $rank => $pr) {
            $ranked[] = ['san' => Game::san($p, $pr['m']), 'uci' => Game::uci($pr['m']), 'p' => $pr['p'], 'logit' => $pr['logit'],
                'search' => $net->rootScores[$pr['m']] ?? null, 'writ' => (($pr['m'] >> 16) & Position::F_WRIT) !== 0]
                + (isset($pr['edges']) ? ['edges' => array_map(fn($e) => [$e[0], round($e[1], 3)], $pr['edges'])] : []);
        }
        $histRank = array_search(Game::uci($m), array_column($ranked, 'uci'), true);
        $row += [
            'rank' => $histRank + 1, 'p' => $ranked[$histRank]['p'],
            'dist' => array_column($ranked, 'p'), // every legal move's probability, best first
            'value' => $t['value'], 'raw' => $t['raw'],
            'hand' => (new Evaluator($net->ev->style))->evaluate($p), 'total' => $net->ev->evaluate($p),
            'picks' => $picks,
            // The trace, trimmed for drawing: a1/a2 are pre1/pre2 clipped to [0, 1], so they are left out.
            'trace' => ['features' => $t['features'], 'pre1' => $r3($t['pre1']), 'pre2' => $r3($t['pre2']),
                'in' => array_map(fn($e) => [$e[0], $e[1], round($e[2], 3)], $t['edges']['in']),
                'mid' => array_map(fn($e) => [$e[0], $e[1], round($e[2], 3)], $t['edges']['mid']),
                'val' => array_map(fn($e) => [$e[0], round($e[1], 3)], $t['edges']['value']),
                'top' => array_values(array_filter($ranked, fn($r, $i) => $i < 6 || $i === $histRank, ARRAY_FILTER_USE_BOTH))],
        ];
        $g->playInt($m);
        $row['san'] = $g->log[count($g->log) - 1]['san'];
        $plies[] = $row;
        fwrite(STDERR, sprintf("%-8s %2d %-7s net #%-2d %5.1f%%  picks: net %-7s deep %-7s plain %-7s\n", $key, $n + 1, $row['san'], $row['rank'],
            100 * $row['p'], $picks['net']['san'], $picks['deep']['san'], $picks['plain']['san']));
    }
    $games[] = array_diff_key($c, ['moves' => 1]) + ['result' => $g->result(), 'termination' => $g->termination,
        'winner' => $g->winner, 'plies' => $plies];
}

// The summary: for each game, how often each player matched history, split by the winner's and the
// loser's moves; and the moments that decide these games, the winner's mates and sacrifices.
$sum = [];
foreach ($games as $gm) {
    $w = $gm['winner'];
    $row = ['key' => $gm['key'], 'plies' => count($gm['plies'])];
    foreach (['winner' => fn($pl) => $pl['side'] === $w, 'loser' => fn($pl) => $pl['side'] !== $w] as $part => $f) {
        $set = array_values(array_filter($gm['plies'], $f));
        $row[$part] = ['moves' => count($set), 'netTop1' => count(array_filter($set, fn($pl) => $pl['rank'] === 1)),
            'netTop3' => count(array_filter($set, fn($pl) => $pl['rank'] <= 3)),
            'meanRank' => round(array_sum(array_column($set, 'rank')) / max(1, count($set)), 2)];
        foreach (array_keys($specs) as $who) {
            $row[$part][$who] = count(array_filter($set, fn($pl) => $pl['picks'][$who]['agree']));
        }
    }
    $last = end($gm['plies']);
    $row['finalMate'] = ['san' => $last['san'], 'rank' => $last['rank'], 'p' => $last['p']]
        + array_map(fn($pk) => $pk['agree'], $last['picks']);
    $sum[] = $row;
}
$doc = ['net' => $netName, 'players' => $specs, 'made' => gmdate('c'), 'seconds' => round(microtime(true) - $t00, 1),
    'summary' => $sum, 'games' => $games];
file_put_contents($out, json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
fwrite(STDERR, sprintf("%s: %d games, %d plies, %.1f s\n", $out, count($games), array_sum(array_map(fn($g) => count($g['plies']), $games)), microtime(true) - $t00));
echo json_encode($sum, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
