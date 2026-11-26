<?php
declare(strict_types=1);

namespace Parliament\AI;

use Parliament\Engine\Game;
use Parliament\Engine\Position;

/**
 * The known space: every position the project has seen, with the moves played
 * from it and how those games ended, kept in a SQLite table WITHOUT ROWID —
 * that is, a clustered B-tree keyed by (Zobrist key, move). Opening theory
 * seeds it; every recorded game grows it.
 *
 * Search::choose plays from it while a position is known, and Search::negamax
 * blends known outcomes into its frontier, which pulls play back toward the tree.
 *
 * IT IS READ BY KEY, ONE POSITION AT A TIME. It used to be read whole into a PHP array when
 * opened. At 541 rows that was nothing; at the 153,774 rows of the fleet's 5,824 gauntlet games
 * the array took 224 MB and died against PHP's default 128 MB on its first line (the Known Tree
 * report, 26 November), and the server opens the book on every request that makes a bot. A
 * position's rows sit together under its key, three page reads deep at that size and four at
 * three hundred million rows, so a probe costs what it costs at any size. What has been read is
 * kept for the life of the object (bounded, below), so a search that meets the same position
 * twice asks once; misses are kept too, since most frontier positions are not in the book.
 */
final class KnownSpace
{
    private \PDO $db;
    private \PDOStatement $byKey;
    /** Positions read so far: key => ['n' => games, 's' => score for the side to move, 'moves' => [uci => [w, d, l, theory]]], or false when the book holds none. */
    private array $seen = [];
    /** How many positions $seen keeps before it starts again: about 8 MB of PHP arrays at the most. */
    private const SEEN_MAX = 40000;
    public int $minPlay = 3;   // games needed before a learned (non-theory) move is played from the book
    public int $maxBookPly = 30;

