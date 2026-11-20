<?php
declare(strict_types=1);

/*
 * Does the policy head cut the branching factor? Same positions, same depth, same evaluation:
 * moves ordered by the hand-written rule, then by the trained net's policy.
 *
 *   php bin/bench-policy.php NET2.json [positions=60] [depth=3]
 */
require __DIR__ . '/../src/autoload.php';

use Parliament\AI\Evaluator;
use Parliament\AI\Net2;
use Parliament\AI\Search;
use Parliament\Engine\Game;

$net = Net2::load($argv[1] ?? pc_root() . '/data/models/net2.json');
$n = (int)($argv[2] ?? 60);
$maxD = (int)($argv[3] ?? 3);
$files = glob(pc_root() . '/orrery/census/results/b-parliament-*/*.json');
sort($files);
$positions = [];
foreach ($files as $i => $f) {
    if (str_ends_with($f, 'summary.json') || $i % 7 !== 3) {
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
foreach ([2, $maxD] as $d) {
    $res = [];
    foreach (['hand order' => false, 'policy order' => true] as $label => $usePolicy) {
        $nodes = $ms = $same = 0;
        foreach ($positions as $k => $g) {
            $ev = new Evaluator();
            if ($usePolicy) {
                $ev->net2 = $net;
                $ev->net2Eval = false;
            }
            $s = new Search($ev, null, $d, 0, 1);
            $s->maxNodes = PHP_INT_MAX;
            $t = microtime(true);
            $info = $s->choose($g);
            $ms += (microtime(true) - $t) * 1000;
            $nodes += $s->nodes;
            $res[$label][$k] = $info['move'];
        }
        printf("depth %d, %-12s %8.0f nodes %7.1f ms per position\n", $d, $label, $nodes / count($positions), $ms / count($positions));
    }
    $agree = count(array_filter(array_keys($res['hand order']), fn($k) => $res['hand order'][$k] === $res['policy order'][$k]));
    printf("depth %d: both orders chose the same move in %d of %d positions (alpha-beta returns the same value; ties may differ)\n", $d, $agree, count($positions));
}
