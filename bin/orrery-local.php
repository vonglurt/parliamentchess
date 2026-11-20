<?php
declare(strict_types=1);

/*
 * A one-machine keeper for an Orrery Queue directory, for when the fleet is not in the room.
 *
 *   php bin/orrery-local.php DIR [--lanes 8]
 *
 * It keeps the same files tools/fleet-queue.py keeps, so the console reads a local
 * run like a fleet run: queue/, running/HOST/, done/, failed/, logs/ID.log, results/ID/,
 * lanes/HOST.json and events.jsonl. A job is claimed by renaming its file. When the
 * queue runs low it asks the planner (config.json "planner") for more; when the planner
 * has nothing left and every lane is idle, it runs "collect" and ends.
 */

$dir = rtrim($argv[1] ?? '', '/');
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "usage: orrery-local.php DIR [--lanes N]\n");
    exit(2);
}
$dir = realpath($dir);
$lanes = 8;
if (($i = array_search('--lanes', $argv, true)) !== false) {
    $lanes = max(1, (int)$argv[$i + 1]);
}
$host = 'local';
foreach (['queue', "running/$host", 'done', 'failed', 'stopped', 'logs', 'results', 'lanes'] as $sub) {
    @mkdir("$dir/$sub", 0775, true);
}
$cfg = json_decode((string)@file_get_contents("$dir/config.json"), true) ?: [];
$planner = str_replace('DIR', escapeshellarg($dir), $cfg['planner'] ?? ('php ' . escapeshellarg(__DIR__ . '/orrery-plan.php') . ' DIR'));
$lowWater = (int)($cfg['low_water'] ?? 6);

function event(string $dir, string $what, array $kw = []): void
{
    file_put_contents("$dir/events.jsonl", json_encode(['t' => date('Y-m-d\TH:i:s'), 'event' => $what] + $kw) . "\n", FILE_APPEND | LOCK_EX);
}

function topUp(string $dir, string $planner, int $n): int
{
    $json = shell_exec("$planner plan $n");
    $jobs = json_decode((string)$json, true) ?: [];
    foreach ($jobs as $job) {
        $f = "$dir/queue/{$job['id']}.json";
        if (!file_exists($f)) {
            file_put_contents($f, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            event($dir, 'queued', ['id' => $job['id']]);
        }
    }
    return count($jobs);
}

function nextJob(string $dir): ?string
{
    $files = glob("$dir/queue/*.json") ?: [];
    $done = array_flip(array_map(fn($f) => basename($f, '.json'), glob("$dir/done/*.json") ?: []));
    usort($files, function ($a, $b) {
        $pa = (json_decode((string)file_get_contents($a), true)['priority'] ?? '') === 'high' ? 0 : 1;
        $pb = (json_decode((string)file_get_contents($b), true)['priority'] ?? '') === 'high' ? 0 : 1;
        return $pa <=> $pb ?: strcmp($a, $b);
    });
    foreach ($files as $f) {
        $job = json_decode((string)file_get_contents($f), true);
        foreach ($job['after'] ?? [] as $dep) {
            if (!isset($done[$dep])) {
                continue 2;
            }
        }
        return $f;
    }
    return null;
}

function copyTree(string $src, string $dst): void
{
    if (!is_dir($src)) {
        return;
    }
    @mkdir($dst, 0775, true);
    foreach (scandir($src) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        is_dir("$src/$f") ? copyTree("$src/$f", "$dst/$f") : copy("$src/$f", "$dst/$f");
    }
}

$running = [];   // id => [proc, job, started, lane]
$home = getenv('HOME');
event($dir, 'keeper-start', ['host' => $host, 'lanes' => $lanes]);
$plannerDry = false;
while (true) {
    // Reap.
    foreach ($running as $id => [$proc, $job, $started, $lane]) {
        $st = proc_get_status($proc);
        if ($st['running']) {
            continue;
        }
        $rc = $st['exitcode'];
        proc_close($proc);
        $job += ['host' => $host, 'lane' => $lane, 'rc' => $rc, 'started' => date('c', (int)$started), 'ended' => date('c'),
            'seconds' => round(microtime(true) - $started, 1)];
        if (!empty($job['fetch'])) {
            copyTree("$home/{$job['fetch']}", "$dir/results/$id");
        }
        @unlink("$dir/running/$host/$id.json");
        $where = $rc === 0 ? 'done' : 'failed';
        file_put_contents("$dir/$where/$id.json", json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        event($dir, $where, ['id' => $id, 'host' => $host, 'rc' => $rc, 'seconds' => $job['seconds']]);
        echo sprintf("%s %-6s %-40s %5.1fs\n", date('H:i:s'), $where, $id, $job['seconds']);
        unset($running[$id]);
        if (!empty($job['replan'])) {
            $plannerDry = false; // a chain's step came back: ask the planner for the next one
        }
    }
    // Plan.
    $queued = count(glob("$dir/queue/*.json") ?: []);
    if ($queued < $lowWater && !$plannerDry) {
        $plannerDry = topUp($dir, $planner, max($lanes * 2, $lowWater)) === 0;
    }
    // Start.
    while (count($running) < $lanes && ($f = nextJob($dir)) !== null) {
        $job = json_decode((string)file_get_contents($f), true);
        $id = $job['id'];
        if (!@rename($f, "$dir/running/$host/$id.json")) {
            continue; // someone else claimed it
        }
        $used = array_column($running, 3);
        $lane = 0;
        while (in_array($lane, $used, true)) {
            $lane++;
        }
        foreach ($job['send'] ?? [] as $snd) {
            $dst = "$home/" . $snd['dst'];
            @mkdir(dirname($dst), 0775, true);
            if (!@copy($snd['src'], $dst)) {
                event($dir, 'send-failed', ['id' => $id, 'src' => $snd['src']]);
            }
        }
        $log = "$dir/logs/$id.log";
        $proc = proc_open(['bash', '-c', $job['cmd']], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $home);
        $running[$id] = [$proc, $job, microtime(true), $lane];
        event($dir, 'start', ['id' => $id, 'host' => $host, 'lane' => $lane]);
    }
    file_put_contents("$dir/lanes/$host.json", json_encode(['host' => $host, 'lanes' => $lanes, 'updated' => date('c'),
        'running' => array_map(fn($r) => ['id' => $r[1]['id'], 'lane' => $r[3], 'since' => date('c', (int)$r[2])], array_values($running))]));
    if (!$running && !glob("$dir/queue/*.json") && $plannerDry) {
        break;
    }
    usleep(300000);
}
event($dir, 'keeper-end', ['host' => $host]);
echo shell_exec("$planner collect");
