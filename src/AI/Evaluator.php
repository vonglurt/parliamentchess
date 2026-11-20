<?php
declare(strict_types=1);

namespace Parliament\AI;

use Parliament\Engine\Position;

/**
 * Static evaluation in centipawns, from the side to move's point of view.
 *
 * Every Parliament position is still a legal-looking chess position (one King a
 * side, ordinary piece types), so ordinary chess knowledge applies unchanged.
 * The election layer adds three small terms: Writs in hand, the projected value
 * of a pending count, and the Pretender's lower promotion ceiling.
 */
final class Evaluator
{
    public const VAL = [0, 100, 320, 330, 500, 900, 0];
    private const PAWN_ADV = [0, 0, 5, 10, 20, 35, 60, 0];
    private const PASSED = [0, 5, 12, 25, 45, 80, 130, 0];

    public ?Net $net = null;
    public float $netWeight = 0.0;
    /** The second-generation net: when set, it IS the evaluation (no blend with the hand-written terms). */
    public ?Net2 $net2 = null;
    /** false: the net only orders moves (its policy head); the hand-written evaluation scores them. */
    public bool $net2Eval = true;
    /** Personality knobs (see Styles). */
    public array $style = ['writValue' => 18, 'coronationBonus' => 0, 'files' => true];

    private static array $CEN = [];

    public function __construct(array $style = [])
    {
        $this->style = array_merge($this->style, $style);
        if (!self::$CEN) {
            for ($sq = 0; $sq < 64; $sq++) {
                $f = $sq & 7;
                $r = $sq >> 3;
                self::$CEN[$sq] = 3.0 - max(abs($f - 3.5), abs($r - 3.5)) + 0.5; // 0 at the rim .. 3 in the centre
            }
        }
    }

    public static function material(Position $p): int
    {
        $s = 0;
        foreach ($p->b as $v) {
            if ($v !== 0) {
                $s += $v > 0 ? self::VAL[$v] : -self::VAL[-$v];
            }
        }
        return $s;
    }

    /** Game phase: 1 = all pieces on, 0 = pawn ending. */
    public static function phase(Position $p): float
    {
        $npm = 0;
        foreach ($p->b as $v) {
            $t = abs($v);
            if ($t >= 2 && $t <= 5) {
                $npm += self::VAL[$t];
            }
        }
        return min(1.0, $npm / 6200);
    }

    public bool $tracing = false;
    public array $trace = [];

    public function evaluate(Position $p): int
    {
        // A pending election is scored as its count would come out now: exact, not a guess.
        if ($p->pending[1] || $p->pending[-1]) {
            $q = clone $p;
            foreach ([1, -1] as $c) {
                if ($q->pending[$c]) {
                    $q->countNow($c);
                }
            }
            return $this->evaluate($q);
        }
        if ($this->net2 !== null && $this->net2Eval && $this->net2->mode === 'value') {
            $v = max(-0.98, min(0.98, $this->net2->value($p)));
            return (int)round(400 * atanh($v));
        }
        $white = $this->white($p);
        if ($this->net2 !== null && $this->net2Eval) {
            // Residual: the hand-written evaluation, corrected by what the deeper teacher saw.
            $corr = max(-2.0, min(2.0, $this->net2->raw($p)));
            $white += (int)round(($p->side === 1 ? 400 : -400) * $corr);
        }
        if ($this->net !== null && $this->netWeight > 0) {
            $v = max(-0.9, min(0.9, $this->net->predict($p))); // at most ~590 cp from the net
            $white = (int)round((1 - $this->netWeight) * $white + $this->netWeight * 400 * atanh($v));
        }
        return $p->side === 1 ? $white : -$white;
    }

