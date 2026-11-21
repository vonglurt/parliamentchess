<?php
declare(strict_types=1);

// Parliament Chess test suite: php bin/test.php [--quick]
require __DIR__ . '/../src/autoload.php';

use Parliament\Engine\Game;
use Parliament\Engine\Position;

$quick = in_array('--quick', $argv, true);
$fails = 0;
$passes = 0;
function check(bool $ok, string $what): void
{
    global $fails, $passes;
    if ($ok) {
        $passes++;
        echo "  ok   $what\n";
    } else {
        $fails++;
        echo "  FAIL $what\n";
    }
}

function perft(Position $p, int $d): int
{
    if ($d === 0) {
        return 1;
    }
    $n = 0;
    foreach ($p->legalMoves() as $m) {
        $n += $d === 1 ? 1 : perft($p->after($m), $d - 1);
    }
    return $n;
}

echo "Perft (Writs cannot exist before move 10, so FIDE reference counts must match)\n";
$cases = [
    ['rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1', [20, 400, 8902, 197281]],
    ['r3k2r/p1ppqpb1/bn2pnp1/3PN3/1p2P3/2N2Q1p/PPPBBPPP/R3K2R w KQkq - 0 1', [48, 2039, 97862]],
    ['8/2p5/3p4/KP5r/1R3p1k/8/4P1P1/8 w - - 0 1', [14, 191, 2812, 43238]],
    ['r3k2r/Pppp1ppp/1b3nbN/nP6/BBP1P3/q4N2/Pp1P2PP/R2Q1RK1 w kq - 0 1', [6, 264, 9467]],
    ['rnbq1k1r/pp1Pbppp/2p5/8/2B5/8/PPP1NnPP/RNBQK2R w KQ - 1 8', [44, 1486, 62379]],
];
foreach ($cases as [$fen, $counts]) {
    $p = Position::fromFen($fen);
    foreach ($counts as $i => $want) {
        if ($quick && $want > 50000) {
            continue;
        }
        $t = microtime(true);
        $got = perft($p, $i + 1);
        check($got === $want, sprintf('perft %d = %d (want %d, %.2fs) %s', $i + 1, $got, $want, microtime(true) - $t, substr($fen, 0, 24)));
    }
}

echo "\nA short game needs no vote\n";
$g = new Game();
foreach (['f3', 'e5', 'g4', 'Qh4'] as $mv) {
    $g->play($mv);
}
check($g->status === 'checkmate' && $g->winner === -1, "Fool's mate: 1.f3 e5 2.g4 Qh4# is mate, 0-1 ({$g->log[3]['san']})");

echo "\nWrit conditions\n";
$p = Position::start();
check(!$p->canWrit(1), 'Coronation Peace: no Writ at move 1');
$base = '7k/8/8/8/8/8/8/K7 w - - 0 20';
$p = Position::fromFen('7k/8/8/8/4P3/3P1P2/8/K7 w - - 0 20');
check($p->canWrit(1), 'Writ allowed at move 20 with a Writ in hand');
$p2 = Position::fromFen('7k/8/8/8/4P3/3P1P2/8/K6r w - - 0 20');
check(!$p2->canWrit(1), 'no Writ while in check');
$p3 = Position::fromFen('7k/8/8/8/4P3/3P1P2/8/K7 w - - 0 20');
$p3->writs[1] = 0;
check(!$p3->canWrit(1), 'no Writ without a Writ in hand');
$p4 = Position::fromFen('7k/8/8/8/4P3/3P1P2/8/K7 w - - 0 20');
$p4->lastCount[1] = 17;
check(!$p4->canWrit(1), 'Term: no Writ three moves after the last count');
$p4->lastCount[1] = 15;
check($p4->canWrit(1), 'Term: Writ allowed five moves after the last count');
$p5 = Position::fromFen('7k/8/8/8/4P3/3PQP2/8/K7 w - - 0 20');
$cands = array_map([Position::class, 'sqName'], $p5->candidates(1));
check(!in_array('e3', $cands, true) && in_array('e4', $cands, true), "Queen's bar: the Queen is never a Candidate (" . implode(',', $cands) . ')');

/** Play a Writ, a Black reply and return the Count event. */
function election(string $fen, string $cand, string $reply): array
{
    $g = new Game($fen);
    $g->play('W@' . $cand);
    $g->play($reply);
    return [$g, $g->pos->lastEvent];
}

