<?php
declare(strict_types=1);

namespace Parliament\AI;

use Parliament\Engine\Position;

/**
 * The second-generation net in PHP: inference only, for the weights train/train_torch.py exports.
 *
 *   Sensors v2 (sparse) -> h1 (sum of rows, clipped ReLU) -> h2 (clipped ReLU)
 *     -> value: tanh, the side to move's expected result (the teacher's deeper search, learned)
 *     -> policy: one logit per move, h2 . (from + to) or h2 . writ, for move ordering
 */
final class Net2
{
    public int $h1;
    public int $h2;
    public array $emb;
    public array $b1;
    public array $w2;
    public array $b2;
    public array $wv;
    public float $bv;
    public array $from;
    public array $to;
    public array $writ;
    public array $mbias;
    public array $meta = [];
    public string $path;
    /** "value": the net is the evaluation; "residual": it is a correction added to the hand-written one. */
    public string $mode = 'value';

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("no net at $path");
        }
        // Two lanes on one machine share pc-data/: one job's send may be rewriting the net while
        // another reads it (g012, 2026-11-21). Half a file is not JSON, so wait and read again.
        for ($try = 0; $try < 40; $try++) {
            $a = json_decode((string)file_get_contents($path), true);
            if (is_array($a)) {
                break;
            }
            usleep(500_000);
        }
        if (($a['version'] ?? 0) !== 2 || ($a['inputs'] ?? 0) !== Sensors::INPUTS) {
            throw new \RuntimeException("$path is not a Sensors v2 net");
        }
        $n = new self();
        $n->path = $path;
        $n->h1 = (int)$a['h1'];
        $n->h2 = (int)$a['h2'];
        foreach (['emb', 'b1', 'w2', 'b2', 'wv', 'from', 'to', 'writ', 'mbias', 'meta'] as $k) {
            $n->$k = $a[$k];
        }
        $n->bv = (float)$a['bv'];
        $n->mode = $a['mode'] ?? 'value';
        return $n;
    }

    /** The second hidden layer for a position (the shared trunk of both heads). */
    public function trunk(Position $p): array
    {
        $acc = $this->b1;
        $h1 = $this->h1;
        foreach (Sensors::features($p) as $i) {
            $row = $this->emb[$i];
            for ($j = 0; $j < $h1; $j++) {
                $acc[$j] += $row[$j];
            }
        }
        $h = $this->b2;
        $h2 = $this->h2;
        for ($j = 0; $j < $h1; $j++) {
            $a = $acc[$j];
            if ($a <= 0) {
                continue;
            }
            if ($a > 1) {
                $a = 1.0;
            }
            $w = $this->w2[$j];
            for ($k = 0; $k < $h2; $k++) {
                $h[$k] += $a * $w[$k];
            }
        }
        for ($k = 0; $k < $h2; $k++) {
            $h[$k] = $h[$k] < 0 ? 0.0 : ($h[$k] > 1 ? 1.0 : $h[$k]);
        }
        return $h;
    }

    /** The value head before its squashing: a correction in units of 400 cp for a residual net. */
    public function raw(Position $p, ?array $h = null): float
    {
        $h ??= $this->trunk($p);
        $z = $this->bv;
        foreach ($h as $k => $x) {
            $z += $x * $this->wv[$k];
        }
        return $z;
    }

    /** Expected result for the side to move, in (-1, 1). */
    public function value(Position $p, ?array $h = null): float
    {
        return tanh($this->raw($p, $h));
    }

    /**
     * Everything the net computes for one position, for the brain panel: the active inputs, both
     * hidden layers before and after their clipped ReLU, the value head, every move's logit and the
     * strongest connections that carried the signal (weight x activation), layer by layer.
     *
     *   in -> h1    emb[i][j] for an active input i and a unit j that fired (inputs are 0 or 1)
     *   h1 -> h2    a1[j] x w2[j][k] for units that fired on both sides
     *   h2 -> out   a2[k] x wv[k] for the value; for a move a2[k] x its (from + to)[k] (or writ[k])
 *               less the average legal move's, since the softmax only sees the differences
     */
    public function trace(Position $p, array $moves, int $chosen = 0, int $inEdges = 260, int $midEdges = 180, int $topMoves = 8): array
    {
        $feats = Sensors::features($p);
        $pre1 = $this->b1;
        foreach ($feats as $i) {
            $row = $this->emb[$i];
            for ($j = 0; $j < $this->h1; $j++) {
                $pre1[$j] += $row[$j];
            }
        }
        $a1 = array_map(fn($v) => $v <= 0 ? 0.0 : min(1.0, $v), $pre1);
        $pre2 = $this->b2;
        foreach ($a1 as $j => $a) {
            if ($a > 0) {
                $w = $this->w2[$j];
                for ($k = 0; $k < $this->h2; $k++) {
                    $pre2[$k] += $a * $w[$k];
                }
            }
        }
        $a2 = array_map(fn($v) => $v <= 0 ? 0.0 : min(1.0, $v), $pre2);
        $raw = $this->raw($p, $a2);
        $logits = $this->logits($p, $moves, $a2);

        $e1 = [];
        foreach ($feats as $i) {
            $row = $this->emb[$i];
            foreach ($a1 as $j => $a) {
                if ($a > 0) {
                    $e1[] = [$i, $j, $row[$j]];
                }
            }
        }
        $e2 = [];
        foreach ($a1 as $j => $a) {
            if ($a > 0) {
                foreach ($a2 as $k => $b) {
                    if ($b > 0) {
                        $e2[] = [$j, $k, $a * $this->w2[$j][$k]];
                    }
                }
            }
        }
        $strongest = function (array $e, int $n): array {
            usort($e, fn($x, $y) => abs($y[2]) <=> abs($x[2]));
            return array_map(fn($x) => [$x[0], $x[1], round($x[2], 4)], array_slice($e, 0, $n));
        };
        $ev = [];
        foreach ($a2 as $k => $b) {
            if ($b > 0) {
                $ev[] = [$k, round($b * $this->wv[$k], 4)];
            }
        }
        // The policy: softmax over the legal moves; the strongest few with their own connections.
        $mx = $logits ? max($logits) : 0.0;
        $sum = 0.0;
        foreach ($logits as $z) {
            $sum += exp($z - $mx);
        }
        // A softmax only compares moves, so a move's connections are drawn against the average legal
        // move: gold lifts it above the others, blue holds it below.
        $vecs = [];
        foreach ($moves as $i => $m) {
            $idx = Sensors::moveIndex($p, $m);
            $vecs[$i] = $idx >= 4096 ? $this->writ[$idx - 4096]
                : array_map(fn($a, $b) => $a + $b, $this->from[$idx >> 6], $this->to[$idx & 63]);
        }
        $mean = array_fill(0, $this->h2, 0.0);
        foreach ($vecs as $v) {
            foreach ($v as $k => $x) {
                $mean[$k] += $x / max(1, count($vecs));
            }
        }
        $order = array_keys($logits);
        usort($order, fn($x, $y) => $logits[$y] <=> $logits[$x]);
        $policy = [];
        foreach ($order as $rank => $i) {
            $m = $moves[$i];
            $row = ['m' => $m, 'logit' => round($logits[$i], 3), 'p' => round(exp($logits[$i] - $mx) / $sum, 4)];
            if ($rank < $topMoves || $m === $chosen) {
                $c = [];
                foreach ($a2 as $k => $b) {
                    if ($b > 0) {
                        $c[] = [$k, 0, $b * ($vecs[$i][$k] - $mean[$k])];
                    }
                }
                $row['edges'] = array_map(fn($x) => [$x[0], $x[2]], $strongest($c, 8));
            }
            $policy[] = $row;
        }
        // Every unit that fired, with its own strongest inputs (for hovering a unit off the main lines).
        $unitIn = [];
        foreach ($a1 as $j => $a) {
            if ($a > 0) {
                $c = [];
                foreach ($feats as $i) {
                    $c[] = [$i, $j, $this->emb[$i][$j]];
                }
                $unitIn[$j] = array_map(fn($x) => [$x[0], $x[2]], $strongest($c, 4));
            }
        }
        $unitMid = [];
        foreach ($a2 as $k => $b) {
            if ($b > 0) {
                $c = [];
                foreach ($a1 as $j => $a) {
                    if ($a > 0) {
                        $c[] = [$j, $k, $a * $this->w2[$j][$k]];
                    }
                }
                $unitMid[$k] = array_map(fn($x) => [$x[0], $x[2]], $strongest($c, 4));
            }
        }
        $r4 = fn(array $a) => array_map(fn($v) => round($v, 4), $a);
        return ['features' => $feats, 'pre1' => $r4($pre1), 'a1' => $r4($a1), 'pre2' => $r4($pre2), 'a2' => $r4($a2),
            'raw' => round($raw, 4), 'value' => round(tanh($raw), 4), 'mode' => $this->mode,
            'edges' => ['in' => $strongest($e1, $inEdges), 'mid' => $strongest($e2, $midEdges), 'value' => $ev,
                'unitIn' => (object)$unitIn, 'unitMid' => (object)$unitMid],
            'policy' => $policy, 'sizes' => [Sensors::INPUTS, $this->h1, $this->h2, count($moves)],
            'net' => $this->meta['name'] ?? basename($this->path, '.json')];
    }

    /** Policy logits for the given moves (same order). */
    public function logits(Position $p, array $moves, ?array $h = null): array
    {
        $h ??= $this->trunk($p);
        $out = [];
        foreach ($moves as $m) {
            $idx = Sensors::moveIndex($p, $m);
            $z = $this->mbias[$idx];
            if ($idx >= 4096) {
                $v = $this->writ[$idx - 4096];
                foreach ($h as $k => $x) {
                    $z += $x * $v[$k];
                }
            } else {
                $f = $this->from[$idx >> 6];
                $t = $this->to[$idx & 63];
                foreach ($h as $k => $x) {
                    $z += $x * ($f[$k] + $t[$k]);
                }
            }
            $out[] = $z;
        }
        return $out;
    }
}