    /** The hand-written evaluation, White's point of view. */
    public function white(Position $p): int
    {
        $b = $p->b;
        $ph = self::phase($p);
        $eg = 1.0 - $ph;
        $score = 0.0;
        $mat = [1 => 0, -1 => 0];
        $bishops = [1 => 0, -1 => 0];
        $pawnFiles = [1 => array_fill(0, 8, 0), -1 => array_fill(0, 8, 0)];
        $pawns = [1 => [], -1 => []];
        for ($sq = 0; $sq < 64; $sq++) {
            $v = $b[$sq];
            if ($v === 1 || $v === -1) {
                $c = $v;
                $pawnFiles[$c][$sq & 7]++;
                $pawns[$c][] = $sq;
            }
        }
        for ($sq = 0; $sq < 64; $sq++) {
            $v = $b[$sq];
            if ($v === 0) {
                continue;
            }
            $c = $v > 0 ? 1 : -1;
            $t = $v * $c;
            $f = $sq & 7;
            $r = $sq >> 3;
            $rel = $c === 1 ? $r : 7 - $r;
            $cen = self::$CEN[$sq];
            $s = self::VAL[$t];
            $mat[$c] += self::VAL[$t];
            switch ($t) {
                case 1:
                    if ($rel === 7) {
                        $s = 40; // L5: stranded on the last rank; it only votes
                        break;
                    }
                    $pre = (($p->lf[$p->id[$sq]] ?? 0) & Position::L_PRETENDER) ? 0.6 : 1.0;
                    $s += self::PAWN_ADV[$rel] * (1 + $eg) * $pre;
                    if (($f === 3 || $f === 4) && $rel >= 3) {
                        $s += 10 * $ph;
                    }
                    if ($pawnFiles[$c][$f] > 1) {
                        $s -= 12;
                    }
                    $isolated = ($f === 0 || $pawnFiles[$c][$f - 1] === 0) && ($f === 7 || $pawnFiles[$c][$f + 1] === 0);
                    if ($isolated) {
                        $s -= 10;
                    }
                    if ($this->passed($sq, $c, $pawns[-$c])) {
                        $s += self::PASSED[$rel] * (1 + 2.0 * $eg) * $pre;
                    }
                    break;
                case 2:
                    $s += 8 * $cen - 12 + 2 * $this->mobility($b, $sq, $t, $c);
                    break;
                case 3:
                    $bishops[$c]++;
                    $s += 3 * $cen + 3 * $this->mobility($b, $sq, $t, $c);
                    break;
                case 4:
                    if ($rel === 6) {
                        $s += 20;
                    }
                    if ($pawnFiles[$c][$f] === 0) {
                        $s += $pawnFiles[-$c][$f] === 0 ? 20 : 10;
                    }
                    $s += 2 * $this->mobility($b, $sq, $t, $c);
                    break;
                case 5:
                    $s += 2 * $cen + 1 * $this->mobility($b, $sq, $t, $c);
                    break;
                case 6:
                    $s = $this->kingTerm($p, $sq, $c, $rel, $cen, $ph, $eg);
                    break;
            }
            $score += $c * $s;
            if ($this->tracing) {
                $this->trace[Position::sqName($sq)] = $c * round($s);
            }
        }
        if ($bishops[1] >= 2) {
            $score += 30;
        }
        if ($bishops[-1] >= 2) {
            $score -= 30;
        }
        // Mop-up: a winning side drives the lone King to the rim (the known road home).
        $diff = $mat[1] - $mat[-1];
        // A winning side that lets the fifty-move clock run is drifting: make progress (push, capture).
        if (abs($diff) >= 200 && $p->half > 8) {
            $score -= ($diff > 0 ? 1 : -1) * 3 * ($p->half - 8);
        }
        if ($eg > 0.6 && abs($diff) >= 300) {
            $c = $diff > 0 ? 1 : -1;
            $loser = $p->ksq[-$c];
            $score += $c * (12 * (3.5 - self::$CEN[$loser]) * 2 + 4 * (14 - $this->manhattan($p->ksq[$c], $loser)));
        }
        if ($p->opt['elections']) {
            $score += $this->politics($p, 1) - $this->politics($p, -1);
            $bonus = $this->coronationBonus();
            if ($bonus) {
                // Populists value every crown their side has passed (the Ledger remembers each reign).
                $reigned = [1 => -1, -1 => -1];
                foreach ($p->b as $sq => $v) {
                    if ($v !== 0 && (($p->lf[$p->id[$sq]] ?? 0) & Position::L_REIGNED)) {
                        $reigned[$v > 0 ? 1 : -1]++;
                    }
                }
                $score += $bonus * (min(2, $reigned[1]) - min(2, $reigned[-1]));
            }
        }
        return (int)round($score);
    }

