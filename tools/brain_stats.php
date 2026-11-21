<?php
declare(strict_types=1);
/*
 * Measurements for Lab Report XII: what the brain panel shows across real games. The champion net
 * (Medium search, net evaluation and policy ordering) plays Medium Populist, colours alternating, no
 * book; at every one of the net's moves the trace is taken exactly as the server takes it.
 *
 *   sh bin/php tools/brain_stats.php [--games 8] [--net champion] [--seed 1] [--json out.json]
 */
require __DIR__ . '/../src/autoload.php';

use Parliament\AI\Players;
use Parliament\Engine\Game;
use Parliament\Engine\Position;

$o = getopt('', ['games:', 'net:', 'seed:', 'json:']);
$games = (int)($o['games'] ?? 8);
$net = $o['net'] ?? 'champion';
$seed = (int)($o['seed'] ?? 1);

$rows = [];
$results = [];
for ($i = 0; $i < $games; $i++) {
    $netSide = $i % 2 ? -1 : 1;
    $players = [$netSide => Players::make("medium+net2:$net", null, $seed * 1000 + $i),
        -$netSide => Players::make('medium/populist', null, $seed * 1000 + 500 + $i)];
    $g = new Game();
    // Two random plies first, so the eight games are eight different games.
    $rng = new \Random\Randomizer(new \Random\Engine\Mt19937($seed * 1000 + $i));
    for ($k = 0; $k < 2; $k++) {
        $legal = $g->pos->legalMoves();
        $g->playInt($legal[$rng->getInt(0, count($legal) - 1)]);
    }
    while ($g->status === 'playing' && count($g->log) < 160) {
        $p = $g->pos;
        $ai = $players[$p->side];
        $info = $ai->choose($g);
        if (!$info['move']) {
            break;
        }
        if ($p->side === $netSide) {
            $t0 = microtime(true);
            $t = $ai->ev->net2->trace($p, $p->legalMoves(), $info['move']);
            $ms = (microtime(true) - $t0) * 1000;
            $ranks = array_column($t['policy'], 'm');
            $best = array_key_first($ai->rootScores);
            $rows[] = [
                'feats' => count($t['features']),
                'h1' => count(array_filter($t['a1'], fn($v) => $v > 0)),
                'h1sat' => count(array_filter($t['a1'], fn($v) => $v >= 1.0)),
                'h2' => count(array_filter($t['a2'], fn($v) => $v > 0)),
                'moves' => count($ranks),
                'top1p' => $t['policy'][0]['p'],
                'rank' => array_search($info['move'], $ranks, true) + 1,
                'policyIsSearchBest' => $ranks[0] === $best,
                'corr' => 400 * max(-2.0, min(2.0, $t['raw'])),
                'traceMs' => $ms, 'searchMs' => $info['ms'] ?? 0,
                'bytes' => strlen(json_encode($t)),
                'writ' => (($info['move'] >> 16) & Position::F_WRIT) !== 0,
                'canFile' => in_array(1181, $t['features'], true),
            ];
        }
        $g->playInt($info['move']);
    }
    $results[] = ['net' => $netSide === 1 ? 'White' : 'Black', 'result' => $g->result(), 'plies' => count($g->log)];
    fwrite(STDERR, sprintf("game %d: net as %s, %s in %d plies, %d net moves so far\n", $i + 1, $netSide === 1 ? 'White' : 'Black', $g->result(), count($g->log), count($rows)));
}

$col = fn(string $k) => array_map(fn($r) => (float)$r[$k], $rows);
$stat = function (array $v): array {
    sort($v);
    $n = count($v);
    return ['mean' => round(array_sum($v) / $n, 3), 'median' => round($v[intdiv($n, 2)], 3), 'min' => round($v[0], 3), 'max' => round($v[$n - 1], 3),
        'p90' => round($v[(int)floor(0.9 * ($n - 1))], 3)];
};
$ranks = $col('rank');
$out = [
    'net' => basename(Players::netPath($net), '.json'), 'games' => $games, 'netMoves' => count($rows), 'results' => $results,
    'activeInputs' => $stat($col('feats')), 'layer1Fired' => $stat($col('h1')), 'layer1Saturated' => $stat($col('h1sat')),
    'layer2Fired' => $stat($col('h2')), 'legalMoves' => $stat($col('moves')), 'policyTop1P' => $stat($col('top1p')),
    'playedRank' => $stat($ranks),
    'playedIsPolicyTop1' => round(count(array_filter($ranks, fn($r) => $r == 1)) / count($ranks), 3),
    'playedInPolicyTop3' => round(count(array_filter($ranks, fn($r) => $r <= 3)) / count($ranks), 3),
    'policyTop1IsSearchBest' => round(count(array_filter($rows, fn($r) => $r['policyIsSearchBest'])) / count($rows), 3),
    'absCorrectionCp' => $stat(array_map('abs', $col('corr'))),
    'traceMs' => $stat($col('traceMs')), 'searchMs' => $stat($col('searchMs')), 'traceBytes' => $stat($col('bytes')),
    'writsFiled' => count(array_filter($rows, fn($r) => $r['writ'])),
    'movesWhereAWritWasLegal' => count(array_filter($rows, fn($r) => $r['canFile'])),
];
$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (isset($o['json'])) {
    file_put_contents($o['json'], $json . "\n");
}
echo $json, "\n";
