<?php
declare(strict_types=1);

namespace Parliament\Engine;

/**
 * One Parliament Chess position and every rule of the game.
 *
 * Squares run a1 = 0 ... h8 = 63. A piece is +type for White, -type for Black,
 * with P=1 N=2 B=3 R=4 Q=5 K=6. Every piece also has a permanent identity
 * ($id[sq]) and a career record in the Ledger ($lf[id]), so social mobility
 * (a pawn crowned, a King demoted) is tracked across the whole game.
 *
 * A move is one int: from | to << 6 | promo << 12 | flags << 16.
 * A Writ (the election call) is from = to = the Candidate's square, flag F_WRIT.
 *
 * The rules are the ones in RULES.md, Lab Report I, sections C to G.
 */
final class Position
{
    public const WHITE = 1;
    public const BLACK = -1;
    public const P = 1, N = 2, B = 3, R = 4, Q = 5, K = 6;

    public const F_CAPTURE = 1, F_DOUBLE = 2, F_EP = 4, F_CASTLE = 8, F_PROMO = 16, F_WRIT = 32;

    /** Ledger flags, per piece identity. */
    public const L_REIGNED = 1, L_PRETENDER = 2, L_WAS_QUEEN = 4;

    /** Rule constants (RULES.md section F). */
    public const PEACE_UNTIL = 10;  // Coronation Peace: no Writ before this full move
    public const TERM = 5;          // full moves from your last count to your next Writ (6 turns between counts)
    public const WRIT_CAP = 3;
    public const WRIT_START = 1;
    /** Votes by piece type: pawns are the Commons (1), N and B 2, R 3 in the College. */
    public const VOTES = [0, 1, 2, 2, 3, 0, 0];

    public const OUT_CORONATION = 'coronation';
    public const OUT_CONTESTED = 'contested';
    public const OUT_SWAP = 'swap';
    public const OUT_DEFEAT = 'defeat';
    public const OUT_FAILED = 'failed';
    public const OUT_VOID = 'void';

    /** @var int[] 64 squares */
    public array $b = [];
    /** @var int[] 64 squares: piece identity, 0 = empty */
    public array $id = [];
    /** @var array<int,int> identity => ledger flags */
    public array $lf = [];
    /** @var array<int,int> identity => the type the piece was born as */
    public array $born = [];
    public int $side = self::WHITE;
    public int $castle = 15;
    public int $ep = -1;
    public int $half = 0;
    public int $full = 1;
    /** @var array<int,int> colour => King square */
    public array $ksq = [1 => 4, -1 => 60];
    public array $writs = [1 => self::WRIT_START, -1 => self::WRIT_START];
    public array $lastCount = [1 => -100, -1 => -100];
    /** @var array<int,int> colour => identity of the pending Candidate, 0 = none */
    public array $pending = [1 => 0, -1 => 0];
    public ?array $lastEvent = null;
    public array $lastMove = [];
    public int $nextId = 1;
    /** Variant switches. elections=false is plain FIDE chess (the A/B baseline). */
    public array $opt = ['elections' => true, 'queenEligible' => false];

    /** Precomputed tables. */
    public static array $KN = [];
    public static array $KG = [];
    public static array $RAY = [];
    public static array $CMASK = [];
    public static array $ZP = [];
    public static array $ZC = [];
    public static array $ZE = [];
    public static array $ZW = [];
    public static int $ZS = 0;
    public static array $ZPEND = [];
    private static bool $ready = false;

