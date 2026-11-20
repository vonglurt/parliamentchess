<?php
declare(strict_types=1);

namespace Parliament\AI;

use Parliament\Engine\Game;
use Parliament\Engine\Position;

/**
 * Book-Return Search (BRS): a medium-strength player that thinks only a few
 * plies ahead and leans on what is already known.
 *
 *  1. In the book (a SQLite B-tree of theory and recorded games) it plays a book move.
 *  2. Out of it, a shallow alpha-beta (depth 2 by default) with a capture-only
 *     quiescence search and one-ply check extensions.
 *  3. At every frontier node it asks the book again: a position that is known
 *     scores as a blend of the search and the recorded outcomes, so the search is
 *     pulled back toward the known tree instead of wandering into novelty.
 *
 * Every Count resolves inside Position::apply, so a Writ is seen exactly as it
 * plays: Writ, the reply, the count, then the evaluation.
 */
final class Search
{
    public const MATE = 100000;

    public Evaluator $ev;
    public ?KnownSpace $book;
    public int $depth;
    /** Centipawns of slack: moves this close to the best may be chosen at random (variety). */
    public int $margin;
    public int $nodes = 0;
    public int $maxNodes = 400000;
    public bool $files = true;
    private array $tt = [];
    private array $history = [];
    private \Random\Randomizer $rng;
    public array $lastInfo = [];
    /** @var array<int,int> every root move's exact score from the last search (action values, for labels) */
    public array $rootScores = [];

    public function __construct(Evaluator $ev, ?KnownSpace $book = null, int $depth = 2, int $margin = 12, ?int $seed = null)
    {
        $this->ev = $ev;
        $this->book = $book;
        $this->depth = $depth;
        $this->margin = $margin;
        $this->files = $ev->style['files'] ?? true;
        $this->rng = new \Random\Randomizer($seed === null ? new \Random\Engine\Secure() : new \Random\Engine\Mt19937($seed));
    }

    /** Pick a move for the side to move in $g. Returns ['move' => int, 'score' => int, 'source' => ..., ...]. */
    public function choose(Game $g): array
    {
        $p = $g->pos;
        $t0 = microtime(true);
        $this->nodes = 0;
        $this->tt = [];
        $this->history = $g->seen;
        if ($this->book) {
            $bm = $this->book->pick($p, $this->rng);
            if ($bm !== null) {
                return $this->lastInfo = ['move' => $bm['move'], 'score' => $bm['score'], 'source' => 'book',
                    'n' => $bm['n'], 'nodes' => 0, 'ms' => (int)((microtime(true) - $t0) * 1000)];
            }
        }
        $moves = $this->rootMoves($p);
        if (!$moves) {
            return $this->lastInfo = ['move' => 0, 'score' => 0, 'source' => 'none'];
        }
        $scores = [];
        for ($d = 1; $d <= $this->depth; $d++) {
            $scores = [];
            foreach ($moves as $m) {
                // Full window at the root: every score is exact, so the near-best pick compares true values.
                $c = $p->after($m);
                $ext = $c->inCheck() ? 1 : 0;
                $scores[$m] = -$this->negamax($c, $d - 1 + $ext, -self::MATE - 1, self::MATE + 1, 1);
            }
            arsort($scores);
            $moves = array_keys($scores);
            if ($this->nodes > $this->maxNodes) {
                break;
            }
        }
        $this->rootScores = $scores;
        $best = reset($scores);
        $bestMove = array_key_first($scores);
        if (abs($best) > 5000 || $this->margin === 0) {
            $pick = $bestMove; // a forced line (a mate either way) is played exactly
        } else {
            // Variety among near-equal moves, but a Writ is filed only when it is the best move outright.
            $near = array_keys(array_filter($scores, fn($s, $m) => $s >= $best - $this->margin
                && ($m === $bestMove || !(($m >> 16) & Position::F_WRIT)), ARRAY_FILTER_USE_BOTH));
            $pick = $near[$this->rng->getInt(0, count($near) - 1)];
        }
        return $this->lastInfo = ['move' => $pick, 'score' => $scores[$pick], 'best' => $best, 'source' => 'search',
            'depth' => $this->depth, 'nodes' => $this->nodes, 'ms' => (int)((microtime(true) - $t0) * 1000),
            'top' => array_slice(array_map(fn($m, $s) => [Game::uci($m), $s], array_keys($scores), $scores), 0, 5)];
    }

    private function rootMoves(Position $p): array
    {
        $out = [];
        foreach ($this->order($p, $p->moves(), 0) as $m) {
            if (!$this->files && (($m >> 16) & Position::F_WRIT)) {
                continue;
            }
            if ($p->isLegal($m)) {
                $out[] = $m;
            }
        }
        return $out;
    }

