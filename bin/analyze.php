<?php
declare(strict_types=1);

/*
 * Balance analysis of an Orrery census (Lab Reports III and VI).
 *
 *   php bin/analyze.php [orrery/census] [--out docs/data/census-analysis.json]
 *
 * Per run: games, the score of the plan's first style (colours alternate inside each job),
 * the trailing side's score with a 95% interval, decisive share and length; per count:
 * outcome by Candidate type and when Writs are filed; and "crown reach" — how many squares
 * the crown could reach within one move plus a Writ, with and without the Queen's bar.
 */
require __DIR__ . '/../src/autoload.php';

use Parliament\Engine\Game;
use Parliament\Engine\Position;

$args = array_slice($argv, 1);
$out = pc_root() . '/docs/data/census-analysis.json';
if (($k = array_search('--out', $args, true)) !== false) {
    $out = $args[$k + 1];
    array_splice($args, $k, 2);
}
$dir = rtrim($args[0] ?? pc_root() . '/orrery/census', '/');

function ci(array $xs): array
{
    $n = count($xs);
    if (!$n) {
        return ['n' => 0, 'mean' => null, 'lo' => null, 'hi' => null];
    }
    $m = array_sum($xs) / $n;
    $var = $n > 1 ? array_sum(array_map(fn($x) => ($x - $m) ** 2, $xs)) / ($n - 1) : 0.0;
    $se = sqrt($var / $n);
    return ['n' => $n, 'mean' => round($m, 3), 'lo' => round($m - 1.96 * $se, 3), 'hi' => round($m + 1.96 * $se, 3), 'se' => round($se, 4)];
}

$plan = json_decode((string)file_get_contents("$dir/plan.json"), true);
$styles = [];
foreach ($plan['runs'] as $r) {
    $styles[$r['name']] = [$r['white'] ?? 'medium', $r['black'] ?? 'medium'];
}
$runs = [];
$byType = [];
$writPly = [];
$reach = ['barred' => [], 'regency' => []];
$sampleN = 0;
foreach (glob("$dir/done/*.json") as $jf) {
    $job = json_decode((string)file_get_contents($jf), true);
    $run = $job['run'];
    $sumf = "$dir/results/{$job['id']}/summary.json";
    if (!is_file($sumf)) {
        continue;
    }
    $sum = json_decode((string)file_get_contents($sumf), true);
    foreach ($sum['files'] as $i => $file) {
        $rec = json_decode((string)file_get_contents("$dir/results/{$job['id']}/$file"), true);
        $s = $sum['games'][$i];
        $first = $styles[$run][0];
        $firstColor = $rec['white'] === $first ? 1 : -1;
        $res = $rec['winner'] === null ? 0.5 : ($rec['winner'] === $firstColor ? 1.0 : 0.0);
        $runs[$run]['styleScore'][] = $res;
        $runs[$run]['whiteScore'][] = $rec['winner'] === null ? 0.5 : ($rec['winner'] === 1 ? 1.0 : 0.0);
        $runs[$run]['decisive'][] = $rec['winner'] === null ? 0 : 1;
        $runs[$run]['plies'][] = $s['plies'];
        $runs[$run]['counts'][] = array_sum($s['outcomes']);
        if ($s['trailingScore'] !== null) {
            $runs[$run]['trailing'][] = $s['trailingScore'];
        }
        foreach ($rec['log'] as $e) {
            if ($e['writ']) {
                $writPly[$run][] = $e['ply'];
            }
            if ($e['count']) {
                $vs = $e['count']['voters'] ?? [];
                if ($vs) {
                    $runs[$run]['abstainShare'][] = count(array_filter($vs, fn($v) => $v === 'X')) / count($vs);
                }
                $runs[$run]['failedCount'][] = $e['count']['outcome'] === 'failed' ? 1 : 0;
                $runs[$run]['crownMoved'][] = in_array($e['count']['outcome'], ['coronation', 'contested', 'swap'], true) ? 1 : 0;
                $t = $e['count']['candType'] ?? 0;
                $byType[$run][$t][$e['count']['outcome']] = ($byType[$run][$t][$e['count']['outcome']] ?? 0) + 1;
            }
        }
        // Crown reach on run B positions: plies 30, 50, 70 of every 4th game.
        if (str_starts_with($run, 'B') && $i === 0 && $sampleN < 120) {
            $g = new Game($rec['initialFen'], $rec['opt']);
            foreach ($rec['log'] as $e) {
                $g->playInt($g->parse($e['uci']));
                if (in_array($e['ply'], [30, 50, 70], true) && $g->status === 'playing') {
                    foreach (['barred' => false, 'regency' => true] as $k => $q) {
                        $reach[$k][] = crownReach($g->pos, $q);
                    }
                    $sampleN++;
                }
                if ($g->status !== 'playing' || $e['ply'] > 70) {
                    break;
                }
            }
        }
    }
}

