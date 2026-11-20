<?php
declare(strict_types=1);

namespace Parliament\AI;

use Parliament\Engine\Position;

/**
 * Sensors v2: the input of the second-generation net.
 *
 * Every feature is binary and seen from the side to move (the board is mirrored
 * for Black), so a position lights only ~100 of 1,203 inputs and the first layer
 * costs (active inputs x hidden) adds — the NNUE idea, cheap enough for PHP.
 *
 * Beyond where the pieces stand, the sensors report what one or two moves would
 * reveal, without searching for it:
 *   - attack maps for both sides, and which pieces hang (can be won next move);
 *   - pressure on each King's zone, check, mobility, the biggest threat;
 *   - the election: Writs, Term, whether filing now could crown, and whether the
 *     opponent's pending count would move their crown.
 *
 * Moves are indexed the same way for the policy head: from * 64 + to (mirrored),
 * and 4096 + square for a Writ.
 */
final class Sensors
{
    public const VERSION = 2;
    public const PS = 0;        // 768: piece-squares, us 0..383, them 384..767
    public const PRE = 768;     // 128: Pretender pawns, us / them
    public const ATT = 896;     // 128: squares we attack / they attack
    public const HANG = 1024;   // 128: our hanging pieces / theirs
    public const KZ = 1152;     // 10: pressure on our King's zone (5 buckets), on theirs (5)
    public const CHK = 1162;    // 1: we are in check
    public const MOB = 1163;    // 10: our mobility (5 buckets), theirs (5)
    public const WR = 1173;     // 8: our Writs 0..3, theirs 0..3
    public const EL = 1181;     // 6: we can file now, our pending, their pending, Peace over, our Term ready, theirs
    public const POLL = 1187;   // 4: filing now could give Coronation / Contested / Swap; or nothing
    public const PEND = 1191;   // 2: their pending count would move their crown; ours would
    public const THREAT = 1193; // 10: our biggest hanging value (5 buckets), theirs (5)
    public const INPUTS = 1203;
    public const MOVES = 4160;  // 64 x 64 + 64 Writs

    private const VAL = [0, 100, 320, 330, 500, 900, 0];

    /** @return int[] active feature indices */
    public static function features(Position $p): array
    {
        $b = $p->b;
        $s = $p->side;
        $mir = $s === 1 ? 0 : 56;
        $cnt = [1 => array_fill(0, 64, 0), -1 => array_fill(0, 64, 0)];
        $min = [1 => array_fill(0, 64, 99999), -1 => array_fill(0, 64, 99999)];
        $mob = [1 => 0, -1 => 0];
        $f = [];
        for ($sq = 0; $sq < 64; $sq++) {
            $v = $b[$sq];
            if ($v === 0) {
                continue;
            }
            $c = $v > 0 ? 1 : -1;
            $t = $v * $c;
            $ci = $c === $s ? 0 : 1;
            $rs = $sq ^ $mir;
            $f[] = $ci * 384 + ($t - 1) * 64 + $rs;
            if ($t === 1 && (($p->lf[$p->id[$sq]] ?? 0) & Position::L_PRETENDER)) {
                $f[] = self::PRE + $ci * 64 + $rs;
            }
            $val = self::VAL[$t] ?: 10000;
            foreach (self::targets($b, $sq, $t, $c) as $to) {
                $cnt[$c][$to]++;
                if ($val < $min[$c][$to]) {
                    $min[$c][$to] = $val;
                }
                if ($t !== 1 && $b[$to] * $c <= 0) {
                    $mob[$c]++;
                }
            }
        }
        $hang = [1 => 0, -1 => 0];
        for ($sq = 0; $sq < 64; $sq++) {
            $rs = $sq ^ $mir;
            if ($cnt[$s][$sq]) {
                $f[] = self::ATT + $rs;
            }
            if ($cnt[-$s][$sq]) {
                $f[] = self::ATT + 64 + $rs;
            }
            $v = $b[$sq];
            if ($v === 0) {
                continue;
            }
            $c = $v > 0 ? 1 : -1;
            $t = $v * $c;
            if ($t === 6 || !$cnt[-$c][$sq]) {
                continue;
            }
            if ($cnt[$c][$sq] === 0 || $min[-$c][$sq] < self::VAL[$t]) {
                $f[] = self::HANG + ($c === $s ? 0 : 64) + $rs;
                $hang[$c] = max($hang[$c], self::VAL[$t]);
            }
        }
        foreach ([1, -1] as $c) {
            $k = $p->ksq[$c];
            $pressure = $cnt[-$c][$k];
            foreach (Position::$KG[$k] as $z) {
                $pressure += $cnt[-$c][$z];
            }
            $bucket = $pressure >= 5 ? 4 : ($pressure >= 3 ? 3 : $pressure);
            $f[] = self::KZ + ($c === $s ? 0 : 5) + $bucket;
            $f[] = self::MOB + ($c === $s ? 0 : 5) + min(4, intdiv($mob[$c], 10));
            $h = $hang[$c];
            $f[] = self::THREAT + ($c === $s ? 0 : 5) + ($h === 0 ? 0 : ($h <= 100 ? 1 : ($h <= 330 ? 2 : ($h <= 500 ? 3 : 4))));
        }
        $inCheck = $cnt[-$s][$p->ksq[$s]] > 0;
        if ($inCheck) {
            $f[] = self::CHK;
        }
        $f[] = self::WR + min(3, $p->writs[$s]);
        $f[] = self::WR + 4 + min(3, $p->writs[-$s]);
        if (!$p->opt['elections']) {
            return $f;
        }
        $termUs = $p->full - $p->lastCount[$s] >= Position::TERM;
        $canWrit = $p->writs[$s] > 0 && $p->full >= Position::PEACE_UNTIL && $p->pending[$s] === 0 && $termUs && !$inCheck;
        if ($canWrit) {
            $f[] = self::EL;
        }
        if ($p->pending[$s]) {
            $f[] = self::EL + 1;
        }
        if ($p->pending[-$s]) {
            $f[] = self::EL + 2;
        }
        if ($p->full >= Position::PEACE_UNTIL) {
            $f[] = self::EL + 3;
        }
        if ($termUs) {
            $f[] = self::EL + 4;
        }
        if ($p->full - $p->lastCount[-$s] >= Position::TERM) {
            $f[] = self::EL + 5;
        }
        if ($canWrit) {
            $got = [];
            for ($sq = 0; $sq < 64; $sq++) {
                $v = $b[$sq];
                if ($v === 0 || ($v > 0) !== ($s > 0) || $cnt[-$s][$sq]) {
                    continue;
                }
                $t = $v * $s;
                if ($t === 6 || ($t === 5 && !$p->opt['queenEligible']) || (($p->lf[$p->id[$sq]] ?? 0) & Position::L_REIGNED)) {
                    continue;
                }
                $got[self::pollLite($p, $s, $sq, $cnt[-$s])] = true;
            }
            $any = false;
            foreach (['coronation' => 0, 'contested' => 1, 'swap' => 2] as $o => $i) {
                if (isset($got[$o])) {
                    $f[] = self::POLL + $i;
                    $any = true;
                }
            }
            if (!$any) {
                $f[] = self::POLL + 3;
            }
        }
        foreach ([[-$s, 0], [$s, 1]] as [$c, $slot]) {
            if ($p->pending[$c]) {
                $csq = array_search($p->pending[$c], $p->id, true);
                if ($csq !== false && !$cnt[-$c][$csq] && self::pollLite($p, $c, $csq, $cnt[-$c]) !== 'defeat') {
                    $f[] = self::PEND + $slot;
                }
            }
        }
        return $f;
    }

