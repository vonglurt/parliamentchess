<?php
declare(strict_types=1);
/*
 * An election to draw: the census Writ that shows every kind of voter at once (Candidate and
 * King backers in both chambers, an abstainer, the Speaker), a Contested Coronation by White.
 * Prints the position and the engine's own poll as JSON for tools/fig_election.py.
 *
 *   php tools/election_example.php > /tmp/election.json
 */
require __DIR__ . '/../src/autoload.php';
use Parliament\Engine\Position;
$best = null;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../orrery/census/results', FilesystemIterator::SKIP_DOTS)) as $f) {
    if (!str_ends_with((string)$f, '.json') || basename((string)$f) === 'summary.json') continue;
    $r = json_decode(file_get_contents((string)$f), true);
    if (empty($r['opt']['elections'])) continue;
    foreach ($r['log'] as $i => $e) {
        if (empty($e['writ'])) continue;
        $c = $e['by']; $p = Position::fromFen($e['fen']);
        $poll = $p->poll($c, $e['to']);
        $v = array_count_values($poll['voters']);
        $pieces = 0; foreach ($poll['voters'] as $sq => $x) if (abs($p->b[$sq]) !== 1) $pieces++;
        if (($v['C'] ?? 0) >= 4 && ($v['I'] ?? 0) >= 3 && ($v['X'] ?? 0) >= 1 && $poll['speaker'] !== null && $pieces >= 4
            && $poll['outcome'] === 'contested' && $c === 1) {
            $score = abs(($v['C'] ?? 0) - ($v['I'] ?? 0)) + abs($pieces - 6);
            if (!$best || $score < $best['s']) $best = ['s' => $score, 'file' => basename((string)$f), 'ply' => $e['ply'], 'fen' => $e['fen'], 'c' => $c,
                'k' => $p->ksq[$c], 'cand' => $e['to'], 'b' => $p->b, 'poll' => $poll];
        }
    }
}
echo json_encode($best);
