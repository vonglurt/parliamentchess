<?php
declare(strict_types=1);

/*
 * Train the Parliament Chess value network on recorded games.
 *
 *   php bin/train.php [--glob 'orrery/census/results/*\/*.json'] [--epochs 6] [--hidden 32] [--lr 0.01]
 *                     [--every 2] [--skip 8] [--lambda 0.7] [--export data/models/dataset.jsonl] [--out data/models/net.json]
 *
 * Each sampled position (every --every plies, after the first --skip) becomes one example:
 * its sparse features (Net::features) and a target in [-1, 1] from White's point of view,
 *     target = lambda * result + (1 - lambda) * tanh(search score / 400),
 * so the net learns both who won and what the shallow search believed at the time.
 * Games are split 90/10 by game (never by position) so validation positions are unseen games.
 * --export also writes the dataset as JSONL for training elsewhere (PyTorch, JAX, ...).
 */
require __DIR__ . '/../src/autoload.php';

use Parliament\AI\Evaluator;
use Parliament\AI\Net;
use Parliament\Engine\Game;

$o = getopt('', ['glob:', 'epochs:', 'hidden:', 'lr:', 'every:', 'skip:', 'lambda:', 'export:', 'out:', 'seed:']);
$root = pc_root();
$globs = isset($o['glob']) ? (array)$o['glob'] : ["$root/orrery/census/results/*/*.json", "$root/orrery/demos/results/*/*.json", "$root/data/recordings/*/*.json"];
$epochs = (int)($o['epochs'] ?? 6);
$hidden = (int)($o['hidden'] ?? 32);
$lr0 = (float)($o['lr'] ?? 0.01);
$every = (int)($o['every'] ?? 2);
$skip = (int)($o['skip'] ?? 8);
$lambda = (float)($o['lambda'] ?? 0.7);
$out = $o['out'] ?? "$root/data/models/net.json";
$seed = (int)($o['seed'] ?? 7);

$files = [];
foreach ($globs as $g) {
    foreach (glob($g) ?: [] as $f) {
        if (!str_ends_with($f, 'summary.json')) {
            $files[] = $f;
        }
    }
}
sort($files);
$t0 = microtime(true);
$games = [];
foreach ($files as $f) {
    $r = json_decode((string)file_get_contents($f), true);
    if (($r['format'] ?? '') !== 'parliament-chess-recording' || $r['status'] === 'playing') {
        continue;
    }
    $result = $r['winner'] === null ? 0.0 : (float)$r['winner'];
    $g = new Game($r['initialFen'], $r['opt'] ?? []);
    $ex = [];
    foreach ($r['log'] as $i => $e) {
        $m = $g->parse($e['uci']);
        if ($m === null) {
            break;
        }
        $g->playInt($m);
        if ($i + 1 < $skip || ($i + 1) % $every !== 0 || $g->status !== 'playing') {
            continue;
        }
        $score = isset($e['score']) ? max(-2000, min(2000, (int)$e['score'])) * $e['by'] : Evaluator::material($g->pos);
        $target = $lambda * $result + (1 - $lambda) * tanh($score / 400);
        $ex[] = [Net::features($g->pos), $target, $result, Evaluator::material($g->pos)];
    }
    if ($ex) {
        $games[] = ['file' => $f, 'ex' => $ex];
    }
}
$rng = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));
$games = $rng->shuffleArray($games);
$nVal = max(1, intdiv(count($games), 10));
$val = array_merge(...array_column(array_slice($games, 0, $nVal), 'ex'));
$train = array_merge(...array_column(array_slice($games, $nVal), 'ex'));
printf("%d recordings, %d games used: %d training positions, %d validation positions (%d held-out games), %.1fs to build\n",
    count($files), count($games), count($train), count($val), $nVal, microtime(true) - $t0);

if (isset($o['export'])) {
    $fh = fopen($o['export'], 'w');
    foreach ($games as $gi => $gm) {
        foreach ($gm['ex'] as [$feats, $target, $result, $mat]) {
            fwrite($fh, json_encode(['game' => $gi, 'split' => $gi < $nVal ? 'val' : 'train', 'features' => $feats,
                'target' => round($target, 4), 'result' => $result, 'material' => $mat]) . "\n");
        }
    }
    fclose($fh);
    echo "dataset written to {$o['export']}\n";
}

/** Mean squared error and decisive-game sign accuracy of a predictor over examples. */
function score(array $ex, callable $predict): array
{
    $se = 0.0;
    $right = $decisive = 0;
    foreach ($ex as [$feats, $target, $result, $mat]) {
        $y = $predict($feats, $mat);
        $se += ($y - $target) ** 2;
        if ($result != 0.0 && abs($y) > 1e-9) {
            $decisive++;
            $right += ($y > 0) === ($result > 0) ? 1 : 0;
        }
    }
    return [$se / max(1, count($ex)), $decisive ? $right / $decisive : 0.0];
}

[$z, $za] = score($val, fn() => 0.0);
[$m, $ma] = score($val, fn($f, $mat) => tanh($mat / 400));
printf("baselines on validation: predict-zero MSE %.4f; material tanh(cp/400) MSE %.4f, sign accuracy %.3f\n", $z, $m, $ma);

$net = new Net($hidden, $seed);
$history = [];
for ($ep = 1; $ep <= $epochs; $ep++) {
    $lr = $lr0 / (1 + 0.3 * ($ep - 1));
    $train = $rng->shuffleArray($train);
    $loss = 0.0;
    foreach ($train as [$feats, $target]) {
        $loss += $net->step($feats, $target, $lr);
    }
    [$vm, $va] = score($val, fn($f) => $net->forward($f)[0]);
    $history[] = ['epoch' => $ep, 'trainMSE' => round($loss / count($train), 5), 'valMSE' => round($vm, 5), 'valSignAcc' => round($va, 4)];
    printf("epoch %d  lr %.4f  train MSE %.4f  val MSE %.4f  val sign accuracy %.3f  (%.1fs)\n", $ep, $lr, $loss / count($train), $vm, $va, microtime(true) - $t0);
}
$net->meta = ['trained' => date('c'), 'positions' => count($train), 'validation' => count($val), 'games' => count($games),
    'hidden' => $hidden, 'lambda' => $lambda, 'history' => $history,
    'baselines' => ['zeroMSE' => round($z, 5), 'materialMSE' => round($m, 5), 'materialSignAcc' => round($ma, 4)]];
if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0775, true);
}
$net->save($out);
echo "saved $out\n";
