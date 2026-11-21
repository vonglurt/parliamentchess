<?php
declare(strict_types=1);

/*
 * The generation planner: Parliament Chess nets trained as a chain of Orrery jobs.
 *
 *   php bin/orrery-gen.php DIR plan N | collect | sync [HOST] | chores    (the keeper's planner contract)
 *
 * DIR is an Orrery Queue directory. The planner keeps its own state in DIR/parliament/ when that
 * folder exists (so it can share a queue such as grove with another project's planner), else in DIR:
 * plan.json (what to run), generations.json and lineage.tsv (what was decided), champion.json,
 * and dist/ (the code shipped to the lanes).
 *
 * One generation is a chain, like a crucible segment and its election:
 *   0. pc-prep      once per machine and code version: the repository unpacked into ~/parliament-chess,
 *                   PHP checked and the test suite run (PHP lanes); a venv with PyTorch built (CUDA lanes)
 *   1. pc-label     PHP lanes play games on every core and label positions with a teacher search one ply
 *                   deeper than the player (bin/label.php)                      -> results/ID/shard.jsonl.gz
 *   2. pc-train     one CUDA lane fits the net on every shard the keeper sends it -> results/ID/net2.json
 *   3. pc-gauntlet  PHP lanes play the new net against the champion (bin/selfplay.php)
 *   4. election     each plan pass decides finished gauntlets: the challenger is elected only if its
 *                   score beats 0.5 by more than bar.z standard errors (crucible's ladder rule); it then
 *                   becomes the champion and later generations label with it (expert iteration).
 *
 * Jobs are told apart from other projects' by kind (pc-*): give grove's config.json a project
 * "parliament-chess" with these kinds, and the lanes that have PHP the capability "php".
 */

const REPO = __DIR__ . '/..';
const KINDS = ['pc-prep', 'pc-label', 'pc-train', 'pc-gauntlet'];

$dir = rtrim($argv[1] ?? '', '/');
$verb = $argv[2] ?? 'plan';
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "usage: orrery-gen.php DIR plan N|collect|sync|chores\n");
    exit(2);
}
$dir = realpath($dir);
$state = is_dir("$dir/parliament") ? "$dir/parliament" : $dir;

/**
 * Our jobs in the queue, by id (every Parliament id starts "pc-"). A shared queue holds tens of
 * thousands of other jobs: only files whose names carry "pc-" are opened. Fleet files are named
 * SEQ-ID.json; the one-machine keeper's ID.json.
 */
function jobs(string $dir): array
{
    $out = [];
    $scan = function (string $pattern, string $state) use (&$out): void {
        foreach (glob($pattern) ?: [] as $f) {
            $id = preg_replace('/^\d+-/', '', basename($f, '.json'));
            if (!str_starts_with($id, 'pc-')) {
                continue;
            }
            $j = json_decode((string)@file_get_contents($f), true) ?: ['id' => $id];
            $out[$j['id'] ?? $id] = ['state' => $state, 'job' => $j];
        }
    };
    foreach (['queue', 'done', 'failed', 'stopped'] as $sub) {
        $scan("$dir/$sub/*pc-*.json", $sub);
    }
    $scan("$dir/running/*/*pc-*.json", 'running');
    return $out;
}

/** A job's result file. The fleet keeper's fetch keeps the relative path (results/ID/pc-out/ID/...);
 *  the one-machine keeper copies the folder's contents (results/ID/...). Both are read. */
function rp(string $dir, string $id, string $file): string
{
    $fleet = "$dir/results/$id/pc-out/$id/$file";
    return file_exists($fleet) ? $fleet : "$dir/results/$id/$file";
}

function state(array $have, string $id): string
{
    return $have[$id]['state'] ?? '';
}

/**
 * Where a job runs: its generation's code version, unpacked by pc-prep into ~/parliament-chess/HASH/
 * (one folder a version, so a late setup job can never roll a lane back), sharing one venv.
 * Without a fleet (the one-machine keeper) it runs in this checkout.
 */