    /** The squares a piece attacks (pawns: diagonals only; sliders up to and including the first blocker). */
    private static function targets(array $b, int $sq, int $t, int $c): array
    {
        if ($t === 1) {
            $out = [];
            $f = $sq & 7;
            $to = $sq + 8 * $c;
            if ($to < 0 || $to > 63) {
                return [];
            }
            if ($f > 0) {
                $out[] = $to - 1;
            }
            if ($f < 7) {
                $out[] = $to + 1;
            }
            return $out;
        }
        if ($t === 2) {
            return Position::$KN[$sq];
        }
        if ($t === 6) {
            return Position::$KG[$sq];
        }
        $out = [];
        $d0 = $t === 3 ? 4 : 0;
        $d1 = $t === 4 ? 4 : 8;
        $rays = Position::$RAY[$sq];
        for ($d = $d0; $d < $d1; $d++) {
            foreach ($rays[$d] as $to) {
                $out[] = $to;
                if ($b[$to] !== 0) {
                    break;
                }
            }
        }
        return $out;
    }

    /** Position::poll, but reading intimidation from a precomputed attack-count map. */
    private static function pollLite(Position $p, int $c, int $cand, array $enemyCnt): string
    {
        $k = $p->ksq[$c];
        $commons = [0, 0];
        $college = [0, 0];
        $speaker = null;
        foreach ($p->b as $sq => $v) {
            if ($v === 0 || ($v > 0) !== ($c > 0) || $sq === $k || $sq === $cand) {
                continue;
            }
            $t = $v * $c;
            if ($t === 6 || $enemyCnt[$sq]) {
                continue;
            }
            $side = Position::cheb($sq, $cand) < Position::cheb($sq, $k) ? 0 : 1;
            if ($t === 1) {
                $commons[$side]++;
            } elseif ($t === 5) {
                $speaker = $side;
            } else {
                $college[$side] += Position::VOTES[$t];
            }
        }
        $cw = $college[0] > $college[1] ? 0 : ($college[0] < $college[1] ? 1 : ($speaker ?? 1));
        $pw = $commons[0] > $commons[1] ? 0 : 1;
        return $cw === 0 ? ($pw === 0 ? 'coronation' : 'contested') : ($pw === 0 ? 'swap' : 'defeat');
    }

    /** Policy index of a move, mirrored for Black like the features. */
    public static function moveIndex(Position $p, int $m): int
    {
        $mir = $p->side === 1 ? 0 : 56;
        $from = ($m & 63) ^ $mir;
        if (($m >> 16) & Position::F_WRIT) {
            return 4096 + $from;
        }
        return $from * 64 + ((($m >> 6) & 63) ^ $mir);
    }
}