echo "\nThe four outcomes and the failures\n";
// Coronation: pawns d3 f3 and knight e5 all stand nearer e4 than a1.
[$g, $ev] = election('7k/p7/8/4N3/4P3/3P1P2/8/K7 w - - 0 20', 'e4', 'a6');
check($ev['outcome'] === 'coronation', 'Coronation: Commons 2-0, College 2-0 (' . Game::describeCount($ev) . ')');
check($g->pos->b[Position::sqIndex('e4')] === 6 && $g->pos->b[0] === 1, 'the Candidate is King on e4, the old King a pawn on a1');
check($g->pos->ksq[1] === Position::sqIndex('e4'), 'King square follows the crown');
$pid = $g->pos->id[0];
check((bool)($g->pos->lf[$pid] & Position::L_PRETENDER), 'the old King is a Pretender in the Ledger');
$a1moves = array_map([Game::class, 'uci'], array_filter($g->pos->legalMoves(), fn($m) => ($m & 63) === 0));
check($a1moves === ['a1a2'], 'L6: a pawn on its first rank steps one square only (' . implode(',', $a1moves) . ')');
check(!in_array(Position::sqIndex('e4'), $g->pos->candidates(1), true), 'L1: the new King can never stand again');

// Royal Swap: Commons back e4 (d3, f3), College backs a1 (knight b2).
[$g, $ev] = election('7k/p7/8/8/4P3/3P1P2/1N6/K7 w - - 0 20', 'e4', 'a6');
check($ev['outcome'] === 'swap', 'Royal Swap: Commons 2-0 Candidate, College 0-2 (' . Game::describeCount($ev) . ')');
check($g->pos->b[Position::sqIndex('e4')] === 6 && $g->pos->b[0] === 1 && !($g->pos->lf[$g->pos->id[0]] & Position::L_PRETENDER),
    'King and Candidate traded squares; nobody changed rank');

// Contested: College backs e4 (knight e5), Commons back a1 (a2, b2).
[$g, $ev] = election('7k/p7/8/4N3/4P3/8/PP6/K7 w - - 0 20', 'e4', 'a6');
check($ev['outcome'] === 'contested', 'Contested Coronation: College 2-0, Commons 0-2 (' . Game::describeCount($ev) . ')');
check($g->pos->writs[-1] === 2, 'the opponent gained a Writ (Black holds ' . $g->pos->writs[-1] . ')');

// Defeat: nobody near the Candidate.
[$g, $ev] = election('7k/p7/8/8/4P3/8/PP6/K7 w - - 0 20', 'e4', 'a6');
check($ev['outcome'] === 'defeat' && $g->pos->ksq[1] === 0, 'Defeat: the crown stays on a1');

// Failed: Black attacks the Candidate's square during the campaign.
[$g, $ev] = election('7k/p7/8/4N3/4P3/3P1P2/6r1/K7 w - - 0 20', 'e4', 'Rg4');
check($ev['outcome'] === 'failed' && $ev['reason'] === 'candidate square attacked', 'Failed: the Candidate was attacked during the campaign');

// Intimidation: Black's bishop attacks d3 and f3? Use a rook on the third rank: d3 and f3 abstain.
[$g, $ev] = election('7k/p7/8/4N3/4P3/3P1P2/7r/K7 w - - 0 20', 'e4', 'Rh3');
check(($ev['voters'][Position::sqIndex('f3')] ?? '') === 'X', 'Intimidation: an attacked voter abstains (f3 = ' . ($ev['voters'][Position::sqIndex('f3')] ?? '-') . ')');

// Void: a swap that puts a Rook on e1 would check the Black King on e8.
[$g, $ev] = election('4k3/p7/8/8/7R/6PP/3N4/4K3 w - - 0 20', 'h4', 'a6');
check($ev['outcome'] === 'void', 'Void: the ballot may not give check (' . Game::describeCount($ev) . ')');

// Captured: the Candidate is taken during the campaign.
$p7 = Position::fromFen('7k/8/3p4/4N3/4P3/3P1P2/8/K7 w - - 0 20');
check(!in_array(Position::sqIndex('e5'), $p7->candidates(1), true),
    'an attacked piece cannot stand, so the Candidate can never be captured in one reply, only attacked');