function prelude(string $hash = ''): string
{
    $v = $hash !== '' ? '"$HOME/parliament-chess/' . $hash . '"' : '"$HOME/parliament-chess"';
    return 'D=' . $v . '; [ -d "$D/src" ] || D="$HOME/parliament-chess"; [ -d "$D/src" ] || D=' . escapeshellarg((string)realpath(REPO)) . '; cd "$D" || exit 1; '
        . 'PY="$HOME/parliament-chess/.venv/bin/python"; [ -x "$PY" ] || PY="$D/.venv/bin/python"; [ -x "$PY" ] || PY=python3; ';
}

function job(array $j): array
{
    return $j + ['timeout_s' => 4 * 3600];
}

/** A generation's code: the version it was pinned to when it started (state/pins.json), built once. */
function pinned(string $state, string $gen): array
{
    $pins = json_decode((string)@file_get_contents("$state/pins.json"), true) ?: [];
    if (empty($pins[$gen]) || !is_file("$state/dist/parliament-chess-{$pins[$gen]}.tgz")) {
        $pins[$gen] = dist($state)['hash'];
        file_put_contents("$state/pins.json", json_encode($pins, JSON_PRETTY_PRINT));
    }
    $pip = glob("$state/dist/pip-*.whl")[0] ?? null;
    return ['hash' => $pins[$gen], 'tgz' => "$state/dist/parliament-chess-{$pins[$gen]}.tgz", 'pip' => $pip];
}

/** The code the lanes run: a tarball named by a hash of its contents, built once per version. */
function dist(string $state): array
{
    $root = (string)realpath(REPO);
    $files = [];
    foreach (['src', 'bin'] as $d) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$d", FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $files[] = substr($f->getPathname(), strlen($root) + 1);
            }
        }
    }
    foreach (['train/train_torch.py', 'train/requirements.txt', 'train/requirements-pascal.txt', 'RULES.md', 'LICENSE'] as $f) {
        if (is_file("$root/$f")) {
            $files[] = $f;
        }
    }
    sort($files);
    $h = hash_init('sha1');
    foreach ($files as $f) {
        hash_update($h, $f . "\0" . sha1_file("$root/$f") . "\n");
    }
    $hash = substr(hash_final($h), 0, 10);
    $tgz = "$state/dist/parliament-chess-$hash.tgz";
    if (!is_file($tgz)) {
        @mkdir("$state/dist", 0775, true);
        $list = tempnam(sys_get_temp_dir(), 'pc');
        file_put_contents($list, implode("\n", $files) . "\n");
        // No macOS extended attributes in the archive: Linux tar warns about every one of them.
        $noMeta = PHP_OS_FAMILY === 'Darwin' ? 'COPYFILE_DISABLE=1 tar --no-xattrs --no-mac-metadata' : 'tar';
        exec($noMeta . ' -czf ' . escapeshellarg("$tgz.tmp") . ' -C ' . escapeshellarg($root) . ' -T ' . escapeshellarg($list) . ' 2>&1', $o, $rc);
        unlink($list);
        if ($rc !== 0) {
            fwrite(STDERR, "could not pack the code: " . implode("\n", $o) . "\n");
            exit(1);
        }
        rename("$tgz.tmp", $tgz);
    }
    $pip = glob("$state/dist/pip-*.whl")[0] ?? null;
    return ['hash' => $hash, 'tgz' => $tgz, 'pip' => $pip];
}

/** Lanes that build for themselves (a second lane on a machine uses its first lane's build). */
function lanes(string $dir): array
{
    $cfg = json_decode((string)@file_get_contents("$dir/config.json"), true) ?: [];
    $out = [];
    foreach ($cfg['hosts'] ?? [] as $h) {
        if (($h['target'] ?? '-') === '-' || !empty($h['build_of']) || !empty($h['paused'])) {
            continue;
        }
        $out[$h['name']] = $h['caps'] ?? ['cpu'];
    }
    return $out;
}

