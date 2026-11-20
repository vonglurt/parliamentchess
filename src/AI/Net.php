<?php
declare(strict_types=1);

namespace Parliament\AI;

use Parliament\Engine\Position;

/**
 * A small value network for Parliament Chess, trainable in plain PHP.
 *
 * Input: 908 binary features, of which only ~35 are on in any position
 * (12 x 64 piece-squares, 2 x 64 Pretender squares, side to move, Writs held,
 * elections pending, Coronation Peace over). Because the input is sparse the
 * first layer costs (active features x hidden) per position — the NNUE idea —
 * so training on tens of thousands of positions takes seconds, not hours.
 *
 * Hidden: H units, clipped ReLU. Output: tanh, White's expected result in [-1, 1].
 */
final class Net
{
    public const INPUTS = 908;
    public int $hidden;
    /** @var array<int, float[]> W1[input] = H weights */
    public array $w1 = [];
    public array $b1 = [];
    public array $w2 = [];
    public float $b2 = 0.0;
    public array $meta = [];

    public function __construct(int $hidden = 32, int $seed = 7)
    {
        $this->hidden = $hidden;
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));
        $scale = 1.0 / sqrt(40);
        for ($i = 0; $i < self::INPUTS; $i++) {
            $row = [];
            for ($j = 0; $j < $hidden; $j++) {
                $row[] = ($rng->nextFloat() * 2 - 1) * $scale;
            }
            $this->w1[$i] = $row;
        }
        $this->b1 = array_fill(0, $hidden, 0.1);
        for ($j = 0; $j < $hidden; $j++) {
            $this->w2[] = ($rng->nextFloat() * 2 - 1) / sqrt($hidden);
        }
    }

    /** Active feature indices for a position. */
    public static function features(Position $p): array
    {
        $f = [];
        foreach ($p->b as $sq => $v) {
            if ($v === 0) {
                continue;
            }
            $ci = $v > 0 ? 0 : 1;
            $t = abs($v);
            $f[] = $ci * 384 + ($t - 1) * 64 + $sq;
            if ($t === 1 && (($p->lf[$p->id[$sq]] ?? 0) & Position::L_PRETENDER)) {
                $f[] = 768 + $ci * 64 + $sq;
            }
        }
        if ($p->side === -1) {
            $f[] = 896;
        }
        $f[] = 897 + min(3, $p->writs[1]);
        $f[] = 901 + min(3, $p->writs[-1]);
        if ($p->pending[1]) {
            $f[] = 905;
        }
        if ($p->pending[-1]) {
            $f[] = 906;
        }
        if ($p->full >= Position::PEACE_UNTIL) {
            $f[] = 907;
        }
        return $f;
    }

    public function predict(Position $p): float
    {
        return $this->forward(self::features($p))[0];
    }

    /** @return array{0: float, 1: float[]} output and hidden activations */
    public function forward(array $feats): array
    {
        $h = $this->b1;
        $H = $this->hidden;
        foreach ($feats as $i) {
            $row = $this->w1[$i];
            for ($j = 0; $j < $H; $j++) {
                $h[$j] += $row[$j];
            }
        }
        $z = $this->b2;
        for ($j = 0; $j < $H; $j++) {
            $a = $h[$j] < 0 ? 0.0 : ($h[$j] > 1 ? 1.0 : $h[$j]);
            $h[$j] = $a;
            $z += $a * $this->w2[$j];
        }
        return [tanh($z), $h];
    }

    /** One SGD step on one sample; returns the squared error before the step. */
    public function step(array $feats, float $target, float $lr): float
    {
        $pre = $this->b1;
        $H = $this->hidden;
        foreach ($feats as $i) {
            $row = $this->w1[$i];
            for ($j = 0; $j < $H; $j++) {
                $pre[$j] += $row[$j];
            }
        }
        $act = [];
        $z = $this->b2;
        for ($j = 0; $j < $H; $j++) {
            $a = $pre[$j] < 0 ? 0.0 : ($pre[$j] > 1 ? 1.0 : $pre[$j]);
            $act[$j] = $a;
            $z += $a * $this->w2[$j];
        }
        $y = tanh($z);
        $err = $y - $target;
        $dz = $err * (1 - $y * $y);
        $dh = [];
        for ($j = 0; $j < $H; $j++) {
            $dh[$j] = ($pre[$j] > 0 && $pre[$j] < 1) ? $dz * $this->w2[$j] : 0.0;
            $this->w2[$j] -= $lr * $dz * $act[$j];
            $this->b1[$j] -= $lr * $dh[$j];
        }
        $this->b2 -= $lr * $dz;
        foreach ($feats as $i) {
            $row = &$this->w1[$i];
            for ($j = 0; $j < $H; $j++) {
                $row[$j] -= $lr * $dh[$j];
            }
            unset($row);
        }
        return $err * $err;
    }

    public function save(string $path): void
    {
        file_put_contents($path, json_encode(['hidden' => $this->hidden, 'w1' => $this->w1, 'b1' => $this->b1,
            'w2' => $this->w2, 'b2' => $this->b2, 'meta' => $this->meta]));
    }

    public static function load(string $path): self
    {
        $a = json_decode((string)file_get_contents($path), true);
        $n = new self((int)$a['hidden']);
        $n->w1 = $a['w1'];
        $n->b1 = $a['b1'];
        $n->w2 = $a['w2'];
        $n->b2 = (float)$a['b2'];
        $n->meta = $a['meta'] ?? [];
        return $n;
    }
}