    private function negamax(Position $p, int $d, int $alpha, int $beta, int $ply): int
    {
        $this->nodes++;
        $key = $p->hash();
        if (($this->history[$key] ?? 0) >= 2 || $p->half >= 100) {
            return 0;
        }
        if ($d <= 0) {
            $q = $this->qsearch($p, $alpha, $beta, $ply, 0);
            return $this->book ? $this->book->blend($key, $q) : $q;
        }
        $tte = $this->tt[$key] ?? null;
        $ttMove = 0;
        if ($tte !== null) {
            [$td, $ts, $tf, $ttMove] = $tte;
            if ($td >= $d) {
                if ($tf === 0 || ($tf === 1 && $ts >= $beta) || ($tf === -1 && $ts <= $alpha)) {
                    return $ts;
                }
            }
        }
        $a0 = $alpha;
        $best = -self::MATE - 1;
        $bestMove = 0;
        $legal = 0;
        $this->history[$key] = ($this->history[$key] ?? 0) + 1;
        foreach ($this->order($p, $p->moves(), $ttMove, $this->ev->net2 !== null) as $m) {
            if (!$p->isLegal($m)) {
                continue;
            }
            if (!$this->files && ($ply & 1) === 0 && (($m >> 16) & Position::F_WRIT)) {
                continue; // a Monarchist never files; its opponent still may
            }
            $legal++;
            $c = $p->after($m);
            $ext = ($ply < 4 && $c->inCheck()) ? 1 : 0;
            $s = -$this->negamax($c, $d - 1 + $ext, -$beta, -$alpha, $ply + 1);
            if ($s > $best) {
                $best = $s;
                $bestMove = $m;
            }
            if ($s > $alpha) {
                $alpha = $s;
            }
            if ($alpha >= $beta) {
                break;
            }
        }
        $this->history[$key]--;
        if ($legal === 0) {
            return $p->inCheck() ? -self::MATE + $ply : 0;
        }
        $flag = $best <= $a0 ? -1 : ($best >= $beta ? 1 : 0);
        $this->tt[$key] = [$d, $best, $flag, $bestMove];
        return $best;
    }

    private function qsearch(Position $p, int $alpha, int $beta, int $ply, int $qd): int
    {
        $this->nodes++;
        $inCheck = $p->inCheck();
        if ($inCheck && $qd < 4) {
            // Evasions: every legal move, so a mate in the quiescence is still a mate.
            $best = -self::MATE + $ply;
            foreach ($this->order($p, $p->moves(), 0) as $m) {
                if (($m >> 16) & Position::F_WRIT || !$p->isLegal($m)) {
                    continue;
                }
                $s = -$this->qsearch($p->after($m), -$beta, -$alpha, $ply + 1, $qd + 1);
                if ($s > $best) {
                    $best = $s;
                }
                if ($s > $alpha) {
                    $alpha = $s;
                }
                if ($alpha >= $beta) {
                    break;
                }
            }
            return $best;
        }
        $stand = $this->ev->evaluate($p);
        if ($stand >= $beta || $qd >= 6) {
            return $stand;
        }
        $best = $stand;
        if ($stand > $alpha) {
            $alpha = $stand;
        }
        foreach ($this->order($p, $p->moves(true), 0) as $m) {
            // Delta pruning: a capture that cannot lift alpha is not worth a look.
            $victim = abs($p->b[($m >> 6) & 63]);
            if ($stand + Evaluator::VAL[$victim ?: 1] + 200 < $alpha && !(($m >> 12) & 7)) {
                continue;
            }
            if (!$p->isLegal($m)) {
                continue;
            }
            $s = -$this->qsearch($p->after($m), -$beta, -$alpha, $ply + 1, $qd + 1);
            if ($s >= $beta) {
                return $s;
            }
            if ($s > $best) {
                $best = $s;
            }
            if ($s > $alpha) {
                $alpha = $s;
            }
        }
        return $best; // fail-soft
    }

    /**
     * TT move, then captures by MVV-LVA, promotions, quiet moves, and Writs last.
     * With a policy head, quiet moves and Writs are ordered by the net's logits instead:
     * better ordering means earlier cutoffs, which is how a policy cuts the branching factor.
     */
    private function order(Position $p, array $moves, int $ttMove, bool $policy = false): array
    {
        $logit = [];
        if ($policy && count($moves) > 1) {
            $lg = $this->ev->net2->logits($p, $moves);
            foreach ($moves as $i => $m) {
                $logit[$m] = $lg[$i];
            }
        }
        $keyed = [];
        foreach ($moves as $m) {
            $fl = $m >> 16;
            if ($m === $ttMove) {
                $k = 100000;
            } elseif ($fl & Position::F_WRIT) {
                $k = $logit ? 4000 + 100 * $logit[$m] : -1000;
            } elseif ($fl & Position::F_CAPTURE) {
                $victim = abs($p->b[($m >> 6) & 63]) ?: 1;
                $k = 10000 + 10 * Evaluator::VAL[$victim] - Evaluator::VAL[abs($p->b[$m & 63])] / 10;
            } elseif (($m >> 12) & 7) {
                $k = 9000;
            } elseif ($logit) {
                $k = 4000 + 100 * $logit[$m];
            } else {
                $k = 0;
            }
            $keyed[] = [$k, $m];
        }
        usort($keyed, fn($a, $b) => $b[0] <=> $a[0]);
        return array_column($keyed, 1);
    }
}
