<?php
declare(strict_types=1);

/*
 * Two browsers playing each other through the server: every request a browser would send,
 * from two clients holding their own tokens, plus a spectator, a bot and two bots.
 *
 *   php bin/test-browsers.php                       starts a server of its own on a free port, with
 *                                                   its own data folder, and stops it at the end
 *   php bin/test-browsers.php --url http://127.0.0.1:8080   tests a server that is already up
 *
 * It covers: names and renaming, turn order and tokens, spectators, illegal moves, castling on both
 * sides, en passant, underpromotion, a Writ and its count, resignation, moves after the end, the
 * game record (players and times), PCN, a bot opponent named from the nut/legume/squash list,
 * bot against bot, the pages, old share links, and the docs folder's guard.
 */
require __DIR__ . '/../src/autoload.php';

use Parliament\Server\Names;

$o = getopt('', ['url:']);
$pass = $fail = 0;
function check(bool $ok, string $what): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  ok   " : "  FAIL ") . $what . "\n";
}

/** One HTTP request as a browser's fetch() would make it: [status, decoded JSON or body, headers]. */
function req(string $method, string $url, ?array $body = null): array
{
    $ctx = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60,
        'header' => "Content-Type: application/json\r\n", 'content' => $body === null ? '' : json_encode($body)]]);
    $text = @file_get_contents($url, false, $ctx);
    $headers = $http_response_header ?? [];
    preg_match('#HTTP/\S+ (\d+)#', $headers[0] ?? '', $m);
    $json = json_decode((string)$text, true);
    return [(int)($m[1] ?? 0), $json ?? $text, $headers];
}

// A server of our own, unless one is given.
$proc = null;
$tmp = null;
if (isset($o['url'])) {
    $base = rtrim($o['url'], '/');
} else {
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr((string)stream_socket_get_name($sock, false), strrpos((string)stream_socket_get_name($sock, false), ':') + 1);
    fclose($sock);
    $tmp = sys_get_temp_dir() . '/pc-browsers-' . getmypid();
    @mkdir($tmp, 0775, true);
    $env = array_merge(getenv(), ['PC_DATA_DIR' => $tmp, 'PHP_CLI_SERVER_WORKERS' => '3']);
    $proc = proc_open(['sh', pc_root() . '/bin/serve', (string)$port], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$tmp/server.log", 'w'],
        2 => ['file', "$tmp/server.log", 'a']], $pipes, pc_root(), $env);
    $base = "http://127.0.0.1:$port";
    for ($i = 0; $i < 50 && @file_get_contents("$base/") === false; $i++) {
        usleep(200000);
    }
    echo "a server of its own at $base (data in $tmp)\n";
}
$api = fn(string $r) => "$base/api/$r";
$names = array_merge(...array_map(fn($b) => [$b[0], $b[1]], Names::BOTS));

echo "\nThe pages\n";
[$s, $b] = req('GET', "$base/");
check($s === 200 && str_contains((string)$b, 'Parliament Chess'), 'the landing page');
[$s, $b] = req('GET', "$base/play.html");
check($s === 200 && str_contains((string)$b, 'app.js'), 'the game page');
[$s, , $h] = req('GET', "$base/?watch=abc123defg");
check($s === 302 && in_array('Location: /play.html?watch=abc123defg', $h, true), 'an old share link goes to /play.html');
[$s] = req('GET', "$base/docs/parliament-chess-lab-report.html");
check($s === 200, 'the lab report is served from docs/');
[$s] = req('GET', "$base/docs/%2e%2e/LICENSE");
check($s === 404, 'nothing outside docs/ is served');

