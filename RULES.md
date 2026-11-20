# Parliament Chess — Rules of the Realm (v1.0)

*Vote King.* Every move is FIDE chess. Every crown is elected.

Parliament Chess adds one office to chess: the crown. It can pass, by vote, from the King to another piece of the same colour. Moves, captures, castling, en passant and promotion are unchanged. One new action, the **Writ**, starts an election, and filing it costs your whole turn.

## 1. The crown is an office

- Whoever holds the crown moves as a King and can be checkmated. Each side has exactly one crown holder at all times.
- After every count the board is still an ordinary chess position (one King a side, ordinary piece types), so ordinary chess knowledge still applies.

## 2. The Ledger: social mobility

Every piece keeps a permanent identity and a career record.

| Rule | Text |
| --- | --- |
| L1 One term | A piece that has worn the crown can never be elected again. The starting Kings have worn it. |
| L2 Abdication | A dethroned King becomes a pawn, a **Pretender**, on its own square. |
| L3 Pretender's ceiling | A Pretender promotes only to Rook, Bishop or Knight. |
| L4 Queen's bar | A Queen can never stand for the crown. (The **Regency** variant lifts this.) |
| L5 Placed, not promoted | A pawn put on its last rank by an election is stranded: it cannot move, but still votes. Only a pawn that *moves* there promotes. |
| L6 First-rank pawn | A pawn on its own first rank steps one square, never two. |

## 3. The electorate

Your parliament is your own pieces, minus the King and the Candidate. The opponent never votes in it.

- **Commons (popular vote):** each pawn casts 1 vote.
- **College (electoral vote):** Knight 2, Bishop 2, Rook 3.
- **Speaker:** the Queen votes only to break a College tie.
- **Proximity:** each voter backs whichever of Incumbent and Candidate is fewer king-steps away (Chebyshev distance). A distance tie backs the Incumbent.
- **Intimidation:** a voter attacked by any enemy piece at the count abstains.

## 4. An election, turn by turn

1. **Writ (your turn).** Name one Candidate: your own piece, not the King, not a Queen, never crowned, and not under attack. Nothing moves. Notation: `W@Nf5` (`W@e4` for a pawn).
2. **Campaign (their turn).** Your opponent makes one ordinary move, or files a Writ of their own. The Candidate was unattacked when named, so an ordinary reply cannot capture it, but its square can be attacked, and your voters can be captured or intimidated. (If the opponent's own count crowns a piece beside your Candidate, that new King may capture it; your count then fails.)
3. **Count (start of your next turn).** If the Candidate is gone or its square is attacked, the election fails. Otherwise both chambers are tallied:

| | Commons back the Candidate | Commons back the Incumbent |
| --- | --- | --- |
| **College backs the Candidate** | Coronation | Contested Coronation |
| **College backs the Incumbent** | Royal Swap | Defeat |

- **Coronation:** the Candidate becomes King; the old King becomes a Pretender pawn.
- **Contested Coronation:** the same, and the opponent gains one Writ.
- **Royal Swap:** King and Candidate exchange squares. Nobody changes rank.
- **Defeat:** nothing happens.

A result that would leave the new King in check, or give check to the enemy King, is **void** and counts as Defeat. When the crown moves, that side loses its castling rights.

4. **Your move.** You then move normally. Checkmate and stalemate are judged after the count.

## 5. Writs and timing

- Each side starts with **1 Writ** and gains 1 for every Knight, Bishop, Rook or Queen it loses (a *grievance*), holding at most **3**.
- **Coronation Peace:** no Writ before move 10, so opening theory is untouched.
- **Term:** six of your own turns must pass between your counts (a Writ needs 5 full moves since your last count).
- No Writ while in check, and one election pending per side.

## 6. Winning

Checkmate the current crown holder. Stalemate, threefold repetition, the fifty-move rule and insufficient material draw as in FIDE chess. A mate delivered during the opponent's campaign is final only after their count, because the count may carry the crown out of the net.

## 7. Example: a short game needs no vote

`1. f3 e5 2. g4 Qh4#` — Fool's Mate is still mate. No Writ can be filed before move 10, so every miniature of chess history survives.

## 8. Example: the crown escapes a check

From `6k1/5ppp/8/r7/8/2PPP3/1N6/7K w - - 0 20` White files `W@d3`. Black swings `Rh5+`, checking the King on h1. At the start of White's turn the count runs: c3 and e3 (Commons 2–0) and the Knight on b2 (College 2–0) all stand nearer d3 than h1, so it is a **Coronation**. The pawn on d3 is now King; the old King on h1 is a Pretender pawn, and the check is gone.

## 9. Variant switches

- **Elections off:** plain FIDE chess (the A/B baseline in the lab reports).
- **Regency:** Queens may stand for the crown (Lab Report III explains why it is off by default).

MIT License.