    public function __construct(string $path)
    {
        // The busy timeout comes first: several workers may open a fresh book at once, and the switch
        // to WAL needs an exclusive lock. Set after it (as it once was), a worker that lost the race
        // failed at once with "database is locked" instead of waiting its turn.
        $this->db = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_TIMEOUT => 15]);
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA busy_timeout=15000');
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS known (
            h INTEGER NOT NULL, mv TEXT NOT NULL, w INTEGER NOT NULL DEFAULT 0, d INTEGER NOT NULL DEFAULT 0,
            l INTEGER NOT NULL DEFAULT 0, theory INTEGER NOT NULL DEFAULT 0, ply INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (h, mv)) WITHOUT ROWID');
        // The primary key's own order: SQLite walks the B-tree to h and reads its rows, no table scan.
        $this->byKey = $this->db->prepare('SELECT mv, w, d, l, theory FROM known WHERE h = ?');
    }

    /** Forget what has been read, so the next probe sees the file as it is now (after another process learned). */
    public function load(): void
    {
        $this->seen = [];
    }

    /** One position's entry, read by its key, or null when the book does not hold it. */
    public function entry(int $h): ?array
    {
        if (array_key_exists($h, $this->seen)) {
            return $this->seen[$h] ?: null;
        }
        if (count($this->seen) >= self::SEEN_MAX) {
            $this->seen = [];
        }
        $this->byKey->execute([$h]);
        $moves = [];
        $n = $w = $d = 0;
        foreach ($this->byKey->fetchAll(\PDO::FETCH_NUM) as [$mv, $mw, $md, $ml, $theory]) {
            $moves[$mv] = [(int)$mw, (int)$md, (int)$ml, (int)$theory];
            $n += $mw + $md + $ml;
            $w += $mw;
            $d += $md;
        }
        if (!$moves) {
            $this->seen[$h] = false;
            return null;
        }
        return $this->seen[$h] = ['n' => $n, 's' => $n ? ($w + 0.5 * $d) / $n : 0.5, 'moves' => $moves];
    }

    /** How many positions the book holds (a walk of the whole key, so for statistics, not for play). */
    public function size(): int
    {
        return (int)$this->db->query('SELECT COUNT(DISTINCT h) FROM known')->fetchColumn();
    }

    /** Whether the book holds nothing yet: one page read, whatever its size. */
    public function isEmpty(): bool
    {
        return $this->db->query('SELECT 1 FROM known LIMIT 1')->fetchColumn() === false;
    }

    /** A book move for $p, or null. Theory moves are always playable; learned ones need $minPlay games. */
    public function pick(Position $p, \Random\Randomizer $rng): ?array
    {
        $e = $this->entry($p->hash());
        if ($e === null) {
            return null;
        }
        $legal = [];
        foreach ($p->legalMoves() as $m) {
            $legal[Game::uci($m)] = $m;
        }
        $cands = [];
        $total = 0.0;
        foreach ($e['moves'] as $uci => [$w, $d, $l, $theory]) {
            $n = $w + $d + $l;
            if (!isset($legal[$uci]) || (!$theory && $n < $this->minPlay)) {
                continue;
            }
            $score = ($w + 0.5 * $d + 1) / ($n + 2);
            if (!$theory && $score < 0.45) {
                continue; // a learned move that keeps losing is not "known good"
            }
            $weight = ($theory ? 4 : 1) * sqrt($n + 1) * $score * $score;
            $cands[] = [$uci, $weight, $score, $n];
            $total += $weight;
        }
        if (!$cands) {
            return null;
        }
        $r = $rng->nextFloat() * $total;
        foreach ($cands as [$uci, $weight, $score, $n]) {
            $r -= $weight;
            if ($r <= 0) {
                return ['move' => $legal[$uci], 'score' => (int)round(($score - 0.5) * 400), 'n' => $n];
            }
        }
        $last = end($cands);
        return ['move' => $legal[$last[0]], 'score' => 0, 'n' => $last[3]];
    }

    /** Blend a searched score with what is known about the position (side-to-move view). */
    public function blend(int $key, int $searched): int
    {
        if (abs($searched) > 5000) {
            return $searched; // a mate score is not blended, so the book is not asked
        }
        $e = $this->entry($key);
        if ($e === null || $e['n'] < 4) {
            return $searched;
        }
        $known = (int)round(($e['s'] - 0.5) * 600);
        $w = $e['n'] / ($e['n'] + 24);
        return (int)round((1 - $w) * $searched + $w * $known);
    }

    /** Add a line of theory (coordinate moves). Returns the number of plies that were legal. */
    public function addTheory(array $ucis, int $weight = 6): int
    {
        $g = new Game();
        $st = $this->db->prepare('INSERT INTO known (h, mv, w, d, l, theory, ply) VALUES (?, ?, ?, ?, ?, 1, ?)
            ON CONFLICT(h, mv) DO UPDATE SET theory = 1, w = w + excluded.w, d = d + excluded.d, l = l + excluded.l');
        foreach ($ucis as $i => $uci) {
            $m = $g->parse($uci);
            if ($m === null) {
                return $i;
            }
            // Theory is "known good" for both sides: mostly draws, a slight edge to the mover.
            $st->execute([$g->pos->hash(), $uci, $weight, 2 * $weight, $weight - 1, $i]);
            unset($this->seen[$g->pos->hash()]);
            $g->playInt($m);
        }
        return count($ucis);
    }

    /** Learn a finished game: each early position, the move played, and the result for the mover. */
    public function learn(Game $g): int
    {
        if ($g->status === 'playing') {
            return 0;
        }
        $replay = new Game($g->initialFen, $g->opt);
        $st = $this->db->prepare('INSERT INTO known (h, mv, w, d, l, ply) VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(h, mv) DO UPDATE SET w = w + excluded.w, d = d + excluded.d, l = l + excluded.l');
        $n = 0;
        $this->db->beginTransaction();
        foreach ($g->log as $i => $e) {
            if ($i >= $this->maxBookPly) {
                break;
            }
            $by = $e['by'];
            $res = $g->winner === null ? 0 : ($g->winner === $by ? 1 : -1);
            $st->execute([$replay->pos->hash(), $e['uci'], (int)($res === 1), (int)($res === 0), (int)($res === -1), $i]);
            unset($this->seen[$replay->pos->hash()]);
            $m = $replay->parse($e['uci']);
            if ($m === null) {
                break;
            }
            $replay->playInt($m);
            $n++;
            if ($replay->status !== 'playing') {
                break;
            }
        }
        $this->db->commit();
        return $n;
    }

    public function stats(): array
    {
        $r = $this->db->query('SELECT COUNT(DISTINCT h) AS positions, COUNT(*) AS edges, SUM(theory) AS theory, SUM(w + d + l) AS visits FROM known')->fetch(\PDO::FETCH_ASSOC);
        return array_map('intval', $r);
    }

    /** Opening theory the book starts with: well-known main lines, 10 to 16 plies each. */
    public static function theory(): array
    {
        return [
            'Ruy Lopez' => 'e2e4 e7e5 g1f3 b8c6 f1b5 a7a6 b5a4 g8f6 e1g1 f8e7 f1e1 b7b5 a4b3 d7d6 c2c3 e8g8',
            'Italian' => 'e2e4 e7e5 g1f3 b8c6 f1c4 f8c5 c2c3 g8f6 d2d3 d7d6 e1g1 e8g8',
            'Two Knights' => 'e2e4 e7e5 g1f3 b8c6 f1c4 g8f6 d2d3 f8e7 e1g1 e8g8',
            'Scotch' => 'e2e4 e7e5 g1f3 b8c6 d2d4 e5d4 f3d4 g8f6 d4c6 b7c6 e4e5 d8e7',
            'Petrov' => 'e2e4 e7e5 g1f3 g8f6 f3e5 d7d6 e5f3 f6e4 d2d4 d6d5 f1d3',
            'Four Knights' => 'e2e4 e7e5 g1f3 b8c6 b1c3 g8f6 f1b5 f8b4 e1g1 e8g8 d2d3 d7d6',
            'Vienna' => 'e2e4 e7e5 b1c3 g8f6 f2f4 d7d5 f4e5 f6e4 g1f3 f8e7',
            "King's Gambit" => 'e2e4 e7e5 f2f4 e5f4 g1f3 g7g5 h2h4 g5g4 f3e5 g8f6',
            'Sicilian Najdorf' => 'e2e4 c7c5 g1f3 d7d6 d2d4 c5d4 f3d4 g8f6 b1c3 a7a6 c1e3 e7e5 d4b3 c8e6',
            'Sicilian Dragon' => 'e2e4 c7c5 g1f3 d7d6 d2d4 c5d4 f3d4 g8f6 b1c3 g7g6 c1e3 f8g7 f2f3 e8g8',
            'Sicilian Taimanov' => 'e2e4 c7c5 g1f3 e7e6 d2d4 c5d4 f3d4 b8c6 b1c3 d8c7 c1e3 a7a6',
            'Sicilian Alapin' => 'e2e4 c7c5 c2c3 g8f6 e4e5 f6d5 d2d4 c5d4 g1f3 b8c6',
            'French Winawer' => 'e2e4 e7e6 d2d4 d7d5 b1c3 f8b4 e4e5 c7c5 a2a3 b4c3 b2c3 g8e7',
            'French Advance' => 'e2e4 e7e6 d2d4 d7d5 e4e5 c7c5 c2c3 b8c6 g1f3 d8b6',
            'Caro-Kann Classical' => 'e2e4 c7c6 d2d4 d7d5 b1c3 d5e4 c3e4 c8f5 e4g3 f5g6 h2h4 h7h6',
            'Caro-Kann Advance' => 'e2e4 c7c6 d2d4 d7d5 e4e5 c8f5 g1f3 e7e6 f1e2 c6c5',
            'Scandinavian' => 'e2e4 d7d5 e4d5 d8d5 b1c3 d5a5 d2d4 g8f6 g1f3 c8f5',
            'Pirc' => 'e2e4 d7d6 d2d4 g8f6 b1c3 g7g6 g1f3 f8g7 f1e2 e8g8 e1g1',
            "Queen's Gambit Declined" => 'd2d4 d7d5 c2c4 e7e6 b1c3 g8f6 c1g5 f8e7 e2e3 e8g8 g1f3 h7h6',
            "Queen's Gambit Accepted" => 'd2d4 d7d5 c2c4 d5c4 g1f3 g8f6 e2e3 e7e6 f1c4 c7c5 e1g1 a7a6',
            'Slav' => 'd2d4 d7d5 c2c4 c7c6 g1f3 g8f6 b1c3 d5c4 a2a4 c8f5 e2e3 e7e6',
            "King's Indian" => 'd2d4 g8f6 c2c4 g7g6 b1c3 f8g7 e2e4 d7d6 g1f3 e8g8 f1e2 e7e5 e1g1 b8c6',
            'Nimzo-Indian' => 'd2d4 g8f6 c2c4 e7e6 b1c3 f8b4 e2e3 e8g8 f1d3 d7d5 g1f3 c7c5',
            'Grunfeld' => 'd2d4 g8f6 c2c4 g7g6 b1c3 d7d5 c4d5 f6d5 e2e4 d5c3 b2c3 f8g7',
            "Queen's Indian" => 'd2d4 g8f6 c2c4 e7e6 g1f3 b7b6 g2g3 c8b7 f1g2 f8e7 e1g1 e8g8',
            'Catalan' => 'd2d4 g8f6 c2c4 e7e6 g2g3 d7d5 f1g2 f8e7 g1f3 e8g8 e1g1 d5c4',
            'London' => 'd2d4 d7d5 c1f4 g8f6 e2e3 c7c5 c2c3 b8c6 b1d2 e7e6 g1f3',
            'Dutch' => 'd2d4 f7f5 g2g3 g8f6 f1g2 e7e6 g1f3 f8e7 e1g1 e8g8 c2c4 d7d6',
            'English' => 'c2c4 e7e5 b1c3 g8f6 g1f3 b8c6 g2g3 d7d5 c4d5 f6d5 f1g2',
            'Reti' => 'g1f3 d7d5 g2g3 g8f6 f1g2 e7e6 e1g1 f8e7 d2d3 e8g8',
        ];
    }
}