echo "\nThe Ledger\n";
$g = new Game('8/P6k/8/8/8/8/8/K7 w - - 0 20');
$g->pos->lf[$g->pos->id[Position::sqIndex('a7')]] |= Position::L_PRETENDER;
$promos = array_map([Game::class, 'uci'], $g->pos->legalMoves());
check(!in_array('a7a8q', $promos, true) && in_array('a7a8r', $promos, true), 'L3: a Pretender promotes to R, B or N only');
$p = Position::fromFen('7k/8/8/8/8/8/8/K3P3 w - - 0 20');
$p6 = Position::fromFen('P6k/8/8/8/8/8/8/K7 w - - 0 20');
$movesA8 = array_filter($p6->legalMoves(), fn($m) => ($m & 63) === 56 && !(($m >> 16) & Position::F_WRIT));
check(count($movesA8) === 0, 'L5: a pawn placed on its last rank is stranded');

echo "\nGrievance\n";
$g = new Game('r3k3/8/8/8/8/8/8/R3K3 w - - 0 20');
$g->play('Rxa8+');
check($g->pos->writs[-1] === 2, 'losing a Rook earns Black a Writ');

echo "\nThe election as an escape\n";
// White files a Writ for d3; Black's rook swings to h5 with check; the count carries the crown away.
$g = new Game('6k1/5ppp/8/r7/8/2PPP3/1N6/7K w - - 0 20');
$g->play('W@d3');
$g->play('Rh5');
$ev = $g->pos->lastEvent;
check(in_array($ev['outcome'], ['coronation', 'contested', 'swap'], true) && !$g->pos->inCheck(1),
    'a check delivered during the campaign is escaped by the count (' . Game::describeCount($ev) . ')');

echo "\nRecords replay\n";
$g = new Game('7k/p7/8/4N3/4P3/3P1P2/8/K7 w - - 0 20');
foreach (['W@e4', 'a6', 'a2', 'a5', 'Nf7+', 'Kg7'] as $mv) {
    $g->play($mv);
}
$copy = Game::fromArray(json_decode(json_encode($g->toArray()), true));
check($copy->pos->toFen() === $g->pos->toFen() && count($copy->log) === 6, 'a recording rebuilds the same game');
echo $g->pcn(['White' => 'test', 'Black' => 'test']);

echo "\nThe net's mind\n";
check(\Parliament\AI\Players::safeSpec('medium+net2:champion') && \Parliament\AI\Players::safeSpec('hard/populist+net2:g005')
    && \Parliament\AI\Players::safeSpec('easy'), 'the browser may ask for levels, styles and published nets');
check(!\Parliament\AI\Players::safeSpec('medium+net2:/etc/hosts') && !\Parliament\AI\Players::safeSpec('medium+net2:../models/g005')
    && !\Parliament\AI\Players::safeSpec('medium+net2'), 'but never for a path, or for the server\'s private default net');
// The fleet's code tarball carries no models/, so the champion may not exist here: skip, don't crash.
try {
    $champ = \Parliament\AI\Players::netPath('champion');
} catch (\InvalidArgumentException) {
    $champ = '';
}
if (is_file($champ)) {
    $net = \Parliament\AI\Net2::load($champ);
    $g = new Game();
    foreach (['e4', 'e5', 'Nf3', 'Nc6', 'Bb5'] as $mv) {
        $g->play($mv);
    }
    $p = $g->pos;
    $moves = $p->legalMoves();
    $t = $net->trace($p, $moves, $moves[3]);
    $same = abs($t['raw'] - $net->raw($p)) < 1e-3 && max(array_map(fn($a, $b) => abs($a - $b), $t['a2'], $net->trunk($p))) < 1e-3;
    check($same, 'the trace computes what the net computes (layer 2 and the value input)');
    $logits = $net->logits($p, $moves);
    $byLogit = array_column($t['policy'], 'logit');
    check(abs(max($logits) - $byLogit[0]) < 1e-2 && abs(array_sum(array_column($t['policy'], 'p')) - 1) < 1e-2,
        'the policy rows are the logits, best first, and their softmax sums to one');
    check(count(array_filter($t['policy'], fn($r) => isset($r['edges']))) >= 8 && isset($t['policy'][array_search($moves[3], array_column($t['policy'], 'm'))]['edges']),
        'the eight favourites and the move played carry their connections');
} else {
    echo "  (skipped: no published net in models/)\n";
}

echo "\n$passes passed, $fails failed\n";
exit($fails ? 1 : 0);
