<?php
declare(strict_types=1);

namespace Parliament\Engine;

/**
 * A game: a Position plus everything a recording needs — the moves with their
 * notation and timing, every Count, the careers of pieces, and the end state.
 */
final class Game
{
    public Position $pos;
    /** @var list<array> one entry per ply */
    public array $log = [];
    /** @var array<int,int> position key => times seen (threefold repetition) */
    public array $seen = [];
    public string $status = 'playing';   // playing | checkmate | stalemate | draw | resigned
    public ?int $winner = null;          // 1, -1, or null for a draw
    public string $termination = '';
    public string $initialFen;
    public array $opt;
    /** @var array<int,list<array>> identity => career events */
    public array $careers = [];
    public float $startedAt;

    public function __construct(?string $fen = null, array $opt = [])
    {
        $this->opt = $opt;
        $this->pos = $fen ? Position::fromFen($fen, $opt) : Position::start($opt);
        $this->initialFen = $this->pos->toFen();
        $this->seen[$this->pos->hash()] = 1;
        $this->startedAt = microtime(true);
    }

    /** Play a move given as text ("e2e4", "e7e8q", "O-O", "W@f5"). Returns the log entry. */
    public function play(string $text, ?float $at = null): array
    {
        $m = $this->parse($text);
        if ($m === null) {
            throw new \InvalidArgumentException("illegal move: $text");
        }
        return $this->playInt($m, $at);
    }

    public function playInt(int $m, ?float $at = null, ?array $extra = null): array
    {
        if ($this->status !== 'playing') {
            throw new \RuntimeException('game is over');
        }
        $before = $this->pos;
        $by = $before->side;
        $san = self::san($before, $m);
        $after = $before->after($m);
        $this->pos = $after;
        $legal = $after->legalMoves();
        $check = $after->inCheck();
        if ($check) {
            $san .= $legal ? '+' : '#';
        }
        $entry = [
            'ply' => count($this->log) + 1,
            'by' => $by,
            'uci' => self::uci($m),
            'san' => $san,
            'from' => $m & 63,
            'to' => ($m >> 6) & 63,
            'writ' => (bool)(($m >> 16) & Position::F_WRIT),
            't' => (int)round((($at ?? microtime(true)) - $this->startedAt) * 1000),
            'fen' => $after->toFen(),
            'count' => $after->lastEvent,
        ];
        if ($extra) {
            $entry += $extra;
        }
        $this->log[] = $entry;
        $this->career($before, $m, $after, $entry['ply']);

        // End states are judged after the Count (RULES.md section G).
        $key = $after->hash();
        $this->seen[$key] = ($this->seen[$key] ?? 0) + 1;
        if (!$legal) {
            if ($check) {
                $this->finish('checkmate', -$after->side, 'checkmate');
            } else {
                $this->finish('stalemate', null, 'stalemate');
            }
        } elseif ($after->half >= 100) {
            $this->finish('draw', null, 'fifty-move rule');
        } elseif ($this->seen[$key] >= 3) {
            $this->finish('draw', null, 'threefold repetition');
        } elseif ($after->insufficient()) {
            $this->finish('draw', null, 'insufficient material');
        }
        return $entry;
    }

    public function finish(string $status, ?int $winner, string $why): void
    {
        $this->status = $status;
        $this->winner = $winner;
        $this->termination = $why;
    }

    public function resign(int $color): void
    {
        $this->finish('resigned', -$color, ($color === 1 ? 'White' : 'Black') . ' resigned');
    }

    public function result(): string
    {
        if ($this->status === 'playing') {
            return '*';
        }
        return $this->winner === 1 ? '1-0' : ($this->winner === -1 ? '0-1' : '1/2-1/2');
    }