/** The setup job for one lane and one code version. */
function prepJob(string $name, array $caps, array $d, array $needs): ?array
{
    $isCuda = in_array($needs['train'], $caps, true);
    $isPhp = in_array($needs['label'], $caps, true) || in_array($needs['gauntlet'], $caps, true);
    if (!$isCuda && !$isPhp) {
        return null;
    }
    $id = "pc-prep2-$name-{$d['hash']}"; // prep2: one folder a version
    $tgz = 'pc-dist/' . basename($d['tgz']);
    $send = [['src' => $d['tgz'], 'dst' => $tgz]];
    $out = '"$HOME/pc-out/' . $id . '"';
    $cmd = 'set -e; mkdir -p ~/parliament-chess/' . $d['hash'] . ' ' . $out . '; cd ~/parliament-chess/' . $d['hash'] . '; '
        . 'tar -xzf ~/' . $tgz . '; ';
    if ($isPhp) {
        $cmd .= 'php -r \'exit(version_compare(PHP_VERSION, "8.2", "<") ? 1 : 0);\'; '
            . 'php -v | head -1 > ' . $out . '/php.txt; sh bin/php bin/test.php --quick > ' . $out . '/test.log 2>&1; ';
    }
    if ($isCuda) {
        if (!$d['pip']) {
            return null; // no pip wheel in dist/ to bootstrap the venv with
        }
        $whl = 'pc-dist/' . basename($d['pip']);
        $send[] = ['src' => $d['pip'], 'dst' => $whl];
        $venv = '~/parliament-chess/.venv';
        $cmd .= 'if ! ' . $venv . '/bin/python -c "import torch, sys; sys.exit(0 if torch.__version__.startswith(\'2.14.1\') else 1)" 2>/dev/null; then '
            . 'rm -rf ' . $venv . '; python3 -m venv --without-pip ' . $venv . '; ' . $venv . '/bin/python ~/' . $whl . '/pip install -q --no-index ~/' . $whl . '; '
            . $venv . '/bin/pip install -q -r train/requirements-pascal.txt; fi; '
            . $venv . '/bin/python -c "import torch; x = torch.ones(1024, device=\'cuda\'); print(torch.__version__, torch.cuda.get_device_name(0), float(x.sum()))" > ' . $out . '/torch.txt 2>&1; ';
    }
    $cmd .= 'echo ok > ' . $out . '/ready';
    return job(['id' => $id, 'kind' => 'pc-prep', 'title' => "Parliament Chess setup on $name ({$d['hash']})",
        'needs' => "host:$name", 'send' => $send, 'cmd' => $cmd, 'fetch' => "pc-out/$id", 'priority' => 'high',
        'timeout_s' => 2 * 3600, 'stage' => 'prep']);
}

/** Planner state per generation: label ids, train id, gauntlet ids, and what is done. */
function generation(array $gen, int $gi, array $have): array
{
    $name = $gen['name'];
    $L = ($gen['label'] ?? []) + ['jobs' => 40, 'games' => 3, 'workers' => 0, 'depth' => 3, 'player' => 'medium/populist', 'every' => 3, 'needs' => 'php'];
    $T = ($gen['train'] ?? []) + ['needs' => 'cuda', 'epochs' => 12, 'h1' => 128, 'h2' => 32, 'batch' => 1024, 'warm' => true, 'mode' => 'residual', 'lam' => 0.1];
    $G = ($gen['gauntlet'] ?? []) + ['jobs' => 25, 'games' => 1, 'workers' => 0, 'challenger' => 'medium+net2', 'champion' => 'medium', 'needs' => 'php'];
    $labels = [];
    for ($k = 1; $k <= $L['jobs']; $k++) {
        $labels[] = sprintf('pc-%s-label-%03d', $name, $k);
    }
    $gaunts = [];
    for ($k = 1; $k <= $G['jobs']; $k++) {
        $gaunts[] = sprintf('pc-%s-gauntlet-%03d', $name, $k);
    }
    $done = fn(array $ids) => count(array_filter($ids, fn($i) => state($have, $i) === 'done')) === count($ids);
    return ['name' => $name, 'gi' => $gi, 'L' => $L, 'T' => $T, 'G' => $G, 'labels' => $labels,
        'bookless' => ($gen['label']['book'] ?? true) === false, // label.book false: --no-book (code from 2026-11-20 on)
        'train' => "pc-$name-train", 'gaunts' => $gaunts, 'labelsDone' => $done($labels),
        'trainDone' => state($have, "pc-$name-train") === 'done', 'gauntDone' => $done($gaunts)];
}