echo "\nTwo browsers: castling both ways, en passant, resignation\n";
[$s, $a] = req('POST', $api('new'), ['mode' => 'pvp', 'name' => 'Atlantic Giant']);
check($s === 200 && $a['you'] === 1, 'browser A creates a game and plays White');
$id = $a['id'];
$tokA = $a['token'];
parse_str((string)parse_url($a['links']['opponent'], PHP_URL_QUERY), $q);
$tokB = $q['token'] ?? '';
[$s, $st] = req('GET', $api('state') . "?id=$id&token=$tokB");
check($s === 200 && $st['you'] === -1, 'browser B opens the opponent link and plays Black');
[$s, $st] = req('POST', $api('name'), ['id' => $id, 'token' => $tokB, 'name' => 'Big Max']);
check($s === 200 && $st['players']['-1'] === 'Big Max' && $st['players']['1'] === 'Atlantic Giant', 'B renames themself: Atlantic Giant vs Big Max');
[$s] = req('POST', $api('name'), ['id' => $id, 'token' => $tokB, 'name' => 'Atlantic Giant']);
check($s === 409, "B cannot take A's name");
[$s, $st] = req('GET', $api('state') . "?id=$id");
check($s === 200 && $st['you'] === 0 && !isset($st['actions']), 'a spectator sees the game and has no moves');
[$s] = req('POST', $api('move'), ['id' => $id, 'token' => $tokB, 'move' => 'e5']);
check($s === 409, 'Black cannot move first');
[$s] = req('POST', $api('move'), ['id' => $id, 'token' => '', 'move' => 'e4']);
check($s === 409, 'a spectator cannot move');
$line = ['e4', 'a6', 'e5', 'd5', 'exd6', 'cxd6', 'Nf3', 'Nc6', 'Be2', 'Bd7', 'O-O', 'Qc7', 'd3', 'O-O-O'];
$ok = true;
foreach ($line as $i => $mv) {
    [$s, $st] = req('POST', $api('move'), ['id' => $id, 'token' => $i % 2 ? $tokB : $tokA, 'move' => $mv]);
    $ok = $ok && $s === 200;
    if ($mv === 'exd6') {
        check($s === 200 && $st['frame']['board'][35] === 0 && $st['frame']['board'][43] === 1, 'en passant: exd6 takes the pawn on d5');
    }
}
check($ok && count($st['log']) === 14, 'fourteen moves, alternating between the two tokens');
$bd = $st['frame']['board'];
check($bd[6] === 6 && $bd[5] === 4 && $bd[58] === -6 && $bd[59] === -4, 'White castled short (Kg1, Rf1), Black long (Kc8, Rd8)');
[$s] = req('POST', $api('move'), ['id' => $id, 'token' => $tokA, 'move' => 'a1a8']);
check($s === 422, 'an illegal move is refused');
[$s, $rec] = req('GET', $api('record') . "?id=$id");
$times = array_column($rec['moves'], 'at');
$sorted = $times;
sort($sorted);
check($s === 200 && count($rec['moves']) === 14 && $rec['moves'][0]['player'] === 'Atlantic Giant' && $rec['moves'][1]['player'] === 'Big Max',
    'the record names the player of every move');
check($times === $sorted && (bool)preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $times[0]), 'and dates every move (ISO 8601, UTC, in order): ' . $times[0]);
[$s, $p] = req('GET', $api('pcn') . "?id=$id");
check(str_contains($p['pcn'], '[White "Atlantic Giant"]') && str_contains($p['pcn'], 'O-O-O') && str_contains($p['pcn'], 'exd6'), 'PCN carries the names and the moves');
[$s, $st] = req('POST', $api('resign'), ['id' => $id, 'token' => $tokA]);
check($s === 200 && $st['result'] === '0-1', 'White resigns: 0-1');
[$s] = req('POST', $api('move'), ['id' => $id, 'token' => $tokB, 'move' => 'Kb8']);
check($s === 409, 'no move after the end');

echo "\nTwo browsers: underpromotion\n";
[, $a] = req('POST', $api('new'), ['mode' => 'pvp', 'fen' => '8/P6k/8/8/8/8/8/K7 w - - 0 1']);
parse_str((string)parse_url($a['links']['opponent'], PHP_URL_QUERY), $q);
[$s, $st] = req('POST', $api('move'), ['id' => $a['id'], 'token' => $a['token'], 'move' => 'a7a8n']);
check($s === 200 && $st['frame']['board'][56] === 2, 'a7-a8=N: the pawn becomes a Knight');

echo "\nTwo browsers: a Writ and its count\n";
[, $a] = req('POST', $api('new'), ['mode' => 'pvp', 'fen' => '6k1/5ppp/8/r7/8/2PPP3/1N6/7K w - - 0 20', 'name' => 'Howden']);
parse_str((string)parse_url($a['links']['opponent'], PHP_URL_QUERY), $q);
[$s, $st] = req('POST', $api('move'), ['id' => $a['id'], 'token' => $a['token'], 'move' => 'W@d3']);
check($s === 200 && $st['frame']['pending'][0] === 19, 'White files a Writ for d3');
[$s, $st] = req('POST', $api('move'), ['id' => $a['id'], 'token' => $q['token'], 'move' => 'Rh5']);
check($s === 200 && ($st['log'][1]['count']['outcome'] ?? '') === 'coronation' && $st['frame']['ksq'][0] === 19,
    "Black's Rh5+ is answered by the count: Coronation, the King is now on d3");
[, $rec] = req('GET', $api('record') . "?id={$a['id']}");
check(str_contains((string)$rec['moves'][1]['count'], 'coronation'), 'the record keeps the count');

