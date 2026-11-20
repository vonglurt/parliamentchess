<?php
declare(strict_types=1);

/*
 * Generation timing: what a search label costs at each depth, measured on real census positions.
 *   php bin/bench-depth.php [positions=120] [maxDepth=4]
 * Prints nodes and milliseconds per position at depths 1..maxDepth, the effective branching
 * factor (nodes(d) / nodes(d-1)) and labelled positions per lane-hour.
 */
require __DIR__ . '/../src/autoload.php';

use Parliament\AI\Evaluator;
use Parliament\AI\Search;
use Parliament\Engine\Game;

$n = (int)($argv[1] ?? 120);
$maxD = (int)($argv[2] ?? 4);
$files = glob(pc_root() . '/orrery/census/results/b-parliament-*/*.json');
sort($files);
$positions = [];
foreach ($files as $i => $f) {
    if (str_ends_with($f, 'summary.json') || $i % 7 !== 0) {
        continue;
    }
    $r = json_decode((string)file_get_contents($f), true);
    $g = new Game($r['initialFen'], $r['opt']);
    foreach ($r['log'] as $e) {
        $g->playInt($g->parse($e['uci']));
        if ($g->status !== 'playing') {
            break;
        }
        if (in_array($e['ply'], [24, 48, 72], true)) {
            $positions[] = clone $g;
        }
    }
    if (count($positions) >= $n) {
        break;
    }
}
$rows = [];
for ($d = 1; $d <= $maxD; $d++) {
    $nodes = $ms = 0;
    foreach ($positions as $g) {
        $s = new Search(new Evaluator(), null, $d, 0, 1);
        $s->maxNodes = PHP_INT_MAX;
        $t = microtime(true);
        $s->choose($g);
        $ms += (microtime(true) - $t) * 1000;
        $nodes += $s->nodes;
    }
    $rows[$d] = [$nodes / count($positions), $ms / count($positions)];
    $ebf = $d > 1 ? $rows[$d][0] / $rows[$d - 1][0] : 0;
    printf("depth %d: %8.0f nodes  %8.1f ms per position  EBF %.2f  -> %7.0f labels per lane-hour\n",
        $d, $rows[$d][0], $rows[$d][1], $ebf, 3600000 / max(0.001, $rows[$d][1]));
}
echo count($positions) . " positions\n";
