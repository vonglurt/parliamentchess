<?php
declare(strict_types=1);

namespace Parliament\Server;

use Parliament\AI\Players;
use Parliament\Engine\Game;
use Parliament\Engine\Position;
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
 *   GET  /api/nets       the published generations of the net (models/), the champion last
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
            'nets' => $this->nets(),
            default => throw new ApiError('no such route', 404),
        };
    }

    /** The generations published in models/, newest (the champion) last, with their gauntlet records. */
    private function nets(): array
    {
        $gens = [];
        foreach (json_decode((string)@file_get_contents($this->root . '/docs/data/generations.json'), true) ?: [] as $g) {
            $gens[$g['name'] ?? ''] = $g;
        }
        $files = glob($this->root . '/models/g[0-9][0-9][0-9].json') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $f) {
            $n = basename($f, '.json');
            $g = $gens[$n] ?? [];
            $out[] = ['name' => $n, 'score' => $g['score'] ?? null, 'z' => $g['z'] ?? null, 'labels' => $g['labels'] ?? null,
                'elections' => $g['politics']['elections'] ?? null, 'decided' => $g['decided'] ?? null];
        }
        return ['nets' => $out, 'champion' => $out ? end($out)['name'] : null];
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
                if (!Players::safeSpec((string)$spec)) {
                    throw new ApiError("no such player: $spec");
                }
                try {
                    Players::make($spec); // the named net must exist and load
                } catch (\Throwable $e) {
                    throw new ApiError("no such player: $spec");
                }
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
            'brain' => $rec['brain'] ?? null,
            'brains' => $rec['brains'] ?? null, 'tally' => $rec['tally'] ?? null, 'evals' => $rec['evals'] ?? [],
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
            $text = (string)($b['move'] ?? '');
            $m = $g->parse($text);
            if ($m === null) {
                throw new ApiError("illegal move: $text", 422);
            }
            $read = $this->reading($rec, $g, $m, $rec['players'][(string)$g->pos->side] ?? '');
            $g->playInt($m);
            if ($read) {
                $rec = $this->remember($rec, $read);
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
                $player = $rec['players'][(string)$g->pos->side] ?? '';
                $mind = $ai->ev->net2 !== null ? $this->brain($ai, $g, $spec, $player, $info)
                    : $this->reading($rec, $g, (int)$info['move'], $player, $ai, $info);
                if ($mind) {
                    $rec = $this->remember($rec, $mind);
                }
            }
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

    /**
     * What a net player saw and computed for the move it is about to play (see Net2::trace): the
     * brain panel draws it, from the active inputs through both hidden layers to the value and the
     * move logits, next to the search's own score for every root move and the move it chose.
     */
    private function brain(\Parliament\AI\Search $ai, Game $g, string $spec, string $player, array $info): array
    {
        return ['kind' => 'own', 'player' => $player, 'spec' => $spec, 'source' => $info['source'] ?? 'search',
            'score' => $info['score'] ?? 0, 'nodes' => $info['nodes'] ?? 0, 'searchMs' => $info['ms'] ?? 0]
            + $this->trace($ai, $g, (int)$info['move'], $ai);
    }

    /**
     * The other side's calculation, as the game's net reads it: a person's move, or a bot's that has
     * no net, is traced through the net player's own weights at the position the mover faced, with the
     * move actually played marked. The mover never saw these numbers; they show how the net would have
     * judged that side's position and how high it ranked the move chosen. A bot's own search scores
     * come along. Null when the game has no net player.
     */
    private function reading(array $rec, Game $g, int $move, string $player, ?\Parliament\AI\Search $own = null, array $info = []): ?array
    {
        $reader = null;
        foreach (['1', '-1'] as $s) {
            $spec = $rec['ai'][$s] ?? null;
            if ($spec && str_contains($spec, '+net2')) {
                $reader = $spec;
                break;
            }
        }
        if ($reader === null) {
            return null;
        }
        try {
            $net = Players::make($reader, null, 1);
        } catch (\Throwable) {
            return null;
        }
        if ($net->ev->net2 === null) {
            return null;
        }
        return ['kind' => 'reading', 'reader' => $reader, 'player' => $player, 'spec' => $rec['ai'][(string)$g->pos->side] ?? null,
            'source' => $own ? ($info['source'] ?? 'search') : 'person', 'score' => $own ? ($info['score'] ?? 0) : null,
            'nodes' => $info['nodes'] ?? 0, 'searchMs' => $info['ms'] ?? 0]
            + $this->trace($net, $g, $move, $own);
    }

    /** Net2::trace at the position before the move, with SAN, squares and search scores per move. */
    private function trace(\Parliament\AI\Search $net, Game $g, int $move, ?\Parliament\AI\Search $scorer): array
    {
        $p = $g->pos;
        $t0 = microtime(true);
        $moves = $p->legalMoves();
        $t = $net->ev->net2->trace($p, $moves, $move);
        foreach ($t['policy'] as &$row) {
            $m = $row['m'];
            $row += ['uci' => Game::uci($m), 'san' => Game::san($p, $m), 'from' => $m & 63, 'to' => ($m >> 6) & 63,
                'writ' => (($m >> 16) & Position::F_WRIT) !== 0, 'search' => $scorer ? ($scorer->rootScores[$m] ?? null) : null];
            unset($row['m']);
        }
        unset($row);
        $hand = (new \Parliament\AI\Evaluator($net->ev->style))->evaluate($p);
        return ['ply' => count($g->log) + 1, 'side' => $p->side, 'chosen' => Game::uci($move),
            'hand' => $hand, 'total' => $net->ev->evaluate($p), 'traceMs' => (int)round((microtime(true) - $t0) * 1000)] + $t;
    }

    /**
     * Keep a calculation: the latest of each side (brains), the latest overall (brain), each side's
     * running tally (how often it played the net's favourite, the rank and probability the net gave
     * its moves, the size of the net's correction) and the net's evaluation after every move, from
     * White's point of view (evals: ply, side, value, own 1 / reading 0, the rank and probability the
     * net gave the move played, the net's correction from White's side, who chose: net, person, bot, book).
     */
    private function remember(array $rec, array $b): array
    {
        $side = (string)$b['side'];
        $rec['brain'] = $b;
        $rec['brains'][$side] = $b;
        $t = $rec['tally'][$side] ?? ['moves' => 0, 'top1' => 0, 'top3' => 0, 'rankSum' => 0, 'pSum' => 0.0, 'corrSum' => 0, 'kind' => $b['kind']];
        $rank = array_search($b['chosen'], array_column($b['policy'], 'uci'), true);
        $rank = $rank === false ? count($b['policy']) : $rank + 1;
        $t['moves']++;
        $t['top1'] += $rank === 1 ? 1 : 0;
        $t['top3'] += $rank <= 3 ? 1 : 0;
        $t['rankSum'] += $rank;
        $t['pSum'] = round($t['pSum'] + ($b['policy'][$rank - 1]['p'] ?? 0), 4);
        $t['corrSum'] += $b['mode'] === 'residual' ? (int)round(abs(400 * max(-2.0, min(2.0, $b['raw'])))) : 0;
        $t['kind'] = $b['kind'];
        $rec['tally'][$side] = $t;
        // From White's side: the value, and the net's correction in centipawns; then who chose the move.
        $corr = $b['mode'] === 'residual' ? (int)round($b['side'] * 400 * max(-2.0, min(2.0, $b['raw']))) : 0;
        $who = $b['source'] === 'book' ? 'book' : ($b['kind'] === 'own' ? 'net' : ($b['source'] === 'person' ? 'person' : 'bot'));
        $rec['evals'][] = [$b['ply'], (int)$b['side'], round($b['side'] * $b['value'], 3), $b['kind'] === 'own' ? 1 : 0,
            $rank, round((float)($b['policy'][$rank - 1]['p'] ?? 0), 4), $corr, $who];
        return $rec;
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
