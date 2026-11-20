<?php
declare(strict_types=1);

namespace Parliament\Server;

use Parliament\Engine\Game;
use Parliament\Engine\Position;

/** What a browser sees of a position: board, badges, politics, and (for the mover) every legal action. */
final class View
{
    public const B_CROWNED = 1;    // a King born as something else
    public const B_PRETENDER = 2;  // a dethroned King, now a pawn
    public const B_STRANDED = 4;   // a pawn placed on its last rank

    public static function frame(Position $p): array
    {
        $badges = array_fill(0, 64, 0);
        $born = array_fill(0, 64, 0);
        foreach ($p->b as $sq => $v) {
            if ($v === 0) {
                continue;
            }
            $id = $p->id[$sq];
            $t = abs($v);
            $born[$sq] = $p->born[$id] ?? $t;
            $lf = $p->lf[$id] ?? 0;
            if ($t === Position::K && ($p->born[$id] ?? 6) !== Position::K) {
                $badges[$sq] |= self::B_CROWNED;
            }
            if ($t === Position::P && ($lf & Position::L_PRETENDER)) {
                $badges[$sq] |= self::B_PRETENDER;
            }
            if ($t === Position::P && ($sq >> 3) === ($v > 0 ? 7 : 0)) {
                $badges[$sq] |= self::B_STRANDED;
            }
        }
        $pending = [];
        foreach ([1, -1] as $c) {
            $pending[$c] = -1;
            if ($p->pending[$c]) {
                $sq = array_search($p->pending[$c], $p->id, true);
                $pending[$c] = $sq === false ? -1 : $sq;
            }
        }
        return [
            'board' => $p->b, 'badges' => $badges, 'born' => $born, 'fen' => $p->toFen(),
            'side' => $p->side, 'full' => $p->full, 'check' => $p->inCheck(), 'ksq' => [$p->ksq[1], $p->ksq[-1]],
            'writs' => [$p->writs[1], $p->writs[-1]],
            'pending' => [$pending[1], $pending[-1]],
            'termReady' => [max(0, $p->lastCount[1] + Position::TERM - $p->full), max(0, $p->lastCount[-1] + Position::TERM - $p->full)],
            'peace' => max(0, Position::PEACE_UNTIL - $p->full),
            'lastMove' => $p->lastMove, 'event' => $p->lastEvent, 'elections' => $p->opt['elections'],
        ];
    }

    /** Legal actions for the side to move, with a live poll for every eligible Candidate. */
    public static function actions(Position $p): array
    {
        $moves = [];
        $writs = [];
        foreach ($p->legalMoves() as $m) {
            $from = $m & 63;
            if (($m >> 16) & Position::F_WRIT) {
                $poll = $p->poll($p->side, $from);
                $writs[] = ['sq' => $from, 'uci' => Game::uci($m), 'poll' => [
                    'commons' => $poll['commons'], 'college' => $poll['college'], 'outcome' => $poll['outcome'],
                    'voters' => $poll['voters']]];
                continue;
            }
            $moves[] = ['uci' => Game::uci($m), 'from' => $from, 'to' => ($m >> 6) & 63, 'promo' => ($m >> 12) & 7];
        }
        return ['moves' => $moves, 'writs' => $writs, 'canWrit' => (bool)$writs];
    }

    /** Every ply of a recording as a frame: what the replay viewer and the film draw. */
    public static function replay(Game $g): array
    {
        $frames = [];
        $r = new Game($g->initialFen, $g->opt);
        $frames[] = self::frame($r->pos) + ['ply' => 0, 'san' => '', 'by' => 0, 't' => 0];
        foreach ($g->log as $e) {
            $m = $r->parse($e['uci']);
            if ($m === null) {
                break;
            }
            $r->playInt($m);
            $frames[] = self::frame($r->pos) + ['ply' => $e['ply'], 'san' => $e['san'], 'by' => $e['by'], 't' => $e['t'],
                'score' => $e['score'] ?? null, 'source' => $e['source'] ?? null];
            if ($r->status !== 'playing') {
                break;
            }
        }
        return $frames;
    }
}
