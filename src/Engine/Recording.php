<?php
declare(strict_types=1);

namespace Parliament\Engine;

use Parliament\AI\Evaluator;

/**
 * Recordings: one JSON document a game, self-contained and replayable
 * (format "parliament-chess-recording", version 1), plus the statistics the
 * lab reports and the Orrery census read from it.
 */
final class Recording
{
    public static function make(Game $g, array $meta = []): array
    {
        return ['format' => 'parliament-chess-recording', 'version' => 1,
            'id' => $meta['id'] ?? bin2hex(random_bytes(6)),
            'created' => date('c'), 'rules' => 'Parliament Chess (Vote King) 1.0'] + $meta
            + ['pcn' => $g->pcn(array_filter(['White' => $meta['white'] ?? null, 'Black' => $meta['black'] ?? null,
                'Date' => date('Y.m.d'), 'Annotator' => $meta['title'] ?? null])),
                'stats' => self::stats($g)] + $g->toArray();
    }

    public static function save(string $dir, array $rec): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = rtrim($dir, '/') . '/' . $rec['id'] . '.json';
        file_put_contents($path, json_encode($rec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents(substr($path, 0, -5) . '.pcn', $rec['pcn']);
        return $path;
    }

    public static function load(string $path): Game
    {
        return Game::fromArray(json_decode((string)file_get_contents($path), true));
    }

    /**
     * Per-game numbers: elections by side and outcome, length, and the comeback read —
     * who was behind on material at move 20, and how that side finished.
     */
    public static function stats(Game $g): array
    {
        $s = ['plies' => count($g->log), 'result' => $g->result(), 'termination' => $g->termination,
            'writs' => [1 => 0, -1 => 0], 'outcomes' => [], 'firstWritPly' => null, 'crownMoves' => 0,
            'pawnCoronations' => 0, 'trailing' => null, 'trailingScore' => null, 'deficit' => 0];
        $replay = new Game($g->initialFen, $g->opt);
        foreach ($g->log as $i => $e) {
            if ($e['writ']) {
                $s['writs'][$e['by']]++;
                $s['firstWritPly'] ??= $e['ply'];
            }
            if ($e['count']) {
                $o = $e['count']['outcome'];
                $s['outcomes'][$o] = ($s['outcomes'][$o] ?? 0) + 1;
                if (in_array($o, ['coronation', 'contested', 'swap'], true)) {
                    $s['crownMoves']++;
                }
                if (in_array($o, ['coronation', 'contested'], true) && ($e['count']['candType'] ?? 0) === 1) {
                    $s['pawnCoronations']++;
                }
            }
            $m = $replay->parse($e['uci']);
            if ($m === null) {
                break;
            }
            $replay->playInt($m);
            if ($e['ply'] === 40) {
                $mat = Evaluator::material($replay->pos);
                if (abs($mat) >= 200) {
                    $s['trailing'] = $mat > 0 ? -1 : 1;
                    $s['deficit'] = abs($mat);
                }
            }
            if ($replay->status !== 'playing') {
                break;
            }
        }
        if ($s['trailing'] !== null && $g->status !== 'playing') {
            $s['trailingScore'] = $g->winner === null ? 0.5 : ($g->winner === $s['trailing'] ? 1.0 : 0.0);
        }
        return $s;
    }

    /** Aggregate many games' stats into one census line. */
    public static function census(array $stats): array
    {
        $n = count($stats);
        $a = ['games' => $n, 'whiteWins' => 0, 'blackWins' => 0, 'draws' => 0, 'avgPlies' => 0.0,
            'writsPerGame' => 0.0, 'countsPerGame' => 0.0, 'crownMovesPerGame' => 0.0, 'outcomes' => [],
            'trailingGames' => 0, 'trailingScore' => 0.0, 'pawnCoronations' => 0, 'terminations' => []];
        if (!$n) {
            return $a;
        }
        $trail = [];
        foreach ($stats as $s) {
            $r = $s['result'];
            $a[$r === '1-0' ? 'whiteWins' : ($r === '0-1' ? 'blackWins' : 'draws')]++;
            $a['avgPlies'] += $s['plies'] / $n;
            $a['writsPerGame'] += (($s['writs'][1] ?? $s['writs']['1'] ?? 0) + ($s['writs'][-1] ?? $s['writs']['-1'] ?? 0)) / $n;
            $a['countsPerGame'] += array_sum($s['outcomes']) / $n;
            $a['crownMovesPerGame'] += $s['crownMoves'] / $n;
            $a['pawnCoronations'] += $s['pawnCoronations'];
            foreach ($s['outcomes'] as $o => $k) {
                $a['outcomes'][$o] = ($a['outcomes'][$o] ?? 0) + $k;
            }
            $a['terminations'][$s['termination']] = ($a['terminations'][$s['termination']] ?? 0) + 1;
            if ($s['trailingScore'] !== null) {
                $trail[] = $s['trailingScore'];
            }
        }
        $a['trailingGames'] = count($trail);
        $a['trailingScore'] = $trail ? array_sum($trail) / count($trail) : null;
        $a['decisive'] = ($a['whiteWins'] + $a['blackWins']) / $n;
        $a['whiteScore'] = ($a['whiteWins'] + 0.5 * $a['draws']) / $n;
        foreach (['avgPlies', 'writsPerGame', 'countsPerGame', 'crownMovesPerGame', 'decisive', 'whiteScore'] as $k) {
            $a[$k] = round($a[$k], 3);
        }
        if ($a['trailingScore'] !== null) {
            $a['trailingScore'] = round($a['trailingScore'], 3);
        }
        return $a;
    }
}