/** The champion before generation $gi (null: the hand-written evaluation). */
function championBefore(string $state, int $gi): ?array
{
    $champ = null;
    foreach (json_decode((string)@file_get_contents("$state/generations.json"), true) ?: [] as $row) {
        if ($row['gi'] < $gi && $row['elected']) {
            $champ = $row;
        }
    }
    return $champ;
}

/** Elections: decide every generation whose gauntlet is complete. Runs at the start of each pass. */
function decide(string $dir, string $state): array
{
    $plan = json_decode((string)file_get_contents("$state/plan.json"), true);
    $have = jobs($dir);
    $rows = json_decode((string)@file_get_contents("$state/generations.json"), true) ?: [];
    $decided = array_column($rows, 'name');
    foreach ($plan['generations'] as $gi => $spec) {
        $g = generation($spec, $gi, $have);
        if (!$g['gauntDone'] || in_array($g['name'], $decided, true)) {
            continue;
        }
        $scores = [];
        // What the challenger did with the election layer across its gauntlet: Writs filed, elections
        // held (counts), crowns moved, Kings crowned, promotions. The gate reads these.
        $politics = ['writs' => 0, 'elections' => 0, 'crownMoves' => 0, 'coronations' => 0, 'promotions' => 0];
        foreach ($g['gaunts'] as $id) {
            $sum = json_decode((string)@file_get_contents(rp($dir, $id, 'summary.json')), true);
            foreach ($sum['files'] ?? [] as $f) {
                $r = json_decode((string)@file_get_contents(rp($dir, $id, $f)), true);
                if (!$r) {
                    continue;
                }
                $col = $r['white'] === $g['G']['challenger'] ? 1 : -1;
                $scores[] = $r['winner'] === null ? 0.5 : ($r['winner'] === $col ? 1.0 : 0.0);
                foreach ($r['log'] ?? [] as $e) {
                    if ($e['by'] === $col && !empty($e['writ'])) {
                        $politics['writs']++;
                    }
                    if ($e['by'] === $col && str_contains((string)$e['san'], '=')) {
                        $politics['promotions']++;
                    }
                    $c = $e['count'] ?? null;
                    if ($c && ($c['color'] ?? 0) === $col) {
                        $politics['elections']++;
                        $out = $c['outcome'] ?? '';
                        $politics['crownMoves'] += in_array($out, ['coronation', 'contested', 'swap'], true) ? 1 : 0;
                        $politics['coronations'] += in_array($out, ['coronation', 'contested'], true) ? 1 : 0;
                    }
                }
            }
        }
        $n = count($scores);
        $mean = $n ? array_sum($scores) / $n : 0.5;
        $sd = $n > 1 ? sqrt(array_sum(array_map(fn($x) => ($x - $mean) ** 2, $scores)) / ($n - 1)) : 0.5;
        $se = $sd / sqrt(max(1, $n));
        $z = $se > 0 ? ($mean - 0.5) / $se : 0.0;
        $bar = (float)($spec['bar']['z'] ?? 2.0);
        // The election gate: no generation is elected unless it held at least bar.elections elections.
        $minElections = (int)($spec['bar']['elections'] ?? 1);
        $strong = $z > $bar;
        $political = $politics['elections'] >= $minElections;
        // Generation timing: lane-seconds per stage, wall clock from the first start to the last end.
        $stage = ['label' => 0.0, 'train' => 0.0, 'gauntlet' => 0.0];
        $first = PHP_INT_MAX;
        $last = 0;
        $hosts = [];
        foreach (array_merge($g['labels'], [$g['train']], $g['gaunts']) as $id) {
            $j = $have[$id]['job'] ?? null;
            if (!$j) {
                continue;
            }
            $secs = (float)($j['seconds'] ?? 0);
            $stage[$j['stage'] ?? 'label'] += $secs;
            $end = strtotime((string)($j['ended'] ?? 'now'));
            $first = min($first, (int)($end - $secs));
            $last = max($last, $end);
            if (!empty($j['host'])) {
                $hosts[$j['host']] = true;
            }
        }
        $labels = 0;
        foreach ($g['labels'] as $id) {
            $labels += (int)(json_decode((string)@file_get_contents(rp($dir, $id, 'summary.json')), true)['labels'] ?? 0);
        }
        $train = json_decode((string)@file_get_contents(rp($dir, $g['train'], 'net2.json')), true)['meta'] ?? [];
        $hist = $train['history'] ?? [];
        $lastEpoch = $hist ? $hist[count($hist) - 1] : [];
        $row = ['name' => $g['name'], 'gi' => $gi, 'games' => $n, 'score' => round($mean, 4), 'se' => round($se, 4), 'z' => round($z, 2),
            'bar' => $bar, 'elected' => $strong && $political, 'minElections' => $minElections, 'politics' => $politics,
            'why' => $strong && $political ? 'stronger, and it holds elections' : (!$strong ? 'not stronger by the bar' : 'stronger, but it held fewer than ' . $minElections . ' election(s)'),
            'labels' => $labels, 'device' => $train['device'] ?? null,
            'valValueMSE' => $lastEpoch['valValueMSE'] ?? null, 'valPolicyTop1' => $lastEpoch['valPolicyTop1'] ?? null,
            'laneSeconds' => array_map(fn($x) => round($x, 1), $stage), 'wallSeconds' => $last > 0 ? $last - $first : null,
            'hosts' => array_keys($hosts), 'decided' => date('c')];
        $rows[] = $row;
        file_put_contents("$state/lineage.tsv", implode("\t", [$row['name'], $row['games'], $row['score'], $row['se'], $row['z'],
            $row['elected'] ? 'elected' : 'not elected: ' . $row['why'], $row['politics']['elections'], $row['labels'], $row['laneSeconds']['label'], $row['laneSeconds']['train'],
            $row['laneSeconds']['gauntlet'], $row['wallSeconds'], $row['decided']]) . "\n", FILE_APPEND);
        if ($row['elected']) {
            copy(rp($dir, $g['train'], 'net2.json'), "$state/champion.json");
        }
    }
    file_put_contents("$state/generations.json", json_encode($rows, JSON_PRETTY_PRINT));
    return $rows;
}