    private function career(Position $before, int $m, Position $after, int $ply): void
    {
        $from = $m & 63;
        $to = ($m >> 6) & 63;
        $fl = $m >> 16;
        if (!($fl & Position::F_WRIT)) {
            $victim = ($fl & Position::F_EP) ? $before->id[$to - 8 * $before->side] : $before->id[$to];
            if ($victim) {
                $this->careers[$victim][] = ['ply' => $ply, 'what' => 'captured', 'sq' => Position::sqName($to)];
            }
            $promo = ($m >> 12) & 7;
            if ($promo) {
                $this->careers[$before->id[$from]][] = ['ply' => $ply, 'what' => 'promoted', 'to' => 'PNBRQK'[$promo - 1], 'sq' => Position::sqName($to)];
            }
        } else {
            $this->careers[$before->id[$from]][] = ['ply' => $ply, 'what' => 'nominated', 'sq' => Position::sqName($from)];
        }
        $ev = $after->lastEvent;
        if ($ev) {
            $out = $ev['outcome'];
            if ($out === Position::OUT_CORONATION || $out === Position::OUT_CONTESTED) {
                $this->careers[$ev['cand']][] = ['ply' => $ply, 'what' => 'crowned', 'sq' => Position::sqName($ev['candSq'])];
                $kid = $after->id[$ev['kingSq']];
                $this->careers[$kid][] = ['ply' => $ply, 'what' => 'dethroned', 'sq' => Position::sqName($ev['kingSq'])];
            } elseif ($out === Position::OUT_SWAP) {
                $this->careers[$ev['cand']][] = ['ply' => $ply, 'what' => 'swapped with the King', 'sq' => Position::sqName($ev['kingSq'])];
            } else {
                $this->careers[$ev['cand']][] = ['ply' => $ply, 'what' => 'lost the count (' . $out . ')'];
            }
        }
    }

    // ------------------------------------------------------------------
    // Notation
    // ------------------------------------------------------------------

    public static function uci(int $m): string
    {
        $from = $m & 63;
        $to = ($m >> 6) & 63;
        if (($m >> 16) & Position::F_WRIT) {
            return 'W@' . Position::sqName($from);
        }
        $promo = ($m >> 12) & 7;
        return Position::sqName($from) . Position::sqName($to) . ($promo ? 'pnbrqk'[$promo - 1] : '');
    }

    /** SAN without the check suffix (added once the reply position is known). Writs read "W@Nf5". */
    public static function san(Position $p, int $m): string
    {
        $from = $m & 63;
        $to = ($m >> 6) & 63;
        $fl = $m >> 16;
        $t = abs($p->b[$from]);
        $L = ['', '', 'N', 'B', 'R', 'Q', 'K'];
        if ($fl & Position::F_WRIT) {
            return 'W@' . $L[$t] . Position::sqName($from);
        }
        if ($fl & Position::F_CASTLE) {
            return ($to & 7) === 6 ? 'O-O' : 'O-O-O';
        }
        $cap = ($fl & Position::F_CAPTURE) !== 0;
        if ($t === Position::P) {
            $s = $cap ? chr(97 + ($from & 7)) . 'x' : '';
            $s .= Position::sqName($to);
            $promo = ($m >> 12) & 7;
            return $promo ? $s . '=' . $L[$promo] : $s;
        }
        $s = $L[$t];
        // Disambiguation among same-type pieces that can reach the same square.
        $rivals = [];
        foreach ($p->legalMoves() as $o) {
            if ($o !== $m && (($o >> 6) & 63) === $to && !(($o >> 16) & Position::F_WRIT)
                && abs($p->b[$o & 63]) === $t && ($o & 63) !== $from) {
                $rivals[] = $o & 63;
            }
        }
        if ($rivals) {
            $sameFile = $sameRank = false;
            foreach ($rivals as $r) {
                $sameFile = $sameFile || ($r & 7) === ($from & 7);
                $sameRank = $sameRank || ($r >> 3) === ($from >> 3);
            }
            if (!$sameFile) {
                $s .= chr(97 + ($from & 7));
            } elseif (!$sameRank) {
                $s .= (string)(($from >> 3) + 1);
            } else {
                $s .= Position::sqName($from);
            }
        }
        return $s . ($cap ? 'x' : '') . Position::sqName($to);
    }