    private function passed(int $sq, int $c, array $enemyPawns): bool
    {
        $f = $sq & 7;
        $r = $sq >> 3;
        foreach ($enemyPawns as $e) {
            $ef = $e & 7;
            if (abs($ef - $f) <= 1 && ($c === 1 ? ($e >> 3) > $r : ($e >> 3) < $r)) {
                return false;
            }
        }
        return true;
    }

    private function mobility(array $b, int $sq, int $t, int $c): int
    {
        $n = 0;
        if ($t === 2) {
            foreach (Position::$KN[$sq] as $to) {
                if ($b[$to] * $c <= 0) {
                    $n++;
                }
            }
            return $n - 4;
        }
        $d0 = $t === 3 ? 4 : 0;
        $d1 = $t === 4 ? 4 : 8;
        $rays = Position::$RAY[$sq];
        for ($d = $d0; $d < $d1; $d++) {
            foreach ($rays[$d] as $to) {
                $w = $b[$to];
                if ($w === 0) {
                    $n++;
                    continue;
                }
                if ($w * $c < 0) {
                    $n++;
                }
                break;
            }
        }
        return $n - ($t === 5 ? 12 : 6);
    }

    private function kingTerm(Position $p, int $sq, int $c, int $rel, float $cen, float $ph, float $eg): float
    {
        $s = $eg * 10 * $cen;
        // Middlegame: stay home behind a shield of pawns.
        $shield = 0;
        $f = $sq & 7;
        foreach ([1, 2] as $step) {
            $rr = ($sq >> 3) + $c * $step;
            if ($rr < 0 || $rr > 7) {
                continue;
            }
            for ($df = -1; $df <= 1; $df++) {
                $ff = $f + $df;
                if ($ff >= 0 && $ff < 8 && $p->b[$rr * 8 + $ff] === $c) {
                    $shield += $step === 1 ? 12 : 6;
                }
            }
        }
        $s += $ph * ($shield - 10 * $cen + ($rel === 0 ? 15 : -10 * $rel));
        $s -= $ph * $this->danger($p->b, $sq, $c);
        return $s;
    }

    /** Attacked squares around a King, weighted: the cost of standing there. */
    public function danger(array $b, int $sq, int $c): int
    {
        $d = Position::attacked($b, $sq, -$c) ? 30 : 0;
        foreach (Position::$KG[$sq] as $n) {
            if (Position::attacked($b, $n, -$c)) {
                $d += 12;
            }
        }
        return $d;
    }

    /** Writs in hand: each is an escape the King has not used yet. Spending one costs its value. */
    private function politics(Position $p, int $c): float
    {
        if (!($this->style['files'] ?? true)) {
            return 0.0;
        }
        return $this->style['writValue'] * $p->writs[$c] * ($p->full >= Position::PEACE_UNTIL - 4 ? 1.0 : 0.5);
    }

    /** Crowning a pawn is cheap; a populist also likes it for its own sake. */
    public function coronationBonus(): int
    {
        return (int)($this->style['coronationBonus'] ?? 0);
    }

    private function manhattan(int $a, int $b): int
    {
        return abs(($a & 7) - ($b & 7)) + abs(($a >> 3) - ($b >> 3));
    }
}
