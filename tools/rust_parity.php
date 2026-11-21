<?php
declare(strict_types=1);
/*
 * Test vectors for the Rust engine (rust/, checked by its pc-parity): positions from real self-play
 * games, with their whole Parliament state (identities, the Ledger, Writs, Terms, pending elections)
 * and the game's repetition counts, and what PHP answers for each: the hash, the move list in order,
 * perft at depth 2 (and 3 for some), the Sensors v2 features, the hand evaluation in two styles, and
 * the teacher's score for every root move at depth 2 (and 3 for some).
 *
 *   php tools/rust_parity.php [--games 10] [--seed 1] > vectors.jsonl
 */
require __DIR__ . '/../src/autoload.php';

use Parliament\AI\Evaluator;
use Parliament\AI\Players;
use Parliament\AI\Search;
use Parliament\AI\Sensors;
use Parliament\Engine\Game;
use Parliament\Engine\Position;

$o = getopt('', ['games:', 'seed:', 'net:']);
// --net FILE: also the net's value-head input and policy logits, and the residual evaluation with it.
$net = isset($o['net']) ? \Parliament\AI\Net2::load($o['net']) : null;
$games = (int)($o['games'] ?? 10);
$seed = (int)($o['seed'] ?? 1);

function state(Position $p): array
{
    $lf = array_fill(0, 64, 0);
    $born = array_fill(0, 64, 0);
    foreach ($p->lf as $id => $v) {
        $lf[$id] = $v;
    }
    foreach ($p->born as $id => $v) {
        $born[$id] = $v;
    }
    return ['fen' => $p->toFen(), 'id' => $p->id, 'lf' => $lf, 'born' => $born,
        'ksq' => [$p->ksq[1], $p->ksq[-1]], 'writs' => [$p->writs[1], $p->writs[-1]],
        'lastCount' => [$p->lastCount[1], $p->lastCount[-1]], 'pending' => [$p->pending[1], $p->pending[-1]],
        'nextId' => $p->nextId, 'elections' => (bool)$p->opt['elections'], 'queenEligible' => (bool)$p->opt['queenEligible']];
}

function perft(Position $p, int $d): int
{
    $moves = $p->legalMoves();
    if ($d === 1) {
        return count($moves);
    }
    $n = 0;
    foreach ($moves as $m) {
        $n += perft($p->after($m), $d - 1);
    }
    return $n;
}

$k = 0;
$emit = function (Game $g, string $name) use (&$k, $net): void {
    $p = $g->pos;
    $v = ['name' => $name, 'state' => state($p),
        'seen' => array_map(fn($h, $n) => [(string)$h, $n], array_keys($g->seen), $g->seen),
        'hash' => (string)$p->hash(), 'moves' => $p->moves(), 'perft2' => perft($p, 2),
        'perft3' => $k % 6 === 0 ? perft($p, 3) : null,
        'features' => Sensors::features($p),
        'h' => (new Evaluator())->evaluate($p),
        'hPopulist' => (new Evaluator(Players::STYLES['populist']))->evaluate($p)];
    if ($net) {
        $v['netRaw'] = $net->raw($p);
        $v['netLogits'] = $net->logits($p, $p->legalMoves());
        $ev = new Evaluator();
        $ev->net2 = $net;
        $v['evalNet'] = $ev->evaluate($p);
    }
    $teacher = [];
    foreach ($k % 4 === 0 ? [2, 3] : [2] as $d) {
        $s = new Search(new Evaluator(), null, $d, 0, 1);
        $s->maxNodes = PHP_INT_MAX;
        $info = $s->choose($g);
        if ($info['move']) {
            $teacher[(string)$d] = ['best' => $info['score'], 'scores' => array_map(fn($m, $sc) => [$m, $sc], array_keys($s->rootScores), $s->rootScores)];
        }
    }
    if ($teacher) {
        $v['teacher'] = $teacher;
    }
    echo json_encode($v, JSON_UNESCAPED_SLASHES), "\n";
    $k++;
};

// Hand-made positions for the rules games rarely reach: castling through and out of check, en passant,
// promotion and underpromotion, a Pretender on its last rank, the coronation of RULES.md section 8.
foreach (['r3k2r/pppppppp/8/8/8/8/PPPPPPPP/R3K2R w KQkq - 0 1', 'r3k2r/8/8/8/4q3/8/8/R3K2R w KQkq - 0 20',
    '8/P6k/8/8/8/8/8/K7 w - - 0 1', '4k3/8/8/3pP3/8/8/8/4K3 w - d6 0 2', '6k1/5ppp/8/r7/8/2PPP3/1N6/7K w - - 0 20',
    'rnbqkbnr/pppp1ppp/8/4p3/4P3/5N2/PPPP1PPP/RNBQKB1R b KQkq - 1 12'] as $i => $fen) {
    $emit(new Game($fen), "fixed-$i");
}
// Self-play: Populists file often and Whigs when it pays; each game samples a position every 3 plies
// and every position just after a Writ or a count.
for ($i = 0; $i < $games; $i++) {
    $gs = $seed * 1000 + $i;
    $spec = $i % 2 ? 'easy/populist' : 'medium/populist';
    $players = [1 => Players::make($spec, null, $gs), -1 => Players::make($i % 3 ? 'easy/whig' : 'medium/whig', null, $gs + 1)];
    $rng = new \Random\Randomizer(new \Random\Engine\Mt19937($gs));
    $g = new Game();
    for ($j = 0; $j < 4; $j++) {
        $legal = $g->pos->legalMoves();
        $g->playInt($legal[$rng->getInt(0, count($legal) - 1)]);
    }
    while ($g->status === 'playing' && count($g->log) < 160) {
        $ply = count($g->log);
        $last = end($g->log);
        if ($ply >= 8 && ($ply % 3 === 0 || !empty($last['writ']) || !empty($last['count']))) {
            $emit($g, "g$gs-ply$ply");
        }
        $info = $players[$g->pos->side]->choose($g);
        if (!$info['move']) {
            break;
        }
        $g->playInt($info['move']);
    }
    fwrite(STDERR, "game $gs: " . count($g->log) . " plies, $k positions so far\n");
}