/**
 * plan.continue = {"max": N}: when the last listed generation has been decided, the next one is
 * written into plan.json as a copy of it (g006 -> g007), until N generations are listed. So the
 * chain runs on by itself, a generation after each verdict, with nobody watching.
 */
function extendPlan(string $state, array $decided): void
{
    $plan = json_decode((string)file_get_contents("$state/plan.json"), true);
    $max = (int)($plan['continue']['max'] ?? 0);
    $gens = $plan['generations'] ?? [];
    $last = $gens ? $gens[count($gens) - 1] : null;
    if ($max <= 0 || !$last || !in_array($last['name'], $decided, true) || count($gens) >= $max) {
        return;
    }
    $next = $last;
    $next['name'] = sprintf('g%03d', (int)substr($last['name'], 1) + 1);
    $plan['generations'][] = $next;
    $tmp = "$state/plan.json.tmp";
    file_put_contents($tmp, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    rename($tmp, "$state/plan.json");
}

/**
 * plan.git = {"commit": true, "push": false, "branch": "main"}: every verdict is committed to the
 * Parliament Chess repository, docs/data/generations.json and lineage.tsv, and every elected
 * generation's net as models/gNNN.json. Only those paths are committed (git commit --only), so
 * whatever else is changed or staged in the checkout is left alone. It runs at every plan pass
 * and does nothing when nothing changed, so a commit that failed (a lock, a rebase under way) is
 * made at the next pass. Failures go to STATE/publish.log and never stop the planner.
 */
function publish(string $dir, string $state): void
{
    $plan = json_decode((string)file_get_contents("$state/plan.json"), true);
    $git = $plan['git'] ?? [];
    if (empty($git['commit'])) {
        return;
    }
    $repo = (string)realpath(getenv('PC_PUBLISH_REPO') ?: REPO);
    $rows = json_decode((string)@file_get_contents("$state/generations.json"), true) ?: [];
    if (!$rows || !is_dir("$repo/.git")) {
        return;
    }
    $want = ['docs/data/generations.json' => (string)file_get_contents("$state/generations.json"),
        'docs/data/lineage.tsv' => (string)@file_get_contents("$state/lineage.tsv")];
    foreach ($rows as $r) {
        if (!empty($r['elected']) && is_file($net = rp($dir, 'pc-' . $r['name'] . '-train', 'net2.json'))) {
            $want["models/{$r['name']}.json"] = (string)file_get_contents($net);
        }
    }
    $changed = [];
    foreach ($want as $rel => $body) {
        if (@file_get_contents("$repo/$rel") !== $body) {
            @mkdir(dirname("$repo/$rel"), 0775, true);
            file_put_contents("$repo/$rel", $body);
            $changed[] = $rel;
        }
    }
    $tracked = trim((string)shell_exec('git -C ' . escapeshellarg($repo) . ' status --porcelain -- '
        . implode(' ', array_map('escapeshellarg', array_keys($want))) . ' 2>/dev/null'));
    if (!$changed && $tracked === '') {
        return;
    }
    $last = $rows[count($rows) - 1];
    $msg = sprintf("Orrery: generation %s %s (score %.3f, z %.2f, %d games, %d elections)",
        $last['name'], $last['elected'] ? 'ELECTED' : 'not elected', $last['score'], $last['z'], $last['games'],
        $last['politics']['elections'] ?? 0);
    $paths = implode(' ', array_map('escapeshellarg', array_keys($want)));
    $g = 'git -C ' . escapeshellarg($repo);
    $log = [];
    exec("$g add -- $paths 2>&1", $log, $rc);
    if ($rc === 0) {
        exec("$g commit -q --only -m " . escapeshellarg($msg) . " -- $paths 2>&1", $log, $rc);
    }
    if ($rc === 0 && !empty($git['push'])) {
        $branch = trim((string)shell_exec("$g rev-parse --abbrev-ref HEAD 2>/dev/null"));
        if ($branch === ($git['branch'] ?? 'main')) {
            // gh by its full path: the keeper's PATH is whatever it was started with.
            $gh = trim((string)shell_exec('command -v gh 2>/dev/null'));
            foreach (['/opt/homebrew/bin/gh', '/usr/local/bin/gh'] as $p) {
                $gh = $gh !== '' ? $gh : (is_executable($p) ? $p : '');
            }
            exec("$g -c credential.helper= -c " . escapeshellarg("credential.helper=!" . ($gh ?: 'gh') . " auth git-credential")
                . " push -q origin " . escapeshellarg($branch) . " 2>&1", $log, $rc);
        } else {
            $log[] = "not pushed: the checkout is on $branch";
        }
    }
    file_put_contents("$state/publish.log", date('c') . ($rc === 0 ? " committed: $msg" : " FAILED ($rc)") . "\n"
        . ($log ? '    ' . implode("\n    ", $log) . "\n" : ''), FILE_APPEND);
}

if ($verb === 'plan') {
    $n = (int)($argv[3] ?? 8);
    if (!is_file("$state/plan.json")) {
        echo "[]\n";
        exit(0);
    }
    $rowsNow = decide($dir, $state);
    extendPlan($state, array_column($rowsNow, 'name'));
    publish($dir, $state);
    $plan = json_decode((string)file_get_contents("$state/plan.json"), true);
    $have = jobs($dir);
    $decided = array_column(json_decode((string)@file_get_contents("$state/generations.json"), true) ?: [], 'name');
    $out = [];
    $lanes = lanes($dir);
    // Sharing a queue: keep at most throttle.queued of our lane jobs waiting at once, so the keeper's
    // low water (which counts every queued job a CPU lane could take) barely moves for the other project.
    $maxQueued = (int)($plan['throttle']['queued'] ?? PHP_INT_MAX);
    $queued = count(array_filter($have, fn($h) => $h['state'] === 'queue' && in_array($h['job']['kind'] ?? '', ['pc-label', 'pc-gauntlet'], true)));
    $room = max(0, $maxQueued - $queued);
    foreach ($plan['generations'] as $gi => $spec) {
        $g = generation($spec, $gi, $have);
        $name = $g['name'];
        if (in_array($name, $decided, true)) {
            continue;
        }
        if ($gi > 0 && !in_array($plan['generations'][$gi - 1]['name'], $decided, true)) {
            break; // a generation starts only when the one before it has been decided
        }
        $d = pinned($state, $name);
        $needs = ['label' => $g['L']['needs'], 'train' => $g['T']['needs'], 'gauntlet' => $g['G']['needs']];
        // Setup first, on every lane that can take this project's work, once per code version.
        foreach ($lanes as $lane => $caps) {
            $p = prepJob($lane, $caps, $d, $needs);
            if ($p && !empty($plan['priority'])) {
                $p['priority'] = $plan['priority']; // a lane's setup must not wait behind the work it unlocks
            }
            if ($p && !isset($have[$p['id']]) && count($out) < $n) {
                $out[] = $p;
            }
        }
        $afterHost = $lanes ? 'pc-prep2-{host}-' . $d['hash'] : null;
        $champ = championBefore($state, $gi);
        $champSend = $champ ? [['src' => rp($dir, 'pc-' . $champ['name'] . '-train', 'net2.json'), 'dst' => "pc-data/$name/champion.json"]] : [];
        $spec2 = fn(string $s) => $s === 'champion' ? ($champ ? 'medium+net2:$HOME/pc-data/' . $name . '/champion.json' : 'medium') : $s;
        // plan.priority "high" puts label and gauntlet jobs ahead of the standing work (training is always high).
        $common = fn(array $j) => job($j + array_filter(['after_host' => $afterHost, 'gen' => $name, 'replan' => true,
            'priority' => $plan['priority'] ?? null]));
        foreach ($g['labels'] as $k => $id) {
            if (isset($have[$id]) || count($out) >= $n || $room <= 0) {
                continue;
            }
            $room--;
            $L = $g['L'];
            $out[] = $common(['id' => $id, 'kind' => 'pc-label', 'title' => "Parliament $name: label at depth {$L['depth']} ($id)",
                'needs' => $L['needs'], 'send' => $L['player'] === 'champion' ? $champSend : [], 'stage' => 'label',
                'cmd' => prelude($lanes ? $d['hash'] : '') . 'sh bin/php bin/label.php --job ' . $id . ' --games ' . (int)$L['games'] . ' --workers ' . (int)$L['workers']
                    . ' --depth ' . (int)$L['depth'] . ' --every ' . (int)$L['every'] . ' --seed ' . (($gi + 1) * 1000 + $k + 1)
                    . ' --player "' . $spec2($L['player']) . '"' . (!empty($g['bookless']) ? ' --no-book' : '') . ' --out "$HOME/pc-out/' . $id . '"',
                'fetch' => "pc-out/$id"]);
        }
        if ($g['labelsDone'] && !isset($have[$g['train']]) && count($out) < $n) {
            $T = $g['T'];
            $send = [];
            foreach ($g['labels'] as $id) {
                $send[] = ['src' => rp($dir, $id, 'shard.jsonl.gz'), 'dst' => "pc-data/$name/labels/$id.jsonl.gz"];
            }
            // A replay window: train.window = N also trains on the labels of the N-1 generations before,
            // so a generation can label fewer new positions (labelling is most of a generation's time).
            for ($back = 1; $back < (int)($T['window'] ?? 1) && $gi - $back >= 0; $back++) {
                $old = generation($plan['generations'][$gi - $back], $gi - $back, $have);
                foreach ($old['labels'] as $id) {
                    if (is_file(rp($dir, $id, 'shard.jsonl.gz'))) {
                        $send[] = ['src' => rp($dir, $id, 'shard.jsonl.gz'), 'dst' => "pc-data/$name/labels/$id.jsonl.gz"];
                    }
                }
            }
            $init = '';
            if ($T['warm'] && $champ) {
                $send = array_merge($send, $champSend);
                $init = ' --init "$HOME/pc-data/' . $name . '/champion.json"';
            }
            // Cookies for non-standard moves, shrinking each generation (cookies.decay), so the bonus
            // opens the door early and the result alone decides later generations.
            $ck = $T['cookies'] ?? [];
            $scale = isset($ck['decay']) ? ((float)$ck['decay']) ** $gi : 1.0;
            $flags = '';
            if (!empty($T['reinforce'])) {
                $flags .= ' --reinforce ' . (float)$T['reinforce'];
            }
            foreach (['writ' => '--cookie-writ', 'promo' => '--cookie-promo', 'weight' => '--weight-special'] as $k => $flag) {
                if (isset($ck[$k])) {
                    $v = $k === 'weight' ? 1 + ((float)$ck[$k] - 1) * $scale : (float)$ck[$k] * $scale;
                    $flags .= ' ' . $flag . ' ' . round($v, 3);
                }
            }
            $o = '"$HOME/pc-out/' . $g['train'] . '"';
            $out[] = $common(['id' => $g['train'], 'kind' => 'pc-train', 'title' => "Parliament $name: train the net", 'needs' => $T['needs'],
                'after' => $g['labels'], 'send' => $send, 'stage' => 'train', 'priority' => 'high',
                'cmd' => prelude($lanes ? $d['hash'] : '') . 'mkdir -p ' . $o . '; "$PY" train/train_torch.py --data "$HOME/pc-data/' . $name . '/labels/*.jsonl.gz"'
                    . ' --epochs ' . (int)$T['epochs'] . ' --h1 ' . (int)$T['h1'] . ' --h2 ' . (int)$T['h2'] . ' --batch ' . (int)$T['batch'] . $init
                    . ' --mode ' . escapeshellarg((string)$T['mode']) . ' --lam ' . (float)$T['lam'] . $flags
                    . ' --out ' . $o . '/net2.json > ' . $o . '/train.log 2>&1',
                'fetch' => "pc-out/{$g['train']}"]);
        }
        if ($g['trainDone']) {
            foreach ($g['gaunts'] as $k => $id) {
                if (isset($have[$id]) || count($out) >= $n || $room <= 0) {
                    continue;
                }
                $room--;
                $G = $g['G'];
                $black = $spec2($G['champion']);
                $out[] = $common(['id' => $id, 'kind' => 'pc-gauntlet', 'title' => "Parliament $name: challenger vs champion ($id)",
                    'needs' => $G['needs'], 'stage' => 'gauntlet',
                    'send' => array_merge([['src' => rp($dir, $g['train'], 'net2.json'), 'dst' => "pc-data/$name/net2.json"]],
                        $G['champion'] === 'champion' && $champ ? $champSend : []),
                    'cmd' => prelude($lanes ? $d['hash'] : '') . 'PC_NET2="$HOME/pc-data/' . $name . '/net2.json" sh bin/php bin/selfplay.php --job ' . $id
                        . ' --white "' . $G['challenger'] . '" --black "' . $black . '" --games ' . (int)$G['games'] . ' --workers ' . (int)$G['workers']
                        . ' --seed ' . (($gi + 1) * 1000 + $k + 501) . ' --opening-random 2 --max-plies 300 --quiet --no-book'
                        . ' --out "$HOME/pc-out/' . $id . '"',
                    'fetch' => "pc-out/$id"]);
            }
        }
        break; // one generation at a time
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

if ($verb === 'collect') {
    if (!is_file("$state/plan.json")) {
        exit(0);
    }
    foreach (decide($dir, $state) as $r) {
        printf("%s  score %.3f +- %.3f over %d games, z %.2f (bar %.1f), %d election(s): %s | labels %d | lane-s label %.0f, train %.0f, gauntlet %.0f | wall %ss\n",
            $r['name'], $r['score'], $r['se'], $r['games'], $r['z'], $r['bar'], $r['politics']['elections'] ?? 0,
            $r['elected'] ? 'ELECTED' : 'not elected (' . ($r['why'] ?? '') . ')', $r['labels'],
            $r['laneSeconds']['label'], $r['laneSeconds']['train'], $r['laneSeconds']['gauntlet'], $r['wallSeconds'] ?? '-');
    }
    exit(0);
}

if ($verb === 'sync' || $verb === 'chores') {
    exit(0);
}
fwrite(STDERR, "orrery-gen: '$verb' is not a verb of this planner\n");
exit(2);
