<?php
declare(strict_types=1);

namespace Parliament\Server;

use Parliament\AI\Players;
use Parliament\Engine\Game;
use Parliament\Engine\Recording;

/**
 * The JSON API. Two browsers share a game by holding one token each; anyone
 * with the game id can watch. Every change bumps the game's version, which is
 * all a watching browser needs to poll for.
 *
 *   POST /api/new        {mode: pvp|ai|aivai, level, color, white, black, fen, elections, name}
 *   POST /api/name       {id, token, name}      a player renames themself
 *   GET  /api/record     ?id                    every move with its player and its date and time
 *   GET  /api/state      ?id&token&since
 *   POST /api/move       {id, token, move}
 *   POST /api/ai         {id, version}          the side to move is an AI: let it move
 *   POST /api/resign     {id, token}
 *   GET  /api/games      live and recent games
 *   GET  /api/recordings demo, census and live recordings
 *   GET  /api/replay     ?path                  every ply as a frame
 *   GET  /api/pcn        ?id                    the game as Parliament Chess Notation
 *   GET  /api/orrery     the Orrery Queue directories and their reports
 */
final class Api
{
    private Store $store;
    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? pc_root();
        $this->store = new Store(pc_data() . '/games');
    }

    public function handle(string $method, string $route, array $q, array $body): array
    {
        return match ($route) {
            'new' => $this->create($body),
            'state' => $this->state((string)($q['id'] ?? ''), (string)($q['token'] ?? ''), (int)($q['since'] ?? -1)),
            'move' => $this->move($body),
            'ai' => $this->aiMove($body),
            'resign' => $this->resign($body),
            'games' => ['games' => $this->store->all()],
            'recordings' => $this->recordings(),
            'replay' => $this->replay((string)($q['path'] ?? '')),
            'pcn' => ['pcn' => $this->pcn($this->load((string)($q['id'] ?? '')))],
            'record' => $this->record($this->load((string)($q['id'] ?? ''))),
            'name' => $this->rename($body),
            'names' => ['bots' => array_map(fn($b) => ['common' => $b[0], 'latin' => $b[1]], Names::BOTS), 'people' => Names::PEOPLE],
            'orrery' => $this->orrery(),
            default => throw new ApiError('no such route', 404),
        };
    }

    private function load(string $id): array
    {
        return $this->store->read($id) ?? throw new ApiError('no such game', 404);
    }

    private function create(array $b): array
    {
        $mode = $b['mode'] ?? 'pvp';
        $level = $b['level'] ?? 'medium';
        $color = ($b['color'] ?? 'white') === 'black' ? -1 : 1;
        $ai = [1 => null, -1 => null];
        if ($mode === 'ai') {
            $ai[-$color] = $level;
        } elseif ($mode === 'aivai') {
            $ai[1] = $b['white'] ?? 'medium';
            $ai[-1] = $b['black'] ?? 'medium/populist';
        } elseif ($mode !== 'pvp') {
            throw new ApiError('mode must be pvp, ai or aivai');
        }
        foreach ($ai as $spec) {
            if ($spec !== null) {
                Players::make($spec); // validates the name
            }
        }
        $opt = ['elections' => ($b['elections'] ?? true) !== false, 'queenEligible' => !empty($b['queenEligible'])];
        $g = new Game(!empty($b['fen']) ? (string)$b['fen'] : null, $opt);
        $id = Store::newId();
        $tokens = ['1' => Store::newId() . Store::newId(), '-1' => Store::newId() . Store::newId()];
        // Names: the creator's own (or a pumpkin), a pumpkin for an opponent not yet arrived,
        // and a nut, a legume or a squash for every bot.
        $names = [];
        foreach ([1, -1] as $c) {
            if ($ai[$c] !== null) {
                $names[(string)$c] = Names::bot(array_values($names));
            } elseif ($mode !== 'aivai' && $c === $color) {
                $names[(string)$c] = Names::clean($b['name'] ?? null) ?? Names::person();
            } else {
                do {
                    $n = Names::person();
                } while (in_array($n, $names, true));
                $names[(string)$c] = $n;
            }
        }
        $rec = ['id' => $id, 'created' => date('c'), 'mode' => $mode, 'version' => 1,
            'players' => $names,
            'ai' => ['1' => $ai[1], '-1' => $ai[-1]], 'tokens' => $tokens, 'game' => $g->toArray()];
        $this->store->update($id, fn() => $rec, true);
        $you = $mode === 'aivai' ? 0 : $color;
        return ['id' => $id, 'you' => $you, 'token' => $you ? $tokens[(string)$you] : '',
            'links' => $this->links($rec, $you)];
    }

    private function links(array $rec, int $you): array
    {
        $id = $rec['id'];
        $l = ['watch' => "/play.html?watch=$id"];
        if ($rec['mode'] === 'pvp' && $you !== 0) {
            $l['opponent'] = "/play.html?game=$id&token=" . $rec['tokens'][(string)-$you];
        }
        return $l;
    }

    private function whoIs(array $rec, string $token): int
    {
        foreach ($rec['tokens'] as $c => $t) {
            if ($token !== '' && hash_equals($t, $token)) {
                return (int)$c;
            }
        }
        return 0;
    }

    private function state(string $id, string $token, int $since): array
    {
        $rec = $this->load($id);
        if ($since === $rec['version']) {
            return ['unchanged' => true, 'version' => $rec['version']];
        }
        return $this->view($rec, $this->whoIs($rec, $token));
    }

    private function view(array $rec, int $you): array
    {
        $g = Game::fromSnapshot($rec['game']);
        $p = $g->pos;
        $playing = $g->status === 'playing';
        $mover = $p->side;
        $out = [
            'id' => $rec['id'], 'version' => $rec['version'], 'mode' => $rec['mode'], 'you' => $you,
            'players' => $rec['players'], 'bots' => ['1' => $rec['ai']['1'] ?? null, '-1' => $rec['ai']['-1'] ?? null],
            'status' => $g->status, 'result' => $g->result(),
            'termination' => $g->termination, 'winner' => $g->winner, 'started' => self::iso($g->startedAt),
            'frame' => View::frame($p),
            'aiToMove' => $playing && !empty($rec['ai'][(string)$mover]),
            'log' => array_map(fn($e) => ['ply' => $e['ply'], 'by' => $e['by'], 'san' => $e['san'], 'writ' => $e['writ'],
                'count' => $e['count'] ? array_diff_key($e['count'], ['voters' => 1]) + ['text' => Game::describeCount($e['count'])] : null,
                'source' => $e['source'] ?? null, 'score' => $e['score'] ?? null,
                'player' => $rec['players'][(string)$e['by']] ?? '', 'at' => self::iso($g->startedAt + $e['t'] / 1000)], $g->log),
            'links' => $this->links($rec, $you),
            'careers' => $this->notable($g),
        ];
        if ($playing && $you === $mover && empty($rec['ai'][(string)$mover])) {
            $out['actions'] = View::actions($p);
        }
        return $out;
    }

    /** A moment as ISO 8601 in UTC with milliseconds: 2026-11-20T13:45:01.250Z. */
    public static function iso(float $t): string
    {
        $d = \DateTime::createFromFormat('U.u', sprintf('%.6F', $t), new \DateTimeZone('UTC'));
        return $d ? $d->format('Y-m-d\TH:i:s.v\Z') : gmdate('Y-m-d\TH:i:s\Z', (int)$t);
    }

    /** The game's record: every move with its number, side, player, notation and date and time. */
    private function record(array $rec): array
    {
        $g = Game::fromSnapshot($rec['game']);
        $moves = [];
        foreach ($g->log as $e) {
            $moves[] = ['ply' => $e['ply'], 'move' => intdiv($e['ply'] + 1, 2) + (int)explode(' ', $g->initialFen)[5] - 1,
                'side' => $e['by'] === 1 ? 'White' : 'Black', 'player' => $rec['players'][(string)$e['by']] ?? '',
                'bot' => $rec['ai'][(string)$e['by']] ?? null, 'san' => $e['san'], 'uci' => $e['uci'],
                'at' => self::iso($g->startedAt + $e['t'] / 1000),
                'count' => $e['count'] ? Game::describeCount($e['count']) : null];
        }
        return ['id' => $rec['id'], 'mode' => $rec['mode'], 'white' => $rec['players']['1'] ?? '', 'black' => $rec['players']['-1'] ?? '',
            'started' => self::iso($g->startedAt), 'status' => $g->status, 'result' => $g->result(),
            'termination' => $g->termination, 'moves' => $moves];
    }

    private function pcn(array $rec): string
    {
        $g = Game::fromSnapshot($rec['game']);
        $start = self::iso($g->startedAt);
        return $g->pcn(['White' => $rec['players']['1'] ?? '?', 'Black' => $rec['players']['-1'] ?? '?',
            'Date' => str_replace('-', '.', substr($start, 0, 10)), 'UTCDate' => str_replace('-', '.', substr($start, 0, 10)),
            'UTCTime' => substr($start, 11, 8)]);
    }

    private function rename(array $b): array
    {
        $res = null;
        $this->store->update((string)($b['id'] ?? ''), function (?array $rec) use ($b, &$res) {
            $you = $rec ? $this->whoIs($rec, (string)($b['token'] ?? '')) : 0;
            $name = Names::clean($b['name'] ?? null);
            if (!$you || !empty($rec['ai'][(string)$you])) {
                throw new ApiError('not a player', 403);
            }
            if ($name === null) {
                throw new ApiError('a name needs a letter or a digit', 422);
            }
            if ($name === ($rec['players'][(string)-$you] ?? null)) {
                throw new ApiError('your opponent already has that name', 409);
            }
            $rec['players'][(string)$you] = $name;
            $rec['version']++;
            $res = $this->view($rec, $you);
            return $rec;
        });
        return $res;
    }

    /** Pieces whose careers include a crown, a fall or a promotion. */
    private function notable(Game $g): array
    {
        $out = [];
        foreach ($g->careers as $id => $events) {
            foreach ($events as $e) {
                if (in_array($e['what'], ['crowned', 'dethroned', 'promoted', 'swapped with the King'], true)) {
                    $out[] = ['piece' => $id, 'born' => 'PNBRQK'[($g->pos->born[$id] ?? 1) - 1], 'color' => $id <= 16 ? 1 : -1] + $e;
                }
            }
        }
        return $out;
    }

    private function move(array $b): array
    {
        $id = (string)($b['id'] ?? '');
        $res = null;
        $this->store->update($id, function (?array $rec) use ($b, &$res) {
            if (!$rec) {
                throw new ApiError('no such game', 404);
            }
            $you = $this->whoIs($rec, (string)($b['token'] ?? ''));
            $g = Game::fromSnapshot($rec['game']);
            if ($g->status !== 'playing') {
                throw new ApiError('the game is over', 409);
            }
            if ($you === 0 || $you !== $g->pos->side || !empty($rec['ai'][(string)$you])) {
                throw new ApiError('not your move', 409);
            }
            try {
                $g->play((string)($b['move'] ?? ''));
            } catch (\InvalidArgumentException $e) {
                throw new ApiError($e->getMessage(), 422);
            }
            $rec = $this->commit($rec, $g);
            $res = $this->view($rec, $you);
            return $rec;
        });
        return $res;
    }

    private function aiMove(array $b): array
    {
        $id = (string)($b['id'] ?? '');
        $res = null;
        $this->store->update($id, function (?array $rec) use ($b, &$res) {
            if (!$rec) {
                throw new ApiError('no such game', 404);
            }
            $g = Game::fromSnapshot($rec['game']);
            $spec = $rec['ai'][(string)$g->pos->side] ?? null;
            if ($g->status !== 'playing' || !$spec || (isset($b['version']) && (int)$b['version'] !== $rec['version'])) {
                $res = ['unchanged' => true, 'version' => $rec['version']];
                return null;
            }
            $ai = Players::make($spec, Players::book(), random_int(1, PHP_INT_MAX));
            $info = $ai->choose($g);
            if ($info['move']) {
                $g->playInt($info['move'], null, ['source' => $info['source'], 'score' => $info['score'] ?? 0,
                    'nodes' => $info['nodes'] ?? 0, 'ms' => $info['ms'] ?? 0]);
            }
            $rec = $this->commit($rec, $g);
            $res = ['moved' => Game::uci($info['move']), 'info' => array_diff_key($info, ['move' => 1]), 'version' => $rec['version']];
            return $rec;
        });
        return $res;
    }

    private function resign(array $b): array
    {
        $res = null;
        $this->store->update((string)($b['id'] ?? ''), function (?array $rec) use ($b, &$res) {
            $you = $rec ? $this->whoIs($rec, (string)($b['token'] ?? '')) : 0;
            if (!$you) {
                throw new ApiError('not a player', 403);
            }
            $g = Game::fromSnapshot($rec['game']);
            if ($g->status === 'playing') {
                $g->resign($you);
            }
            $rec = $this->commit($rec, $g);
            $res = $this->view($rec, $you);
            return $rec;
        });
        return $res;
    }

    /** Save the game back; a finished game becomes a recording and teaches the known space. */
    private function commit(array $rec, Game $g): array
    {
        $was = $rec['game']['status'];
        $rec['game'] = $g->toArray();
        $rec['version']++;
        if ($was === 'playing' && $g->status !== 'playing') {
            $r = Recording::make($g, ['id' => 'live-' . $rec['id'], 'title' => $rec['players']['1'] . ' vs ' . $rec['players']['-1'],
                'white' => $rec['players']['1'], 'black' => $rec['players']['-1'], 'mode' => $rec['mode'],
                'bots' => $rec['ai'], 'started' => self::iso($g->startedAt), 'record' => $this->record($rec)]);
            Recording::save(pc_data() . '/recordings/live', $r);
            if (count($g->log) >= 6) {
                Players::book()->learn($g);
            }
        }
        return $rec;
    }

    // ------------------------------------------------------------------
    // Recordings and the Orrery
    // ------------------------------------------------------------------

    private function recordings(): array
    {
        $out = [];
        foreach (glob($this->root . '/orrery/*/report.json') ?: [] as $rf) {
            $queue = basename(dirname($rf));
            $rep = json_decode((string)file_get_contents($rf), true);
            foreach ($rep['recordings'] ?? [] as $r) {
                $rel = $this->relative($r['file']);
                if ($rel === null) {
                    continue;
                }
                $out[] = ['queue' => $queue, 'run' => $r['run'], 'path' => $rel, 'result' => $r['result'],
                    'plies' => $r['plies'], 'counts' => $r['counts'], 'crownMoves' => $r['crownMoves']];
            }
        }
        foreach (glob(pc_data() . '/recordings/*/*.json') ?: [] as $f) {
            if (str_ends_with($f, 'summary.json')) {
                continue;
            }
            $r = json_decode((string)file_get_contents($f), true);
            if (($r['format'] ?? '') !== 'parliament-chess-recording') {
                continue;
            }
            $out[] = ['queue' => basename(dirname($f)), 'run' => $r['title'] ?? $r['id'], 'path' => $this->relative($f),
                'result' => $r['result'], 'plies' => count($r['log']), 'counts' => array_sum($r['stats']['outcomes'] ?? []),
                'crownMoves' => $r['stats']['crownMoves'] ?? 0];
        }
        return ['recordings' => $out];
    }

    private function relative(string $f): ?string
    {
        $real = realpath($f);
        $root = realpath($this->root);
        if ($real === false || !str_starts_with($real, $root . '/')) {
            return null;
        }
        return substr($real, strlen($root) + 1);
    }

    private function replay(string $path): array
    {
        $real = realpath($this->root . '/' . $path);
        $root = realpath($this->root);
        if ($real === false || !str_ends_with($real, '.json')
            || !(str_starts_with($real, "$root/data/recordings/") || str_starts_with($real, "$root/orrery/"))) {
            throw new ApiError('no such recording', 404);
        }
        $rec = json_decode((string)file_get_contents($real), true);
        $g = Game::fromArray($rec);
        $log = array_map(fn($e) => ['ply' => $e['ply'], 'by' => $e['by'], 'san' => $e['san'], 'writ' => $e['writ'],
            'count' => $e['count'] ? array_diff_key($e['count'], ['voters' => 1]) + ['text' => Game::describeCount($e['count'])] : null],
            $g->log);
        return ['meta' => array_intersect_key($rec, array_flip(['id', 'title', 'white', 'black', 'result', 'termination', 'created', 'stats', 'pcn'])),
            'frames' => View::replay($g), 'log' => $log];
    }

    private function orrery(): array
    {
        $out = [];
        foreach (glob($this->root . '/orrery/*', GLOB_ONLYDIR) ?: [] as $d) {
            $plan = json_decode((string)@file_get_contents("$d/plan.json"), true) ?: [];
            $count = fn(string $g) => count(glob("$d/$g") ?: []);
            $lanes = [];
            foreach (glob("$d/lanes/*.json") ?: [] as $lf) {
                $lanes[] = json_decode((string)file_get_contents($lf), true);
            }
            $events = [];
            if (is_file("$d/events.jsonl")) {
                $lines = array_slice(file("$d/events.jsonl", FILE_IGNORE_NEW_LINES), -40);
                $events = array_map(fn($l) => json_decode($l, true), $lines);
            }
            $jobs = [];
            foreach (['queue', 'done', 'failed'] as $state) {
                foreach (glob("$d/$state/*.json") ?: [] as $jf) {
                    $j = json_decode((string)file_get_contents($jf), true);
                    $jobs[] = ['id' => $j['id'], 'state' => $state, 'run' => $j['run'] ?? '', 'seconds' => $j['seconds'] ?? null];
                }
            }
            foreach (glob("$d/running/*/*.json") ?: [] as $jf) {
                $j = json_decode((string)file_get_contents($jf), true);
                $jobs[] = ['id' => $j['id'], 'state' => 'running', 'run' => $j['run'] ?? '', 'seconds' => null];
            }
            $report = json_decode((string)@file_get_contents("$d/report.json"), true) ?: [];
            $out[] = ['name' => basename($d), 'about' => $plan['about'] ?? '', 'runs' => array_column($plan['runs'] ?? [], 'name'),
                'counts' => ['queue' => $count('queue/*.json'), 'running' => $count('running/*/*.json'),
                    'done' => $count('done/*.json'), 'failed' => $count('failed/*.json')],
                'lanes' => $lanes, 'events' => $events, 'jobs' => $jobs, 'report' => $report['runs'] ?? []];
        }
        return ['queues' => $out];
    }
}
