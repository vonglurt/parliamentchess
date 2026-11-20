<?php
declare(strict_types=1);

/*
 * The Parliament Chess planner for the Orrery Queue (orreryq).
 *
 *   php bin/orrery-plan.php DIR plan N     print up to N new jobs as a JSON list (the keeper queues them)
 *   php bin/orrery-plan.php DIR collect    gather each results/ID/summary.json into DIR/report.json and report.md
 *   php bin/orrery-plan.php DIR sync       nothing to gather beyond results/ (exit 0)
 *   php bin/orrery-plan.php DIR chores     nothing to tidy (exit 0)
 *
 * It follows tools/fleet-queue.py's contract: the queue knows nothing about what a
 * job is for; a job is {"id", "kind", "title", "needs", "cmd", "fetch", ...}; the
 * command runs on the host from $HOME, and "fetch" is copied back into results/ID/.
 *
 * DIR/plan.json says what to run: a list of runs, each a census of self-play
 * games (or a scripted demo) over a list of seeds — one job per seed.
 */

const REPO = __DIR__ . '/..';

$dir = rtrim($argv[1] ?? '', '/');
$verb = $argv[2] ?? 'plan';
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "usage: orrery-plan.php DIR plan N|collect|sync|chores\n");
    exit(2);
}

function slug(string $s): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
}

function known(string $dir): array
{
    $ids = [];
    foreach (['queue', 'done', 'failed', 'stopped'] as $sub) {
        foreach (glob("$dir/$sub/*.json") ?: [] as $f) {
            $ids[basename($f, '.json')] = $sub;
        }
    }
    foreach (glob("$dir/running/*/*.json") ?: [] as $f) {
        $ids[basename($f, '.json')] = 'running';
    }
    return $ids;
}

function jobFor(array $run, int $seed): array
{
    $id = slug($run['name']) . '-s' . $seed;
    $repo = realpath(REPO);
    $args = [
        '--job', $id, '--white', $run['white'] ?? 'medium', '--black', $run['black'] ?? 'medium',
        '--games', (string)($run['games'] ?? 4), '--seed', (string)$seed, '--title', $run['title'] ?? $run['name'],
        '--max-plies', (string)($run['maxPlies'] ?? 240), '--quiet',
    ];
    if (($run['elections'] ?? true) === false) {
        $args[] = '--no-elections';
    }
    if (!empty($run['queenEligible'])) {
        $args[] = '--queen-eligible';
    }
    if (!empty($run['fen'])) {
        array_push($args, '--fen', $run['fen']);
    }
    if (!empty($run['moves'])) {
        array_push($args, '--moves', $run['moves']);
    }
    if (!empty($run['openingRandom'])) {
        array_push($args, '--opening-random', (string)$run['openingRandom']);
    }
    $cmd = 'D="$HOME/parliament-chess"; [ -d "$D" ] || D=' . escapeshellarg($repo) . '; cd "$D" && sh bin/php bin/selfplay.php '
        . implode(' ', array_map('escapeshellarg', $args)) . ' --out "$HOME/pc-out/' . $id . '"';
    return [
        'id' => $id,
        'kind' => $run['kind'] ?? 'census',
        'title' => ($run['title'] ?? $run['name']) . " (seed $seed)",
        'needs' => $run['needs'] ?? 'cpu',
        'cmd' => $cmd,
        'fetch' => "pc-out/$id",
        'priority' => ($run['kind'] ?? '') === 'demo' ? 'high' : 'low',
        'timeout_s' => (int)($run['timeout'] ?? 3600),
        'run' => $run['name'],
        'project' => 'parliament-chess',
    ];
}

if ($verb === 'plan') {
    $n = (int)($argv[3] ?? 8);
    $plan = json_decode((string)@file_get_contents("$dir/plan.json"), true) ?: ['runs' => []];
    $have = known($dir);
    $out = [];
    foreach ($plan['runs'] as $run) {
        foreach ($run['seeds'] ?? [1] as $seed) {
            $job = jobFor($run, (int)$seed);
            if (!isset($have[$job['id']]) && count($out) < $n) {
                $out[] = $job;
            }
        }
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

if ($verb === 'collect') {
    require REPO . '/src/autoload.php';
    $byRun = [];
    $recordings = [];
    foreach (glob("$dir/done/*.json") ?: [] as $jf) {
        $job = json_decode((string)file_get_contents($jf), true);
        $res = "$dir/results/{$job['id']}";
        $sumFile = is_file("$res/summary.json") ? "$res/summary.json" : (glob("$res/*/summary.json")[0] ?? null);
        if (!$sumFile) {
            continue;
        }
        $sum = json_decode((string)file_get_contents($sumFile), true);
        $run = $job['run'] ?? preg_replace('/-s\d+$/', '', $job['id']);
        foreach ($sum['games'] as $i => $s) {
            $byRun[$run][] = $s;
            $recordings[] = ['run' => $run, 'job' => $job['id'], 'file' => dirname($sumFile) . '/' . $sum['files'][$i],
                'result' => $s['result'], 'plies' => $s['plies'], 'counts' => array_sum($s['outcomes']),
                'crownMoves' => $s['crownMoves']];
        }
    }
    $report = ['generated' => date('c'), 'runs' => []];
    foreach ($byRun as $run => $stats) {
        $report['runs'][$run] = \Parliament\Engine\Recording::census($stats);
    }
    $report['recordings'] = $recordings;
    file_put_contents("$dir/report.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $md = "| run | games | White score | decisive | plies | Writs/game | counts/game | crown moves/game | trailing side's score (n) |\n|---|---|---|---|---|---|---|---|---|\n";
    foreach ($report['runs'] as $run => $c) {
        $md .= sprintf("| %s | %d | %.3f | %.3f | %.1f | %.2f | %.2f | %.2f | %s (%d) |\n", $run, $c['games'], $c['whiteScore'],
            $c['decisive'], $c['avgPlies'], $c['writsPerGame'], $c['countsPerGame'], $c['crownMovesPerGame'],
            $c['trailingScore'] === null ? '-' : sprintf('%.3f', $c['trailingScore']), $c['trailingGames']);
    }
    file_put_contents("$dir/report.md", $md);
    echo $md;
    exit(0);
}

if ($verb === 'sync' || $verb === 'chores') {
    exit(0);
}
fwrite(STDERR, "unknown verb $verb\n");
exit(2);