/** Squares where the side to move's crown could stand after one ordinary move and one Writ. */
function crownReach(Position $p, bool $queenEligible): int
{
    $q = clone $p;
    $q->opt['queenEligible'] = $queenEligible;
    $q->full = max($q->full, Position::PEACE_UNTIL);
    $squares = [];
    foreach ($q->candidates($q->side) as $sq) {
        $squares[$sq] = true;
    }
    foreach ($q->legalMoves() as $m) {
        if (($m >> 16) & Position::F_WRIT) {
            continue;
        }
        $t = abs($q->b[$m & 63]);
        if ($t === Position::K || ($t === Position::Q && !$queenEligible)) {
            continue;
        }
        $id = $q->id[$m & 63];
        if (($q->lf[$id] ?? 0) & Position::L_REIGNED) {
            continue;
        }
        $squares[($m >> 6) & 63] = true;
    }
    return count($squares);
}

$report = ['generated' => date('c'), 'runs' => []];
foreach ($runs as $run => $r) {
    $report['runs'][$run] = [
        'styles' => $styles[$run],
        'games' => count($r['styleScore']),
        'firstStyleScore' => ci($r['styleScore']),
        'whiteScore' => ci($r['whiteScore']),
        'decisive' => ci($r['decisive']),
        'plies' => ci($r['plies']),
        'countsPerGame' => ci($r['counts']),
        'trailingScore' => ci($r['trailing'] ?? []),
        'abstainShare' => ci($r['abstainShare'] ?? []),
        'failedShare' => ci($r['failedCount'] ?? []),
        'crownMovedShare' => ci($r['crownMoved'] ?? []),
        'firstWritPlyMedian' => isset($writPly[$run]) ? (function ($a) { sort($a); return $a[intdiv(count($a), 2)]; })($writPly[$run]) : null,
        'outcomesByCandidate' => $byType[$run] ?? [],
    ];
}
$report['crownReach'] = ['samples' => $sampleN, 'barred' => ci($reach['barred']), 'regency' => ci($reach['regency'])];
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0775, true);
}
file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT));

printf("%-26s %5s %-28s %-22s %-22s %-14s %s\n", 'run', 'games', 'first style score [95% CI]', 'trailing score (n)', 'decisive', 'plies', 'counts/game');
foreach ($report['runs'] as $run => $r) {
    $f = $r['firstStyleScore'];
    $t = $r['trailingScore'];
    printf("%-26s %5d %.3f [%.3f, %.3f] (%s)  %s  %.3f  %6.1f  %.2f\n", $run, $r['games'], $f['mean'], $f['lo'], $f['hi'], $r['styles'][0],
        $t['n'] ? sprintf('%.3f [%.3f, %.3f] n=%d', $t['mean'], $t['lo'], $t['hi'], $t['n']) : '-', $r['decisive']['mean'], $r['plies']['mean'], $r['countsPerGame']['mean']);
}
$cr = $report['crownReach'];
printf("crown reach over %d positions: Queen barred %.1f squares, Regency %.1f squares\n", $cr['samples'], $cr['barred']['mean'], $cr['regency']['mean']);
echo "outcomes by Candidate type (1 P, 2 N, 3 B, 4 R, 5 Q):\n";
foreach ($report['runs'] as $run => $r) {
    echo "  $run: " . json_encode($r['outcomesByCandidate']) . " first Writ median ply " . ($r['firstWritPlyMedian'] ?? '-')
        . sprintf(" | crown moved in %.0f%% of counts, failed %.0f%%, voters intimidated %.0f%%", 100 * ($r['crownMovedShare']['mean'] ?? 0),
            100 * ($r['failedShare']['mean'] ?? 0), 100 * ($r['abstainShare']['mean'] ?? 0)) . "\n";
}
echo "written $out\n";