echo "\nOne browser against a bot\n";
[$s, $a] = req('POST', $api('new'), ['mode' => 'ai', 'level' => 'easy', 'color' => 'white', 'name' => 'Jarrahdale']);
[$s, $st] = req('POST', $api('move'), ['id' => $a['id'], 'token' => $a['token'], 'move' => 'e4']);
$bot = $st['players']['-1'];
check(in_array($bot, $names, true) && $st['bots']['-1'] === 'easy', "the bot is named from the list: $bot");
[$s, $r] = req('POST', $api('ai'), ['id' => $a['id'], 'version' => $st['version']]);
check($s === 200 && !empty($r['moved']), 'the bot replies: ' . ($r['moved'] ?? '?'));
[, $rec] = req('GET', $api('record') . "?id={$a['id']}");
check(($rec['moves'][1]['player'] ?? '') === $bot && $rec['moves'][1]['bot'] === 'easy', 'the record names the bot');
[$s] = req('POST', $api('name'), ['id' => $a['id'], 'token' => $a['token'], 'name' => 'Big Moose']);
check($s === 200, 'the person renames themself mid-game');

echo "\nTwo bots, one spectator\n";
[, $a] = req('POST', $api('new'), ['mode' => 'aivai', 'white' => 'easy', 'black' => 'easy/populist']);
[, $st] = req('GET', $api('state') . "?id={$a['id']}");
check(in_array($st['players']['1'], $names, true) && in_array($st['players']['-1'], $names, true) && $st['players']['1'] !== $st['players']['-1'],
    "two different bots: {$st['players']['1']} vs {$st['players']['-1']}");
$v = $st['version'];
for ($i = 0; $i < 4; $i++) {
    [, $r] = req('POST', $api('ai'), ['id' => $a['id'], 'version' => $v]);
    $v = $r['version'];
}
[, $st] = req('GET', $api('state') . "?id={$a['id']}");
check(count($st['log']) === 4, 'the spectator drives four bot moves');
[, $r] = req('POST', $api('ai'), ['id' => $a['id'], 'version' => 1]);
check(!empty($r['unchanged']), 'a stale request moves nothing (two spectators cannot double-move)');

check(($st['brains'] ?? null) === null && ($st['evals'] ?? []) === [], 'a game without a net carries no calculations');

echo "\nEach side's calculation\n";
if (is_dir(__DIR__ . '/../models') && glob(__DIR__ . '/../models/g[0-9][0-9][0-9].json')) {
    [, $a] = req('POST', $api('new'), ['mode' => 'ai', 'level' => 'medium+net2:champion', 'color' => 'white', 'name' => 'Jarrahdale']);
    [$s, $st] = req('POST', $api('move'), ['id' => $a['id'], 'token' => $a['token'], 'move' => 'e4']);
    $w = $st['brains']['1'] ?? [];
    check($s === 200 && ($w['kind'] ?? '') === 'reading' && ($w['source'] ?? '') === 'person' && ($w['chosen'] ?? '') === 'e2e4',
        'a person\'s move is read by the game\'s net, the move played marked');
    [, $r] = req('POST', $api('ai'), ['id' => $a['id'], 'version' => $st['version']]);
    [, $st] = req('GET', $api('state') . "?id={$a['id']}");
    check(($st['brains']['-1']['kind'] ?? '') === 'own' && ($st['brains']['1']['kind'] ?? '') === 'reading' && ($st['brain']['side'] ?? 0) === -1,
        'the net\'s reply is its own calculation; each side keeps its latest');
    check(($st['tally']['1']['moves'] ?? 0) === 1 && ($st['tally']['-1']['moves'] ?? 0) === 1 && count($st['evals']) === 2
        && $st['evals'][0][1] === 1 && $st['evals'][1][3] === 1, 'a tally per side, and the net\'s evaluation after every move');
    [$s] = req('POST', $api('move'), ['id' => $a['id'], 'token' => $a['token'], 'move' => 'e2e5']);
    check($s === 422, 'an illegal move is still refused');
} else {
    echo "  (skipped: no published net in models/)\n";
}

echo "\nUnknown games\n";
[$s] = req('POST', $api('move'), ['id' => 'nosuchgame1', 'token' => 'x', 'move' => 'e4']);
check($s === 404, 'a move for an unknown game is a 404');
if ($tmp) {
    check(!is_file("$tmp/games/nosuchgame1.json"), 'and leaves no file behind');
}

if ($proc) {
    $st = proc_get_status($proc);
    exec('pkill -TERM -P ' . (int)$st['pid'] . ' 2>/dev/null');
    proc_terminate($proc);
    proc_close($proc);
    exec('rm -rf ' . escapeshellarg($tmp));
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
