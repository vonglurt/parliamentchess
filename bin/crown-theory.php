<?php
declare(strict_types=1);

/*
 * Parliament Chess — a theory of the crown move, measured.
 *
 * Castling theory came from practice: players learned when to tuck the King away by seeing what
 * happened to Kings that did and did not. This script does the same for the crown move (a Writ
 * and its count) over recorded self-play games. For every Writ it records the position at the
 * moment of filing and what followed:
 *
 *   phase       the full move number when it was filed
 *   score       the filer's own search score at that moment (cp, the filer's point of view)
 *   danger      squares around the filer's King (and the King's own) the enemy attacks
 *   distance    king-steps from the King to the Candidate
 *   poll        the count as it would stand with no campaign move (Position::poll)
 *   outcome     the actual count, after the opponent's one campaign move
 *   result      the filer's game score: 1 win, 0.5 draw, 0 loss
 *
 *   php bin/crown-theory.php [--json docs/data/crown-theory.json] DIR...     (default orrery/census/results)
 */

require __DIR__ . '/../src/autoload.php';

use Parliament\Engine\Position;

$o = getopt('', ['json:']);
$dirs = array_values(array_filter(array_slice($argv, 1), fn($a) => !str_starts_with($a, '--') && is_dir($a)));
if (!$dirs) {
    $dirs = [__DIR__ . '/../orrery/census/results'];
}
$files = [];
foreach ($dirs as $d) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (str_ends_with((string)$f, '.json') && basename((string)$f) !== 'summary.json') {
            $files[] = (string)$f;
        }
    }
}
sort($files);

$rows = [];
$games = $electionGames = 0;
foreach ($files as $f) {
    $r = json_decode((string)file_get_contents($f), true);
    if (($r['format'] ?? '') !== 'parliament-chess-recording' || empty($r['opt']['elections'])) {
        continue;
    }
    $games++;
    $log = $r['log'];
    $any = false;
    foreach ($log as $i => $e) {
        if (empty($e['writ'])) {
            continue;
        }
        $any = true;
        $c = $e['by'];
        $cand = $e['to'];
        $pos = Position::fromFen($e['fen']);
        $k = $pos->ksq[$c];
        $danger = 0;
        foreach ([-9, -8, -7, -1, 0, 1, 7, 8, 9] as $dd) {
            $sq = $k + $dd;
            if ($sq < 0 || $sq > 63 || abs(($sq & 7) - ($k & 7)) > 1) {
                continue;
            }
            $danger += Position::attacked($pos->b, $sq, -$c) ? 1 : 0;
        }
        $poll = $pos->poll($c, $cand)['outcome'];
        // The count is logged on the first later entry that carries this colour's count.
        $outcome = null;
        for ($j = $i + 1; $j < min(count($log), $i + 3); $j++) {
            if (($log[$j]['count']['color'] ?? 0) === $c) {
                $outcome = $log[$j]['count']['outcome'];
                break;
            }
        }
        $rows[] = [
            'file' => basename($f, '.json'),
            'full' => intdiv($e['ply'] + 1, 2),
            'score' => (int)($e['score'] ?? 0),
            'danger' => $danger,
            'inCheckSoon' => $danger >= 3,
            'distance' => max(abs(($k & 7) - ($cand & 7)), abs(($k >> 3) - ($cand >> 3))),
            'candType' => abs($pos->b[$cand]),
            'poll' => $poll,
            'outcome' => $outcome ?? 'game over',
            'result' => $r['winner'] === null ? 0.5 : ($r['winner'] === $c ? 1.0 : 0.0),
        ];
    }
    $electionGames += $any ? 1 : 0;
}

$crowned = fn(?string $o) => in_array($o, ['coronation', 'contested', 'swap'], true);
$moved = fn(array $x) => $crowned($x['outcome']);

/** Mean with a 95% normal interval. */
function ci(array $xs): array
{
    $n = count($xs);
    if ($n === 0) {
        return ['n' => 0, 'mean' => null, 'lo' => null, 'hi' => null];
    }
    $m = array_sum($xs) / $n;
    $sd = $n > 1 ? sqrt(array_sum(array_map(fn($x) => ($x - $m) ** 2, $xs)) / ($n - 1)) : 0.0;
    $h = 1.96 * $sd / sqrt($n);
    return ['n' => $n, 'mean' => round($m, 3), 'lo' => round($m - $h, 3), 'hi' => round($m + $h, 3)];
}

function group(array $rows, callable $key, callable $val): array
{
    $g = [];
    foreach ($rows as $r) {
        $g[$key($r)][] = $val($r);
    }
    ksort($g);
    return array_map('ci', $g);
}

