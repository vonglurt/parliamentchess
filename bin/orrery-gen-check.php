<?php
declare(strict_types=1);

/*
 * The election rules of the generation planner, proved on queues made up for the purpose.
 *
 *   php bin/orrery-gen-check.php [PLANNER] [--replay QUEUE]
 *
 * Each case builds a queue folder in the temporary directory as the fleet keeper would leave it (job
 * files in done/ and queue/, results fetched under results/ID/pc-out/ID/), runs the planner's plan or
 * collect verb on it as the keeper does, and reads what it planned and what it decided. PLANNER is
 * bin/orrery-gen.php by default; give it the file from before a change (git show REV:bin/orrery-gen.php)
 * to see which claims that code fails.
 *
 * --replay QUEUE decides the real record again: the queue's results and done jobs are linked, read
 * only, and its parliament/ state is copied with git publishing and the control turned off, so nothing
 * is committed and the live state is not touched. Every generation the new rules do not reach must get
 * the verdict it got on the day.
 */

$args = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--replay'));
$replayAt = array_search('--replay', $argv, true);
$replay = $replayAt !== false ? ($argv[$replayAt + 1] ?? '') : null;
if ($replay !== null) {
    $args = array_values(array_diff($args, [$replay]));
}
$planner = realpath($args[0] ?? __DIR__ . '/orrery-gen.php');
$php = PHP_BINARY;
$fails = 0;
$passes = 0;

function check(bool $ok, string $what): void
{
    global $fails, $passes;
    $ok ? $passes++ : $fails++;
    echo ($ok ? '  ok   ' : '  FAIL ') . "$what\n";
}

