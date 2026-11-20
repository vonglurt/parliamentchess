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
        $a = json_decode((string)file_get_contents($path), true);
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