    public static function init(): void
    {
        if (self::$ready) {
            return;
        }
        self::$ready = true;
        // N, S, E, W, NE, NW, SE, SW as (file step, rank step)
        $dirs = [[0, 1], [0, -1], [1, 0], [-1, 0], [1, 1], [-1, 1], [1, -1], [-1, -1]];
        $jumps = [[1, 2], [2, 1], [2, -1], [1, -2], [-1, -2], [-2, -1], [-2, 1], [-1, 2]];
        for ($sq = 0; $sq < 64; $sq++) {
            $f = $sq & 7;
            $r = $sq >> 3;
            $kn = [];
            foreach ($jumps as [$df, $dr]) {
                $nf = $f + $df;
                $nr = $r + $dr;
                if ($nf >= 0 && $nf < 8 && $nr >= 0 && $nr < 8) {
                    $kn[] = $nr * 8 + $nf;
                }
            }
            self::$KN[$sq] = $kn;
            $kg = [];
            $rays = [];
            foreach ($dirs as $d => [$df, $dr]) {
                $nf = $f + $df;
                $nr = $r + $dr;
                if ($nf >= 0 && $nf < 8 && $nr >= 0 && $nr < 8) {
                    $kg[] = $nr * 8 + $nf;
                }
                $ray = [];
                while ($nf >= 0 && $nf < 8 && $nr >= 0 && $nr < 8) {
                    $ray[] = $nr * 8 + $nf;
                    $nf += $df;
                    $nr += $dr;
                }
                $rays[$d] = $ray;
            }
            self::$KG[$sq] = $kg;
            self::$RAY[$sq] = $rays;
            self::$CMASK[$sq] = 15;
        }
        self::$CMASK[0] = 15 & ~2;
        self::$CMASK[4] = 15 & ~3;
        self::$CMASK[7] = 15 & ~1;
        self::$CMASK[56] = 15 & ~8;
        self::$CMASK[60] = 15 & ~12;
        self::$CMASK[63] = 15 & ~4;

        // Zobrist keys from a fixed seed, so a book written today is read tomorrow.
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(20261120));
        $r64 = static fn(): int => unpack('q', $rng->getBytes(8))[1];
        for ($p = 0; $p < 12; $p++) {
            for ($sq = 0; $sq < 64; $sq++) {
                self::$ZP[$p][$sq] = $r64();
            }
        }
        for ($i = 0; $i < 16; $i++) {
            self::$ZC[$i] = $r64();
        }
        for ($i = 0; $i < 8; $i++) {
            self::$ZE[$i] = $r64();
        }
        foreach ([1, -1] as $c) {
            for ($i = 0; $i <= self::WRIT_CAP; $i++) {
                self::$ZW[$c][$i] = $r64();
            }
            self::$ZPEND[$c] = $r64();
        }
        self::$ZS = $r64();
    }

    // ------------------------------------------------------------------
    // Construction
    // ------------------------------------------------------------------

    public static function start(array $opt = []): self
    {
        return self::fromFen('rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1', $opt);
    }

    public static function fromFen(string $fen, array $opt = []): self
    {
        self::init();
        $p = new self();
        $p->opt = array_merge($p->opt, $opt);
        $p->b = array_fill(0, 64, 0);
        $p->id = array_fill(0, 64, 0);
        $parts = preg_split('/\s+/', trim($fen));
        $rows = explode('/', $parts[0]);
        if (count($rows) !== 8) {
            throw new \InvalidArgumentException("bad FEN: $fen");
        }
        $map = ['p' => 1, 'n' => 2, 'b' => 3, 'r' => 4, 'q' => 5, 'k' => 6];
        foreach ($rows as $i => $row) {
            $r = 7 - $i;
            $f = 0;
            foreach (str_split($row) as $ch) {
                if ($ch >= '1' && $ch <= '8') {
                    $f += (int)$ch;
                    continue;
                }
                $t = $map[strtolower($ch)] ?? throw new \InvalidArgumentException("bad FEN piece $ch");
                $sq = $r * 8 + $f;
                $p->b[$sq] = ($ch >= 'A' && $ch <= 'Z') ? $t : -$t;
                $f++;
            }
        }
        // Identities: White first, then Black, in square order, so the start position is stable.
        foreach ([1, -1] as $c) {
            for ($sq = 0; $sq < 64; $sq++) {
                $v = $p->b[$sq];
                if ($v !== 0 && ($v > 0) === ($c > 0)) {
                    $pid = $p->nextId++;
                    $p->id[$sq] = $pid;
                    $p->born[$pid] = abs($v);
                    $p->lf[$pid] = abs($v) === self::K ? self::L_REIGNED : (abs($v) === self::Q ? self::L_WAS_QUEEN : 0);
                    if (abs($v) === self::K) {
                        $p->ksq[$c] = $sq;
                    }
                }
            }
        }
        $p->side = ($parts[1] ?? 'w') === 'b' ? -1 : 1;
        $cs = $parts[2] ?? '-';
        $p->castle = (str_contains($cs, 'K') ? 1 : 0) | (str_contains($cs, 'Q') ? 2 : 0)
            | (str_contains($cs, 'k') ? 4 : 0) | (str_contains($cs, 'q') ? 8 : 0);
        $eps = $parts[3] ?? '-';
        $p->ep = $eps === '-' ? -1 : self::sqIndex($eps);
        $p->half = (int)($parts[4] ?? 0);
        $p->full = (int)($parts[5] ?? 1);
        return $p;
    }

    public function toFen(): string
    {
        $chars = [1 => 'p', 2 => 'n', 3 => 'b', 4 => 'r', 5 => 'q', 6 => 'k'];
        $rows = [];
        for ($r = 7; $r >= 0; $r--) {
            $row = '';
            $empty = 0;
            for ($f = 0; $f < 8; $f++) {
                $v = $this->b[$r * 8 + $f];
                if ($v === 0) {
                    $empty++;
                    continue;
                }
                if ($empty) {
                    $row .= $empty;
                    $empty = 0;
                }
                $ch = $chars[abs($v)];
                $row .= $v > 0 ? strtoupper($ch) : $ch;
            }
            if ($empty) {
                $row .= $empty;
            }
            $rows[] = $row;
        }
        $cs = ($this->castle & 1 ? 'K' : '') . ($this->castle & 2 ? 'Q' : '') . ($this->castle & 4 ? 'k' : '') . ($this->castle & 8 ? 'q' : '');
        return implode('/', $rows) . ' ' . ($this->side === 1 ? 'w' : 'b') . ' ' . ($cs ?: '-') . ' '
            . ($this->ep >= 0 ? self::sqName($this->ep) : '-') . ' ' . $this->half . ' ' . $this->full;
    }

    /** The whole state, for storage and replay. */
    public function toArray(): array
    {
        return [
            'fen' => $this->toFen(), 'id' => $this->id, 'lf' => $this->lf, 'born' => $this->born,
            'ksq' => $this->ksq, 'writs' => $this->writs, 'lastCount' => $this->lastCount,
            'pending' => $this->pending, 'nextId' => $this->nextId, 'opt' => $this->opt,
            'lastEvent' => $this->lastEvent, 'lastMove' => $this->lastMove,
        ];
    }

    public static function fromArray(array $a): self
    {
        $p = self::fromFen($a['fen'], $a['opt'] ?? []);
        $p->id = array_map('intval', $a['id']);
        $p->lf = self::intKeys($a['lf']);
        $p->born = self::intKeys($a['born']);
        $p->ksq = self::intKeys($a['ksq']);
        $p->writs = self::intKeys($a['writs']);
        $p->lastCount = self::intKeys($a['lastCount']);
        $p->pending = self::intKeys($a['pending']);
        $p->nextId = (int)$a['nextId'];
        $p->lastEvent = $a['lastEvent'] ?? null;
        $p->lastMove = $a['lastMove'] ?? [];
        return $p;
    }

    private static function intKeys(array $a): array
    {
        $o = [];
        foreach ($a as $k => $v) {
            $o[(int)$k] = (int)$v;
        }
        return $o;
    }

    public static function sqName(int $sq): string
    {
        return chr(97 + ($sq & 7)) . (($sq >> 3) + 1);
    }

    public static function sqIndex(string $s): int
    {
        return (ord($s[1]) - 49) * 8 + (ord($s[0]) - 97);
    }

    // ------------------------------------------------------------------
    // Attacks
    // ------------------------------------------------------------------

    /** Is $sq attacked by colour $by on board $b? */
    public static function attacked(array $b, int $sq, int $by): bool
    {
        $f = $sq & 7;
        if ($by === 1) {
            if ($f > 0 && $sq >= 9 && $b[$sq - 9] === 1) {
                return true;
            }
            if ($f < 7 && $sq >= 7 && $b[$sq - 7] === 1) {
                return true;
            }
        } else {
            if ($f > 0 && $sq + 7 < 64 && $b[$sq + 7] === -1) {
                return true;
            }
            if ($f < 7 && $sq + 9 < 64 && $b[$sq + 9] === -1) {
                return true;
            }
        }
        $n = 2 * $by;
        foreach (self::$KN[$sq] as $t) {
            if ($b[$t] === $n) {
                return true;
            }
        }
        $k = 6 * $by;
        foreach (self::$KG[$sq] as $t) {
            if ($b[$t] === $k) {
                return true;
            }
        }
        $r = 4 * $by;
        $q = 5 * $by;
        $bb = 3 * $by;
        $rays = self::$RAY[$sq];
        for ($d = 0; $d < 4; $d++) {
            foreach ($rays[$d] as $t) {
                $v = $b[$t];
                if ($v !== 0) {
                    if ($v === $r || $v === $q) {
                        return true;
                    }
                    break;
                }
            }
        }
        for ($d = 4; $d < 8; $d++) {
            foreach ($rays[$d] as $t) {
                $v = $b[$t];
                if ($v !== 0) {
                    if ($v === $bb || $v === $q) {
                        return true;
                    }
                    break;
                }
            }
        }
        return false;
    }

    /** Every enemy piece attacking $sq (for the UI's intimidation lines). */
    public function attackersOf(int $sq, int $by): array
    {
        $out = [];
        for ($s = 0; $s < 64; $s++) {
            $v = $this->b[$s];
            if ($v === 0 || ($v > 0) !== ($by > 0)) {
                continue;
            }
            $b = $this->b;
            // Remove every other piece of $by except this one, then ask.
            for ($t = 0; $t < 64; $t++) {
                if ($t !== $s && $b[$t] !== 0 && ($b[$t] > 0) === ($by > 0)) {
                    $b[$t] = 7 * $by; // a blocker that attacks nothing
                }
            }
            if (self::attacked($b, $sq, $by)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    public function inCheck(?int $c = null): bool
    {
        $c ??= $this->side;
        return self::attacked($this->b, $this->ksq[$c], -$c);
    }

    // ------------------------------------------------------------------
    // Move generation
    // ------------------------------------------------------------------

    /** Pseudo-legal moves for the side to move (Writs included unless captures only). */
    public function moves(bool $capsOnly = false): array
    {
        $b = $this->b;
        $s = $this->side;
        $out = [];
        for ($sq = 0; $sq < 64; $sq++) {
            $v = $b[$sq];
            if ($v === 0 || ($v > 0) !== ($s > 0)) {
                continue;
            }
            $t = $v * $s;
            if ($t === 1) {
                $this->pawnMoves($sq, $out, $capsOnly);
                continue;
            }
            if ($t === 2 || $t === 6) {
                foreach ($t === 2 ? self::$KN[$sq] : self::$KG[$sq] as $to) {
                    $w = $b[$to];
                    if ($w === 0) {
                        if (!$capsOnly) {
                            $out[] = $sq | ($to << 6);
                        }
                    } elseif ($w * $s < 0) {
                        $out[] = $sq | ($to << 6) | (self::F_CAPTURE << 16);
                    }
                }
                if ($t === 6 && !$capsOnly) {
                    $this->castles($sq, $out);
                }
                continue;
            }
            $d0 = $t === 3 ? 4 : 0;
            $d1 = $t === 4 ? 4 : 8;
            $rays = self::$RAY[$sq];
            for ($d = $d0; $d < $d1; $d++) {
                foreach ($rays[$d] as $to) {
                    $w = $b[$to];
                    if ($w === 0) {
                        if (!$capsOnly) {
                            $out[] = $sq | ($to << 6);
                        }
                        continue;
                    }
                    if ($w * $s < 0) {
                        $out[] = $sq | ($to << 6) | (self::F_CAPTURE << 16);
                    }
                    break;
                }
            }
        }
        if (!$capsOnly && $this->canWrit($s)) {
            foreach ($this->candidates($s) as $sq) {
                $out[] = $sq | ($sq << 6) | (self::F_WRIT << 16);
            }
        }
        return $out;
    }

    private function pawnMoves(int $sq, array &$out, bool $capsOnly): void
    {
        $b = $this->b;
        $s = $this->side;
        $r = $sq >> 3;
        $last = $s === 1 ? 7 : 0;
        if ($r === $last) {
            return; // L5: a pawn placed on its last rank is stranded
        }
        $fwd = $sq + 8 * $s;
        $isPromo = ($fwd >> 3) === $last;
        $promos = [0];
        if ($isPromo) {
            $promos = ($this->lf[$this->id[$sq]] ?? 0) & self::L_PRETENDER ? [4, 3, 2] : [5, 4, 3, 2];
        }
        if ((!$capsOnly || $isPromo) && $b[$fwd] === 0) {
            foreach ($promos as $pr) {
                $out[] = $sq | ($fwd << 6) | ($pr << 12) | (($pr ? self::F_PROMO : 0) << 16);
            }
            // L6: a pawn on its own first rank never double-steps; the usual start rank does.
            if (!$capsOnly && $r === ($s === 1 ? 1 : 6)) {
                $f2 = $fwd + 8 * $s;
                if ($b[$f2] === 0) {
                    $out[] = $sq | ($f2 << 6) | (self::F_DOUBLE << 16);
                }
            }
        }
        $f = $sq & 7;
        foreach ([-1, 1] as $df) {
            $nf = $f + $df;
            if ($nf < 0 || $nf > 7) {
                continue;
            }
            $to = $fwd + $df;
            $w = $b[$to];
            if ($w * $s < 0) {
                foreach ($promos as $pr) {
                    $out[] = $sq | ($to << 6) | ($pr << 12) | ((self::F_CAPTURE | ($pr ? self::F_PROMO : 0)) << 16);
                }
            } elseif ($w === 0 && $to === $this->ep) {
                $out[] = $sq | ($to << 6) | ((self::F_CAPTURE | self::F_EP) << 16);
            }
        }
    }

    private function castles(int $sq, array &$out): void
    {
        $b = $this->b;
        $s = $this->side;
        if ($s === 1 && $sq === 4 && ($this->castle & 3)) {
            if ($this->inCheck(1)) {
                return;
            }
            if (($this->castle & 1) && $b[5] === 0 && $b[6] === 0 && $b[7] === 4
                && !self::attacked($b, 5, -1) && !self::attacked($b, 6, -1)) {
                $out[] = 4 | (6 << 6) | (self::F_CASTLE << 16);
            }
            if (($this->castle & 2) && $b[3] === 0 && $b[2] === 0 && $b[1] === 0 && $b[0] === 4
                && !self::attacked($b, 3, -1) && !self::attacked($b, 2, -1)) {
                $out[] = 4 | (2 << 6) | (self::F_CASTLE << 16);
            }
        } elseif ($s === -1 && $sq === 60 && ($this->castle & 12)) {
            if ($this->inCheck(-1)) {
                return;
            }
            if (($this->castle & 4) && $b[61] === 0 && $b[62] === 0 && $b[63] === -4
                && !self::attacked($b, 61, 1) && !self::attacked($b, 62, 1)) {
                $out[] = 60 | (62 << 6) | (self::F_CASTLE << 16);
            }
            if (($this->castle & 8) && $b[59] === 0 && $b[58] === 0 && $b[57] === 0 && $b[56] === -4
                && !self::attacked($b, 59, 1) && !self::attacked($b, 58, 1)) {
                $out[] = 60 | (58 << 6) | (self::F_CASTLE << 16);
            }
        }
    }

    /** Does a pseudo-legal move keep the mover's King out of check? */
    public function isLegal(int $m): bool
    {
        $fl = $m >> 16;
        if ($fl & self::F_WRIT) {
            return true; // canWrit() already required "not in check"
        }
        $from = $m & 63;
        $to = ($m >> 6) & 63;
        $s = $this->side;
        $b = $this->b;
        $v = $b[$from];
        $b[$to] = $v;
        $b[$from] = 0;
        if ($fl & self::F_EP) {
            $b[$to - 8 * $s] = 0;
        }
        $k = $v * $s === 6 ? $to : $this->ksq[$s];
        return !self::attacked($b, $k, -$s);
    }

    public function legalMoves(): array
    {
        $out = [];
        foreach ($this->moves() as $m) {
            if ($this->isLegal($m)) {
                $out[] = $m;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Elections
    // ------------------------------------------------------------------

    /** May colour $c file a Writ now? (RULES.md section F) */
    public function canWrit(int $c): bool
    {
        return $this->opt['elections']
            && $this->writs[$c] > 0
            && $this->full >= self::PEACE_UNTIL
            && $this->pending[$c] === 0
            && $this->full - $this->lastCount[$c] >= self::TERM
            && !$this->inCheck($c);
    }

    /** Squares of colour $c's eligible Candidates: not King, not Queen, never crowned, not attacked. */
    public function candidates(int $c): array
    {
        $out = [];
        $b = $this->b;
        for ($sq = 0; $sq < 64; $sq++) {
            $v = $b[$sq];
            if ($v === 0 || ($v > 0) !== ($c > 0)) {
                continue;
            }
            $t = $v * $c;
            if ($t === self::K || ($t === self::Q && !$this->opt['queenEligible'])) {
                continue;
            }
            if (($this->lf[$this->id[$sq]] ?? 0) & self::L_REIGNED) {
                continue;
            }
            if (self::attacked($b, $sq, -$c)) {
                continue;
            }
            $out[] = $sq;
        }
        return $out;
    }

    /**
     * The tally if colour $c's election were counted now with the Candidate on $candSq.
     * Each voter backs the nearer of Incumbent and Candidate (king-steps); ties back the Incumbent;
     * a voter attacked by the enemy abstains. Returns the votes, the per-voter map and the outcome.
     */
    public function poll(int $c, int $candSq): array
    {
        $b = $this->b;
        $k = $this->ksq[$c];
        $kf = $k & 7;
        $kr = $k >> 3;
        $cf = $candSq & 7;
        $cr = $candSq >> 3;
        $commons = [0, 0]; // [candidate, incumbent]
        $college = [0, 0];
        $speaker = null;
        $voters = [];
        for ($sq = 0; $sq < 64; $sq++) {
            $v = $b[$sq];
            if ($v === 0 || ($v > 0) !== ($c > 0) || $sq === $k || $sq === $candSq) {
                continue;
            }
            $t = $v * $c;
            if ($t === self::K) {
                continue;
            }
            if (self::attacked($b, $sq, -$c)) {
                $voters[$sq] = 'X';
                continue;
            }
            $f = $sq & 7;
            $r = $sq >> 3;
            $dk = max(abs($f - $kf), abs($r - $kr));
            $dc = max(abs($f - $cf), abs($r - $cr));
            $side = $dc < $dk ? 0 : 1;
            $voters[$sq] = $side === 0 ? 'C' : 'I';
            if ($t === self::P) {
                $commons[$side] += 1;
            } elseif ($t === self::Q) {
                $speaker = $side;
            } else {
                $college[$side] += self::VOTES[$t];
            }
        }
        $collegeWin = $college[0] > $college[1] ? 0 : ($college[0] < $college[1] ? 1 : ($speaker ?? 1));
        $popularWin = $commons[0] > $commons[1] ? 0 : 1;
        $outcome = match (true) {
            $collegeWin === 0 && $popularWin === 0 => self::OUT_CORONATION,
            $collegeWin === 0 => self::OUT_CONTESTED,
            $popularWin === 0 => self::OUT_SWAP,
            default => self::OUT_DEFEAT,
        };
        return [
            'commons' => $commons, 'college' => $college, 'speaker' => $speaker,
            'collegeWinner' => $collegeWin === 0 ? 'candidate' : 'incumbent',
            'popularWinner' => $popularWin === 0 ? 'candidate' : 'incumbent',
            'voters' => $voters, 'outcome' => $outcome,
        ];
    }

    /** Count colour $c's pending election now (evaluation uses this to score a pending Writ exactly). */
    public function countNow(int $c): void
    {
        if ($this->pending[$c]) {
            $this->count($c);
        }
    }

    /** The Count, at the start of colour $c's turn (RULES.md section E, step 3). */
    private function count(int $c): void
    {
        $cid = $this->pending[$c];
        $this->pending[$c] = 0;
        $this->lastCount[$c] = $this->full;
        $csq = array_search($cid, $this->id, true);
        $csq = $csq === false ? -1 : $csq;
        $ev = ['type' => 'count', 'color' => $c, 'cand' => $cid, 'candSq' => $csq, 'kingSq' => $this->ksq[$c],
            'candType' => $csq >= 0 ? abs($this->b[$csq]) : 0, 'full' => $this->full];
        if ($csq < 0) {
            $ev['outcome'] = self::OUT_FAILED;
            $ev['reason'] = 'candidate captured';
        } elseif (self::attacked($this->b, $csq, -$c)) {
            $ev['outcome'] = self::OUT_FAILED;
            $ev['reason'] = 'candidate square attacked';
        } else {
            $ev += $this->poll($c, $csq);
            $out = $ev['outcome'];
            if ($out === self::OUT_CORONATION || $out === self::OUT_CONTESTED || $out === self::OUT_SWAP) {
                $save = [$this->b, $this->id, $this->lf, $this->ksq, $this->castle];
                $k = $this->ksq[$c];
                if ($out === self::OUT_SWAP) {
                    [$this->b[$k], $this->b[$csq]] = [$this->b[$csq], $this->b[$k]];
                    [$this->id[$k], $this->id[$csq]] = [$this->id[$csq], $this->id[$k]];
                } else {
                    $kid = $this->id[$k];
                    $this->b[$csq] = self::K * $c;
                    $this->lf[$cid] = ($this->lf[$cid] ?? 0) | self::L_REIGNED;
                    $this->b[$k] = self::P * $c;
                    $this->lf[$kid] = ($this->lf[$kid] ?? 0) | self::L_PRETENDER | self::L_REIGNED;
                }
                $this->ksq[$c] = $csq;
                $this->castle &= $c === 1 ? ~3 : ~12;
                // A result may not leave the new King in check, nor give check by ballot.
                if (self::attacked($this->b, $csq, -$c) || self::attacked($this->b, $this->ksq[-$c], $c)) {
                    [$this->b, $this->id, $this->lf, $this->ksq, $this->castle] = $save;
                    $ev['reason'] = 'result would ' . (self::attacked($save[0], $csq, -$c) ? 'leave the King in check' : 'give check');
                    $ev['outcome'] = self::OUT_VOID;
                } else {
                    $this->half = 0;
                    if ($out === self::OUT_CONTESTED) {
                        $this->writs[-$c] = min(self::WRIT_CAP, $this->writs[-$c] + 1);
                    }
                }
            }
        }
        $this->lastEvent = $ev;
    }

    // ------------------------------------------------------------------
    // Making moves
    // ------------------------------------------------------------------

    /** Play a legal move in place. The opponent's pending election, if any, is counted at the end. */
    public function apply(int $m): void
    {
        $from = $m & 63;
        $to = ($m >> 6) & 63;
        $promo = ($m >> 12) & 7;
        $fl = $m >> 16;
        $s = $this->side;
        $this->lastEvent = null;
        $this->lastMove = [$from, $to, $fl];
        if ($fl & self::F_WRIT) {
            $this->pending[$s] = $this->id[$from];
            $this->writs[$s]--;
            $this->ep = -1;
            $this->half++;
        } else {
            $b = &$this->b;
            $ids = &$this->id;
            $v = $b[$from];
            $cap = $b[$to];
            if ($fl & self::F_EP) {
                $cs = $to - 8 * $s;
                $cap = $b[$cs];
                $b[$cs] = 0;
                $ids[$cs] = 0;
            }
            $b[$to] = $v;
            $ids[$to] = $ids[$from];
            $b[$from] = 0;
            $ids[$from] = 0;
            if ($promo) {
                $b[$to] = $promo * $s;
                if ($promo === self::Q) {
                    $this->lf[$ids[$to]] = ($this->lf[$ids[$to]] ?? 0) | self::L_WAS_QUEEN;
                }
            }
            if ($v * $s === 6) {
                $this->ksq[$s] = $to;
                if ($fl & self::F_CASTLE) {
                    [$rf, $rt] = match ($to) { 6 => [7, 5], 2 => [0, 3], 62 => [63, 61], default => [56, 59] };
                    $b[$rt] = $b[$rf];
                    $ids[$rt] = $ids[$rf];
                    $b[$rf] = 0;
                    $ids[$rf] = 0;
                }
            }
            unset($b, $ids);
            $this->castle &= self::$CMASK[$from] & self::$CMASK[$to];
            // Grievance: losing a Knight, Bishop, Rook or Queen earns a Writ.
            $ct = abs($cap);
            if ($ct >= 2 && $ct <= 5) {
                $this->writs[-$s] = min(self::WRIT_CAP, $this->writs[-$s] + 1);
            }
            $this->half = ($v * $s === 1 || $cap !== 0) ? 0 : $this->half + 1;
            $this->ep = ($fl & self::F_DOUBLE) ? $from + 8 * $s : -1;
        }
        if ($s === -1) {
            $this->full++;
        }
        $this->side = -$s;
        if ($this->pending[-$s] !== 0) {
            $this->count(-$s);
        }
    }

    public function after(int $m): self
    {
        $c = clone $this;
        $c->apply($m);
        return $c;
    }

    // ------------------------------------------------------------------
    // Keys and end states
    // ------------------------------------------------------------------

    public function hash(): int
    {
        $h = 0;
        $b = $this->b;
        for ($sq = 0; $sq < 64; $sq++) {
            $v = $b[$sq];
            if ($v !== 0) {
                $h ^= self::$ZP[$v > 0 ? $v - 1 : 5 - $v][$sq];
            }
        }
        if ($this->side === -1) {
            $h ^= self::$ZS;
        }
        $h ^= self::$ZC[$this->castle];
        if ($this->ep >= 0) {
            $h ^= self::$ZE[$this->ep & 7];
        }
        if ($this->opt['elections']) {
            $h ^= self::$ZW[1][$this->writs[1]] ^ self::$ZW[-1][$this->writs[-1]];
            if ($this->pending[1]) {
                $h ^= self::$ZPEND[1];
            }
            if ($this->pending[-1]) {
                $h ^= self::$ZPEND[-1];
            }
        }
        return $h;
    }

    /** FIDE dead positions only. A lone minor has no electorate, so it can never be crowned into a win. */
    public function insufficient(): bool
    {
        $minors = [];
        foreach ($this->b as $sq => $v) {
            $t = abs($v);
            if ($t === 0 || $t === 6) {
                continue;
            }
            if ($t === 1 || $t === 4 || $t === 5) {
                return false;
            }
            $minors[] = [$t, $sq, $v > 0 ? 1 : -1];
        }
        if (count($minors) <= 1) {
            return true;
        }
        if (count($minors) === 2 && $minors[0][0] === 3 && $minors[1][0] === 3 && $minors[0][2] !== $minors[1][2]) {
            $c = static fn(int $sq): int => (($sq & 7) + ($sq >> 3)) & 1;
            return $c($minors[0][1]) === $c($minors[1][1]);
        }
        return false;
    }

    public static function cheb(int $a, int $b): int
    {
        return max(abs(($a & 7) - ($b & 7)), abs(($a >> 3) - ($b >> 3)));
    }
}