function put(string $file, $data): void
{
    @mkdir(dirname($file), 0775, true);
    file_put_contents($file, is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// JSON writes 0.0 as 0, so a z read back is compared as a float.

/** A fresh queue: no lanes (so no setup jobs), the given plan, and the rows decided before. */
function queue(array $gens, array $rows = [], array $planExtra = []): string
{
    $dir = sys_get_temp_dir() . '/pc-gen-check-' . getmypid() . '-' . bin2hex(random_bytes(3));
    put("$dir/config.json", ['hosts' => []]);
    put("$dir/parliament/plan.json", ['generations' => $gens] + $planExtra);
    put("$dir/parliament/generations.json", $rows);
    return $dir;
}

/** A generation's plan entry, small: two label jobs and four gauntlet jobs. */
function spec(string $name, array $extra = []): array
{
    return array_replace_recursive(['name' => $name, 'label' => ['jobs' => 2, 'player' => 'champion'],
        'train' => ['needs' => 'gpu'], 'gauntlet' => ['jobs' => 4, 'games' => 1, 'workers' => 10, 'champion' => 'champion'],
        'bar' => ['z' => 2, 'elections' => 1]], $extra);
}

/** A row decided earlier: an elected champion. */
function champion(string $name, int $gi): array
{
    return ['name' => $name, 'gi' => $gi, 'games' => 200, 'score' => 0.6, 'se' => 0.03, 'z' => 3.3, 'bar' => 2, 'elected' => true,
        'politics' => ['elections' => 90], 'why' => 'stronger, and it holds elections', 'labels' => 1000,
        'laneSeconds' => ['label' => 1, 'train' => 1, 'gauntlet' => 1], 'wallSeconds' => 3, 'decided' => '2026-11-20T00:00:00+00:00'];
}

function done(string $dir, string $id, string $stage, array $extra = []): void
{
    put("$dir/done/$id.json", ['id' => $id, 'kind' => 'pc-' . ($stage === 'control' || $stage === 'confirm' ? 'gauntlet' : $stage), 'stage' => $stage === 'confirm' ? 'gauntlet' : $stage,
        'host' => 'lane', 'seconds' => 60, 'ended' => '2026-11-26T12:00:00'] + $extra);
}

function failed(string $dir, string $id): void
{
    put("$dir/failed/$id.json", ['id' => $id, 'kind' => 'pc-gauntlet', 'stage' => 'control', 'rc' => 1]);
}

/** A net: the weights a given seed makes, and a meta that says how it was trained. */
function net(string $dir, string $train, int $seed, array $meta = []): void
{
    mt_srand($seed);
    $w = [];
    for ($i = 0; $i < 64; $i++) {
        $w[] = mt_rand(-1000, 1000) / 1000;
    }
    put("$dir/results/$train/pc-out/$train/net2.json", ['version' => 2, 'h1' => 8, 'h2' => 4, 'w2' => $w,
        'meta' => $meta + ['trained' => date('c', 1795000000 + $seed), 'device' => 'test card', 'history' => [['epoch' => 0, 'valValueMSE' => 0.2, 'valPolicyTop1' => 0.1]]]]);
}

/**
 * A finished round of games: JOBS jobs, each WINS / LOSSES / the rest drawn of PER games from the
 * challenger's side, colours alternating, each game holding one election the challenger called.
 */
function games(string $dir, string $name, string $round, int $jobs, int $per, int $wins, int $losses, string $stage): void
{
    for ($k = 1; $k <= $jobs; $k++) {
        $id = sprintf('pc-%s-%s-%03d', $name, $round, $k);
        $files = [];
        for ($i = 0; $i < $per; $i++) {
            $col = $i % 2 === 0 ? 1 : -1;
            $result = $i < $wins ? $col : ($i < $wins + $losses ? -$col : null);
            $f = sprintf('%s-g%02d.json', $id, $i + 1);
            put("$dir/results/$id/pc-out/$id/$f", ['white' => $col === 1 ? 'medium+net2' : "medium+net2:\$HOME/pc-data/$name/champion.json",
                'winner' => $result, 'log' => [['by' => $col, 'san' => 'e4', 'writ' => true, 'count' => null],
                    ['by' => -$col, 'san' => 'e5', 'count' => ['color' => $col, 'outcome' => 'coronation']]]]);
            $files[] = $f;
        }
        put("$dir/results/$id/pc-out/$id/summary.json", ['job' => $id, 'files' => $files]);
        done($dir, $id, $stage);
    }
}

/** The labels and the training of a generation, done. */
function trained(string $dir, string $name, int $seed, array $meta = []): void
{
    for ($k = 1; $k <= 2; $k++) {
        $id = sprintf('pc-%s-label-%03d', $name, $k);
        put("$dir/results/$id/pc-out/$id/summary.json", ['labels' => 500]);
        done($dir, $id, 'label');
    }
    net($dir, "pc-$name-train", $seed, $meta);
    done($dir, "pc-$name-train", 'train');
}

function run(string $dir, string $verb): array
{
    global $php, $planner;
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($planner) . ' ' . escapeshellarg($dir) . ' ' . $verb . ' 2>&1';
    $out = (string)shell_exec('PC_PUBLISH_REPO=/nonexistent ' . $cmd);
    return $verb === 'collect' ? ['text' => $out] : ['jobs' => json_decode($out, true), 'text' => $out];
}

function rows(string $dir): array
{
    $out = [];
    foreach (json_decode((string)@file_get_contents("$dir/parliament/generations.json"), true) ?: [] as $r) {
        $out[$r['name']] = $r;
    }
    return $out;
}

function ids(array $jobs, string $like): array
{
    return array_values(array_filter(array_column($jobs ?? [], 'id'), fn($i) => str_contains($i, $like)));
}

function seeds(array $jobs, string $like): array
{
    $out = [];
    foreach ($jobs ?? [] as $j) {
        if (str_contains($j['id'], $like) && preg_match('/--seed (\d+)/', $j['cmd'], $m)) {
            $out[] = (int)$m[1];
        }
    }
    return $out;
}

if ($replay !== null) {
    $q = realpath($replay);
    if (!$q || !is_file("$q/parliament/generations.json")) {
        fwrite(STDERR, "--replay QUEUE: no parliament/generations.json in '$replay'\n");
        exit(2);
    }
    $was = json_decode((string)file_get_contents("$q/parliament/generations.json"), true);
    $dir = sys_get_temp_dir() . '/pc-gen-replay-' . getmypid();
    @mkdir("$dir/parliament", 0775, true);
    foreach (['results', 'done', 'failed', 'queue', 'stopped', 'running'] as $sub) {
        if (is_dir("$q/$sub")) {
            symlink("$q/$sub", "$dir/$sub");
        }
    }
    put("$dir/config.json", ['hosts' => []]);
    $plan = json_decode((string)file_get_contents("$q/parliament/plan.json"), true);
    $plan['git'] = ['commit' => false];
    foreach ($plan['generations'] as &$gen) {
        $gen['control'] = ['every' => 0];
    }
    unset($gen);
    put("$dir/parliament/plan.json", $plan);
    put("$dir/parliament/generations.json", []);
    $t = microtime(true);
    $text = run($dir, 'collect')['text'];
    $secs = microtime(true) - $t;
    $now = rows($dir);
    echo "Replay of " . count($was) . " generations in " . round($secs, 1) . " s ($q, read only)\n";
    $same = $moved = 0;
    foreach ($was as $r) {
        $n = $now[$r['name']] ?? null;
        $tag = $n === null ? 'undecided' : ($n['z'] === null ? 'refused: ' . $n['why'] : sprintf('z %.2f %s', $n['z'], $n['elected'] ? 'elected' : 'not elected'));
        if ($n && $n['z'] !== null && $n['z'] === $r['z'] && $n['elected'] === $r['elected'] && $n['games'] === $r['games']) {
            $same++;
            continue;
        }
        $moved++;
        printf("  %s  on the day z %.2f %s, now %s\n", $r['name'], $r['z'], $r['elected'] ? 'ELECTED' : 'not elected', $tag);
    }
    echo "  $same verdicts the same, $moved changed or waiting\n";
    echo preg_replace('/^/m', '    | ', trim(implode("\n", array_filter(explode("\n", $text), fn($l) => str_contains($l, 'under way') || str_contains($l, 'not played'))))) . "\n";
    exec('rm -rf ' . escapeshellarg($dir));

    // A net that has played is judged by its games, so the replay cannot show g016 refused. Here is the
    // queue as it stood when g016's net came back, before its gauntlet: g015 the champion, g016's
    // labels and training done, their real results linked.
    $byName = array_column($plan['generations'], null, 'name');
    $g015 = array_column($was, null, 'name')['g015'] ?? null;
    if ($g015 && isset($byName['g015'], $byName['g016'])) {
        echo "\ng016 as it came back from the trainer, on its real files\n";
        $dir = sys_get_temp_dir() . '/pc-gen-g016-' . getmypid();
        put("$dir/config.json", ['hosts' => []]);
        put("$dir/parliament/plan.json", ['generations' => [$byName['g015'], $byName['g016']], 'git' => ['commit' => false]]);
        put("$dir/parliament/generations.json", [['gi' => 0] + $g015]);
        @mkdir("$dir/results", 0775, true);
        foreach (array_merge(glob("$q/done/*pc-g016-label-*.json") ?: [], glob("$q/done/*pc-g016-train.json") ?: [], glob("$q/done/*pc-g015-train.json") ?: []) as $f) {
            put("$dir/done/" . basename($f), (string)file_get_contents($f));
            $id = preg_replace('/^\d+-/', '', basename($f, '.json'));
            if (is_dir("$q/results/$id")) {
                symlink("$q/results/$id", "$dir/results/$id");
            }
        }
        $p = run($dir, 'plan 64');
        $r = rows($dir)['g016'] ?? null;
        check(ids($p['jobs'], 'g016-gauntlet') === [], 'g016 gets no gauntlet');
        check($r !== null && !empty($r['unchanged']) && str_contains($r['why'], 'champion g015'), 'g016 is refused as champion g015\'s weights: ' . ($r['why'] ?? '-'));
        exec('rm -rf ' . escapeshellarg($dir));
    }
    exit($fails ? 1 : 0);
}

echo "Planner: $planner\n\n";

echo "An unchanged net is refused before it plays\n";
$dir = queue([spec('g005'), spec('g006'), spec('g007')], [champion('g005', 0)]);
net($dir, 'pc-g005-train', 5);
trained($dir, 'g006', 5, ['bestEpoch' => 0]); // g005's weights, a new meta: g016's case
$p = run($dir, 'plan 64');
$r = rows($dir)['g006'] ?? null;
check(ids($p['jobs'], 'g006-gauntlet') === [], 'no gauntlet is planned for a net whose weights are its champion\'s');
check($r !== null && $r['elected'] === false && !empty($r['unchanged']), 'it is decided at once, not elected, and marked unchanged');
check($r !== null && $r['games'] === 0 && $r['score'] === null && $r['z'] === null, 'its row has no score: 0 games, score and z null, not an invented 0.5');
check($r !== null && str_contains($r['why'], 'champion g005'), 'the reason names the champion it copies: ' . ($r['why'] ?? '-'));
check(count(ids($p['jobs'], 'g007-label')) === 2, 'the next generation starts in the same pass');
exec('rm -rf ' . escapeshellarg($dir));

$dir = queue([spec('g005'), spec('g006')], [champion('g005', 0)]);
net($dir, 'pc-g005-train', 5);
trained($dir, 'g006', 6, ['bestEpoch' => 0]); // other weights, but the trainer says it kept its start
$p = run($dir, 'plan 64');
$r = rows($dir)['g006'] ?? null;
check($r !== null && $r['z'] === null && str_contains($r['why'], 'epoch 0'), 'a net the trainer exported at epoch 0 is refused too, by the trainer\'s word');
exec('rm -rf ' . escapeshellarg($dir));

$dir = queue([spec('g005'), spec('g006')], [champion('g005', 0)]);
net($dir, 'pc-g005-train', 5);
trained($dir, 'g006', 6, ['bestEpoch' => 4]);
$p = run($dir, 'plan 64');
check(count(ids($p['jobs'], 'g006-gauntlet')) === 4 && !isset(rows($dir)['g006']), 'a net that moved gets its four gauntlet jobs and no verdict yet');
games($dir, 'g006', 'gauntlet', 4, 10, 5, 5, 'gauntlet');
$p = run($dir, 'plan 64');
$r = rows($dir)['g006'] ?? null;
check($r !== null && $r['games'] === 40 && $r['z'] !== null && (float)$r['z'] === 0.0 && $r['elected'] === false && empty($r['unchanged']), 'and an even gauntlet decides it as before: 40 games, z 0, not elected');
exec('rm -rf ' . escapeshellarg($dir));

echo "\nA crown is confirmed by a second gauntlet\n";
$dir = queue([spec('g005'), spec('g006'), spec('g007')], [champion('g005', 0)]);
net($dir, 'pc-g005-train', 5);
trained($dir, 'g006', 6, ['bestEpoch' => 4]);
games($dir, 'g006', 'gauntlet', 4, 10, 7, 1, 'gauntlet'); // 0.80 over 40 games: far over the bar
$p = run($dir, 'plan 64');
check(!isset(rows($dir)['g006']), 'a first gauntlet over the bar elects nobody yet');
check(!is_file("$dir/parliament/champion.json"), 'and no champion file is written');
check(count(ids($p['jobs'], 'g006-confirm')) === 4, 'its second gauntlet is planned, as many jobs as the first');
check(ids($p['jobs'], 'g007') === [], 'the next generation waits for the verdict');
$s1 = seeds($p['jobs'], 'g006-confirm');
check($s1 && !array_intersect($s1, range(2000 + 501, 2000 + 504)), 'on seeds of its own (' . implode(',', $s1) . '), not the first gauntlet\'s 2501-2504');
$c = run($dir, 'collect')['text'];
check(str_contains($c, 'g006  over the bar at z') && str_contains($c, 'second is under way'), 'collect says the second is under way');
foreach (ids($p['jobs'], 'g006-confirm') as $id) {
    put("$dir/queue/$id.json", ['id' => $id, 'kind' => 'pc-gauntlet']); // as the keeper holds them
}
$p = run($dir, 'plan 64');
check(ids($p['jobs'], 'g006-confirm') === [], 'the second is planned once: the next pass finds its jobs queued');
array_map('unlink', glob("$dir/queue/*.json"));

$ok = $dir;
games($ok, 'g006', 'confirm', 4, 10, 6, 2, 'confirm'); // 0.70: holds
$p = run($ok, 'plan 64');
$r = rows($ok)['g006'] ?? null;
check($r !== null && $r['elected'] === true && str_contains($r['why'], 'confirmed'), 'a second gauntlet that holds elects it: ' . ($r['why'] ?? '-'));
check($r !== null && $r['games'] === 80 && isset($r['first'], $r['confirm']) && $r['first']['games'] === 40 && $r['confirm']['games'] === 40, 'the row pools 80 games and keeps each round\'s verdict');
check($r !== null && $r['politics']['elections'] === 80, 'the elections of both rounds are counted');
check(is_file("$ok/parliament/champion.json"), 'the champion file is written');
check(count(ids($p['jobs'], 'g007-label')) === 2, 'and the next generation starts, labelled by the new champion');
exec('rm -rf ' . escapeshellarg($ok));

$dir = queue([spec('g005'), spec('g006')], [champion('g005', 0)]);
net($dir, 'pc-g005-train', 5);
trained($dir, 'g006', 6, ['bestEpoch' => 4]);
games($dir, 'g006', 'gauntlet', 4, 10, 4, 1, 'gauntlet'); // 0.65 over 40, z about 2.3: a lucky-looking first
games($dir, 'g006', 'confirm', 4, 10, 2, 2, 'confirm');  // 0.50: the second says even
$p = run($dir, 'plan 64');
$r = rows($dir)['g006'] ?? null;
check($r !== null && $r['elected'] === false && str_contains($r['why'], 'not confirmed'), 'a second gauntlet at even refuses the crown: ' . ($r['why'] ?? '-'));
check(!is_file("$dir/parliament/champion.json"), 'and the champion stays');
check($r !== null && isset($r['first'], $r['confirm']) && $r['first']['z'] > 2 && (float)$r['confirm']['z'] === 0.0, 'the row shows a first round over the bar and a second at z 0');
check($r !== null && $r['z'] > 2, 'the 80 games pooled are over the bar (z ' . ($r['z'] ?? '-') . '): it is the second round\'s own floor that refuses it');
exec('rm -rf ' . escapeshellarg($dir));

$dir = queue([spec('g005'), spec('g006', ['bar' => ['confirm' => false]])], [champion('g005', 0)]);
net($dir, 'pc-g005-train', 5);
trained($dir, 'g006', 6, ['bestEpoch' => 4]);
games($dir, 'g006', 'gauntlet', 4, 10, 7, 1, 'gauntlet');
$p = run($dir, 'plan 64');
$r = rows($dir)['g006'] ?? null;
check($r !== null && $r['elected'] === true && !isset($r['confirm']), 'bar.confirm false: the first gauntlet decides alone, as before');
exec('rm -rf ' . escapeshellarg($dir));

echo "\nEvery tenth generation carries a control\n";
$dir = queue([spec('g009'), spec('g010')], [champion('g009', 0)]);
net($dir, 'pc-g009-train', 9);
$p = run($dir, 'plan 64');
$ctl = array_values(array_filter($p['jobs'] ?? [], fn($j) => str_contains($j['id'], 'g010-control')));
check(count($ctl) === 4 && count(ids($p['jobs'], 'g010-label')) === 2, 'g010\'s four control jobs are planned beside its labels');
check($ctl && str_contains($ctl[0]['cmd'], 'PC_NET2="$HOME/pc-data/g010/champion.json"') && str_contains($ctl[0]['cmd'], '--black "medium+net2:$HOME/pc-data/g010/champion.json"'),
    'a control plays the champion\'s net on both sides');
check($ctl && in_array('pc-data/g010/champion.json', array_column($ctl[0]['send'], 'dst'), true) && !in_array('pc-data/g010/net2.json', array_column($ctl[0]['send'], 'dst'), true),
    'it is sent the champion and no new net (there is none yet)');
check($ctl && $ctl[0]['stage'] === 'control' && $ctl[0]['kind'] === 'pc-gauntlet', 'its stage is control and its kind a gauntlet\'s, so the same lanes take it');
$sc = seeds($p['jobs'], 'g010-control');
check($sc && !array_intersect($sc, array_merge(range(2501, 2504), range(2601, 2604))), 'on seeds of its own (' . implode(',', $sc) . ')');
trained($dir, 'g010', 10, ['bestEpoch' => 4]);
games($dir, 'g010', 'gauntlet', 4, 10, 5, 5, 'gauntlet');
games($dir, 'g010', 'control', 3, 10, 6, 4, 'control');
failed($dir, 'pc-g010-control-004');
failed($dir, 'pc-g010-control-004-r1');
$p = run($dir, 'plan 64');
check(!isset(rows($dir)['g010']), 'a verdict waits while a control job is still to be retried');
check(ids($p['jobs'], 'control-004-r2') !== [], 'and the retry is planned');
failed($dir, 'pc-g010-control-004-r2');
$p = run($dir, 'plan 64');
$r = rows($dir)['g010'] ?? null;
check($r !== null && isset($r['control']) && $r['control']['games'] === 30 && $r['control']['of'] === 'g009', 'a control that lost a job for good is kept short (30 games) and the verdict comes: ' . json_encode($r['control'] ?? null));
check($r !== null && $r['games'] === 40 && (float)$r['z'] === 0.0, 'the control does not enter the generation\'s own score');
check($r !== null && ($r['laneSeconds']['control'] ?? 0) > 0, 'its lane time is counted apart');
exec('rm -rf ' . escapeshellarg($dir));

$dir = queue([spec('g010')]);
$p = run($dir, 'plan 64');
check(ids($p['jobs'], 'control') === [], 'no control before there is a champion to play itself');
exec('rm -rf ' . escapeshellarg($dir));

$dir = queue([spec('g009'), spec('g011')], [champion('g009', 0)]);
net($dir, 'pc-g009-train', 9);
$p = run($dir, 'plan 64');
check(ids($p['jobs'], 'control') === [], 'and none on a generation whose number is not a tenth');
exec('rm -rf ' . escapeshellarg($dir));

$dir = queue([spec('g009'), spec('g010', ['control' => ['every' => 0]])], [champion('g009', 0)]);
net($dir, 'pc-g009-train', 9);
$p = run($dir, 'plan 64');
check(ids($p['jobs'], 'control') === [], 'control.every 0 turns it off');
exec('rm -rf ' . escapeshellarg($dir));

echo "\n$passes passed, $fails failed\n";
exit($fails ? 1 : 0);