    /** Accepts coordinate moves (e2e4, e7e8q), Writs (W@f5, W@Nf5) and SAN (Nf3, exd5, O-O). */
    public function parse(string $text): ?int
    {
        $text = trim($text);
        $legal = $this->pos->legalMoves();
        if (preg_match('/^[Ww]@[NBRQ]?([a-h][1-8])$/', $text, $mm)) {
            $sq = Position::sqIndex($mm[1]);
            foreach ($legal as $m) {
                if ((($m >> 16) & Position::F_WRIT) && ($m & 63) === $sq) {
                    return $m;
                }
            }
            return null;
        }
        $lower = strtolower($text);
        foreach ($legal as $m) {
            if (self::uci($m) === $lower) {
                return $m;
            }
        }
        $clean = rtrim($text, '+#!?');
        $clean = str_replace('0-0', 'O-O', $clean);
        foreach ($legal as $m) {
            if (self::san($this->pos, $m) === $clean) {
                return $m;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Records
    // ------------------------------------------------------------------

    /** Parliament Chess Notation: PGN with Writs as moves and Counts as comments. */
    public function pcn(array $tags = []): string
    {
        $tags = ['Event' => 'Parliament Chess', 'Variant' => 'Parliament Chess (Vote King) 1.0'] + $tags
            + ['Result' => $this->result(), 'Termination' => $this->termination ?: 'unterminated'];
        if ($this->initialFen !== Position::start()->toFen()) {
            $tags['FEN'] = $this->initialFen;
            $tags['SetUp'] = '1';
        }
        $out = '';
        foreach ($tags as $k => $v) {
            $out .= '[' . $k . ' "' . str_replace('"', "'", (string)$v) . "\"]\n";
        }
        $out .= "\n";
        $fen = explode(' ', $this->initialFen);
        $num = (int)$fen[5];
        $side = $fen[1] === 'w' ? 1 : -1;
        $line = '';
        foreach ($this->log as $i => $e) {
            if ($side === 1) {
                $line .= $num . '. ';
            } elseif ($i === 0) {
                $line .= $num . '... ';
            }
            $line .= $e['san'] . ' ';
            if ($e['count']) {
                $line .= '{' . self::describeCount($e['count']) . '} ';
            }
            if ($side === -1) {
                $num++;
            }
            $side = -$side;
        }
        return $out . wordwrap(trim($line) . ' ' . $this->result(), 80) . "\n";
    }

    public static function describeCount(array $c): string
    {
        $who = $c['color'] === 1 ? 'White' : 'Black';
        $sq = $c['candSq'] >= 0 ? Position::sqName($c['candSq']) : '?';
        if ($c['outcome'] === Position::OUT_FAILED) {
            return "$who count: failed, " . $c['reason'];
        }
        $s = sprintf('%s count for %s: Commons %d-%d, College %d-%d => %s', $who, $sq,
            $c['commons'][0], $c['commons'][1], $c['college'][0], $c['college'][1], $c['outcome']);
        return isset($c['reason']) ? $s . ' (' . $c['reason'] . ')' : $s;
    }

    public function toArray(): array
    {
        return [
            'initialFen' => $this->initialFen, 'opt' => $this->opt, 'log' => $this->log,
            'status' => $this->status, 'winner' => $this->winner, 'termination' => $this->termination,
            'result' => $this->result(), 'careers' => $this->careers, 'startedAt' => $this->startedAt,
            'position' => $this->pos->toArray(), 'seen' => $this->seen,
        ];
    }

    /** Rebuild from a stored snapshot without replaying (the server's fast path). */
    public static function fromSnapshot(array $a): self
    {
        $g = new self($a['initialFen'], $a['opt'] ?? []);
        $g->pos = Position::fromArray($a['position']);
        $g->log = $a['log'];
        $g->seen = [];
        foreach ($a['seen'] ?? [] as $k => $v) {
            $g->seen[(int)$k] = (int)$v;
        }
        $g->status = $a['status'];
        $g->winner = $a['winner'];
        $g->termination = $a['termination'];
        $g->startedAt = (float)$a['startedAt'];
        foreach ($a['careers'] ?? [] as $k => $v) {
            $g->careers[(int)$k] = $v;
        }
        return $g;
    }

    /** Rebuild by replaying the moves: a recording is its own proof. */
    public static function fromArray(array $a): self
    {
        $g = new self($a['initialFen'], $a['opt'] ?? []);
        $g->startedAt = (float)$a['startedAt'];
        foreach ($a['log'] as $e) {
            $m = $g->parse($e['uci']);
            if ($m === null) {
                throw new \RuntimeException('recording does not replay at ply ' . $e['ply'] . ': ' . $e['uci']);
            }
            $extra = array_diff_key($e, array_flip(['ply', 'by', 'uci', 'san', 'from', 'to', 'writ', 't', 'fen', 'count']));
            $g->playInt($m, $g->startedAt + $e['t'] / 1000, $extra ?: null);
            if ($g->status !== 'playing') {
                break;
            }
        }
        if ($a['status'] === 'resigned' && $g->status === 'playing') {
            $g->finish('resigned', $a['winner'], $a['termination']);
        } elseif ($g->status === 'playing' && $a['status'] !== 'playing') {
            $g->finish($a['status'], $a['winner'], $a['termination']);
        }
        return $g;
    }
}