$band = fn(array $x) => $x['score'] <= -150 ? '1 behind (<= -150)' : ($x['score'] >= 150 ? '3 ahead (>= +150)' : '2 level');
$phase = fn(array $x) => $x['full'] < 20 ? '1 moves 10-19' : ($x['full'] < 30 ? '2 moves 20-29' : ($x['full'] < 45 ? '3 moves 30-44' : '4 move 45+'));
$dangerB = fn(array $x) => $x['danger'] === 0 ? '0 none' : ($x['danger'] <= 2 ? '1 one or two' : '2 three or more');
$dist = fn(array $x) => $x['distance'] <= 2 ? '1 near (1-2)' : ($x['distance'] <= 4 ? '2 middle (3-4)' : '3 far (5-7)');

$out = [
    'games' => $games, 'gamesWithWrits' => $electionGames, 'writs' => count($rows),
    'outcomes' => array_count_values(array_column($rows, 'outcome')),
    'pollThenCount' => (function () use ($rows, $crowned) {
        $t = ['held' => 0, 'lost in campaign' => 0, 'won in campaign' => 0, 'lost either way' => 0];
        foreach ($rows as $r) {
            $p = $crowned($r['poll']);
            $a = $crowned($r['outcome']);
            $t[$p && $a ? 'held' : ($p ? 'lost in campaign' : ($a ? 'won in campaign' : 'lost either way'))]++;
        }
        return $t;
    })(),
    'crownedRateByPhase' => group($rows, $phase, fn($x) => $moved($x) ? 1 : 0),
    'crownedRateByDanger' => group($rows, $dangerB, fn($x) => $moved($x) ? 1 : 0),
    'crownedRateByDistance' => group($rows, $dist, fn($x) => $moved($x) ? 1 : 0),
    'crownedRateByPollWon' => group($rows, fn($x) => $crowned($x['poll']) ? 'poll already won' : 'poll not won', fn($x) => $moved($x) ? 1 : 0),
    'resultByBandCrowned' => group($rows, fn($x) => $band($x) . ' / ' . ($moved($x) ? 'crown moved' : 'crown stayed'), fn($x) => $x['result']),
    'resultByDangerCrowned' => group($rows, fn($x) => $dangerB($x) . ' / ' . ($moved($x) ? 'crown moved' : 'crown stayed'), fn($x) => $x['result']),
    'resultByPhaseCrowned' => group($rows, fn($x) => $phase($x) . ' / ' . ($moved($x) ? 'crown moved' : 'crown stayed'), fn($x) => $x['result']),
    'scoreAtWrit' => (function () use ($rows) {
        $s = array_column($rows, 'score');
        sort($s);
        $q = fn(float $p) => $s ? $s[(int)floor($p * (count($s) - 1))] : null;
        return ['median' => $q(0.5), 'q1' => $q(0.25), 'q3' => $q(0.75)]; // a median: mate scores skew a mean
    })(),
];

$show = function (string $title, array $g, string $what) {
    printf("\n%s\n", $title);
    foreach ($g as $k => $c) {
        printf("  %-44s n=%4d  %s %.3f  [%.3f, %.3f]\n", $k, $c['n'], $what, $c['mean'], $c['lo'], $c['hi']);
    }
};
printf("%d games with elections on, %d with at least one Writ, %d Writs\n", $games, $electionGames, count($rows));
echo 'outcomes: ', json_encode($out['outcomes']), "\n";
echo 'poll at filing -> count after the campaign: ', json_encode($out['pollThenCount']), "\n";
printf("filer's score at the Writ: median %d cp, quartiles %d and %d\n", $out['scoreAtWrit']['median'], $out['scoreAtWrit']['q1'], $out['scoreAtWrit']['q3']);
$show('Crown moved, by phase', $out['crownedRateByPhase'], 'rate');
$show('Crown moved, by danger to the King', $out['crownedRateByDanger'], 'rate');
$show('Crown moved, by King-to-Candidate distance', $out['crownedRateByDistance'], 'rate');
$show('Crown moved, by the poll at filing', $out['crownedRateByPollWon'], 'rate');
$show("Filer's game score, by standing and whether the crown moved", $out['resultByBandCrowned'], 'score');
$show("Filer's game score, by danger and whether the crown moved", $out['resultByDangerCrowned'], 'score');
$show("Filer's game score, by phase and whether the crown moved", $out['resultByPhaseCrowned'], 'score');

if (isset($o['json'])) {
    file_put_contents($o['json'], json_encode($out + ['rows' => $rows], JSON_PRETTY_PRINT));
    echo "\nwrote {$o['json']}\n";
}
