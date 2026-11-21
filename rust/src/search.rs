//! Book-Return Search without the book (src/AI/Search.php): iterative deepening to a fixed depth with
//! a full window at the root, alpha-beta with one-ply check extensions, a fail-soft quiescence search,
//! a transposition table and the game's repetition counts. Move ordering, the table and the history
//! work exactly as in PHP, so the same position gives the same score for every root move.

use crate::eval::{Evaluator, VAL};
use crate::game::Game;
use crate::position::{Position, F_CAPTURE, F_WRIT};
use crate::rng::Rng;
use std::collections::HashMap;
use std::hash::{BuildHasherDefault, Hasher};

pub const MATE: i32 = 100_000;

/// Zobrist keys are already uniform random bits: the table needs no hashing of its own, only the key.
/// (The default SipHash, built to resist adversarial keys, was a fifth of the search's time.)
#[derive(Default)]
pub struct KeyHasher(u64);

impl Hasher for KeyHasher {
    fn finish(&self) -> u64 {
        self.0
    }
    fn write(&mut self, bytes: &[u8]) {
        for &b in bytes {
            self.0 = (self.0 << 8) | b as u64;
        }
    }
    fn write_i64(&mut self, x: i64) {
        self.0 = x as u64;
    }
}

pub type KeyMap<V> = HashMap<i64, V, BuildHasherDefault<KeyHasher>>;

pub struct Search {
    pub ev: Evaluator,
    pub depth: i32,
    /// Centipawns of slack: moves this close to the best may be chosen at random (variety).
    pub margin: i32,
    pub nodes: u64,
    pub max_nodes: u64,
    pub files: bool,
    tt: KeyMap<(i32, i32, i8, u32)>,
    history: KeyMap<i32>,
    rng: Rng,
    /// Every root move's exact score from the last search, best first (action values, for labels).
    pub root_scores: Vec<(u32, i32)>,
}

pub struct Choice {
    pub mv: u32,
    pub score: i32,
}

impl Search {
    pub fn new(ev: Evaluator, depth: i32, margin: i32, seed: u64) -> Search {
        let files = ev.style.files;
        Search {
            ev,
            depth,
            margin,
            nodes: 0,
            max_nodes: 400_000,
            files,
            tt: KeyMap::default(),
            history: KeyMap::default(),
            rng: Rng::new(seed),
            root_scores: Vec::new(),
        }
    }

    /// Pick a move for the side to move in g.
    pub fn choose(&mut self, g: &Game) -> Choice {
        self.choose_from(&g.pos, &g.seen)
    }

    pub fn choose_from(&mut self, p: &Position, seen: &HashMap<i64, i32>) -> Choice {
        self.nodes = 0;
        self.tt.clear();
        self.history = seen.iter().map(|(&k, &v)| (k, v)).collect();
        let mut moves = self.root_moves(p);
        if moves.is_empty() {
            self.root_scores.clear();
            return Choice { mv: 0, score: 0 };
        }
        let mut scores: Vec<(u32, i32)> = Vec::new();
        for d in 1..=self.depth {
            scores.clear();
            for &m in &moves {
                // Full window at the root: every score is exact.
                let c = p.after(m);
                let ext = if c.in_check() { 1 } else { 0 };
                let s = -self.negamax(&c, d - 1 + ext, -MATE - 1, MATE + 1, 1);
                scores.push((m, s));
            }
            // PHP's arsort: by score, best first, ties in their present order (a stable sort).
            scores.sort_by(|a, b| b.1.cmp(&a.1));
            moves = scores.iter().map(|x| x.0).collect();
            if self.nodes > self.max_nodes {
                break;
            }
        }
        self.root_scores = scores.clone();
        let (best_move, best) = scores[0];
        let pick = if best.abs() > 5000 || self.margin == 0 {
            best_move
        } else {
            // Variety among near-equal moves, but a Writ is filed only when it is the best move outright.
            let near: Vec<u32> = scores
                .iter()
                .filter(|&&(m, s)| s >= best - self.margin && (m == best_move || (m >> 16) & F_WRIT == 0))
                .map(|x| x.0)
                .collect();
            near[self.rng.int(0, near.len() - 1)]
        };
        let score = scores.iter().find(|x| x.0 == pick).map(|x| x.1).unwrap_or(best);
        Choice { mv: pick, score }
    }

    fn root_moves(&self, p: &Position) -> Vec<u32> {
        let ordered = self.order(p, p.moves(false), 0, false);
        ordered.into_iter().filter(|&m| (self.files || (m >> 16) & F_WRIT == 0) && p.is_legal(m)).collect()
    }

    fn negamax(&mut self, p: &Position, d: i32, mut alpha: i32, beta: i32, ply: i32) -> i32 {
        self.nodes += 1;
        let key = p.hash();
        if self.history.get(&key).copied().unwrap_or(0) >= 2 || p.half >= 100 {
            return 0;
        }
        if d <= 0 {
            return self.qsearch(p, alpha, beta, ply, 0);
        }
        let mut tt_move = 0;
        if let Some(&(td, ts, tf, tm)) = self.tt.get(&key) {
            tt_move = tm;
            if td >= d && (tf == 0 || (tf == 1 && ts >= beta) || (tf == -1 && ts <= alpha)) {
                return ts;
            }
        }
        let a0 = alpha;
        let mut best = -MATE - 1;
        let mut best_move = 0;
        let mut legal = 0;
        *self.history.entry(key).or_insert(0) += 1;
        let policy = self.ev.net2.is_some();
        for m in self.order(p, p.moves(false), tt_move, policy) {
            if !p.is_legal(m) {
                continue;
            }
            if !self.files && (ply & 1) == 0 && (m >> 16) & F_WRIT != 0 {
                continue; // a Monarchist never files; its opponent still may
            }
            legal += 1;
            let c = p.after(m);
            let ext = if ply < 4 && c.in_check() { 1 } else { 0 };
            let s = -self.negamax(&c, d - 1 + ext, -beta, -alpha, ply + 1);
            if s > best {
                best = s;
                best_move = m;
            }
            if s > alpha {
                alpha = s;
            }
            if alpha >= beta {
                break;
            }
        }
        *self.history.get_mut(&key).unwrap() -= 1;
        if legal == 0 {
            return if p.in_check() { -MATE + ply } else { 0 };
        }
        let flag = if best <= a0 {
            -1
        } else if best >= beta {
            1
        } else {
            0
        };
        self.tt.insert(key, (d, best, flag, best_move));
        best
    }

    fn qsearch(&mut self, p: &Position, mut alpha: i32, beta: i32, ply: i32, qd: i32) -> i32 {
        self.nodes += 1;
        if p.in_check() && qd < 4 {
            // Evasions: every legal move, so a mate in the quiescence is still a mate.
            let mut best = -MATE + ply;
            for m in self.order(p, p.moves(false), 0, false) {
                if (m >> 16) & F_WRIT != 0 || !p.is_legal(m) {
                    continue;
                }
                let s = -self.qsearch(&p.after(m), -beta, -alpha, ply + 1, qd + 1);
                if s > best {
                    best = s;
                }
                if s > alpha {
                    alpha = s;
                }
                if alpha >= beta {
                    break;
                }
            }
            return best;
        }
        let stand = self.ev.evaluate(p);
        if stand >= beta || qd >= 6 {
            return stand;
        }
        let mut best = stand;
        if stand > alpha {
            alpha = stand;
        }
        for m in self.order(p, p.moves(true), 0, false) {
            // Delta pruning: a capture that cannot lift alpha is not worth a look.
            let victim = p.b[((m >> 6) & 63) as usize].unsigned_abs() as usize;
            if stand + VAL[if victim == 0 { 1 } else { victim }] + 200 < alpha && (m >> 12) & 7 == 0 {
                continue;
            }
            if !p.is_legal(m) {
                continue;
            }
            let s = -self.qsearch(&p.after(m), -beta, -alpha, ply + 1, qd + 1);
            if s >= beta {
                return s;
            }
            if s > best {
                best = s;
            }
            if s > alpha {
                alpha = s;
            }
        }
        best
    }

    /// TT move, then captures by MVV-LVA, promotions, quiet moves, and Writs last. With a policy head,
    /// quiet moves and Writs are ordered by the net's logits (4000 + 100 x logit). A stable sort, as
    /// PHP's usort is.
    fn order(&self, p: &Position, moves: Vec<u32>, tt_move: u32, policy: bool) -> Vec<u32> {
        let logits: Option<Vec<f64>> = if policy && moves.len() > 1 {
            self.ev.net2.as_ref().map(|n| n.logits(p, &moves))
        } else {
            None
        };
        let mut keyed: Vec<(f64, u32)> = moves
            .iter()
            .enumerate()
            .map(|(i, &m)| {
                let fl = m >> 16;
                let k = if m == tt_move {
                    100_000.0
                } else if fl & F_WRIT != 0 {
                    match &logits {
                        Some(l) => 4000.0 + 100.0 * l[i],
                        None => -1000.0,
                    }
                } else if fl & F_CAPTURE != 0 {
                    let victim = p.b[((m >> 6) & 63) as usize].unsigned_abs() as usize;
                    let victim = if victim == 0 { 1 } else { victim };
                    let attacker = p.b[(m & 63) as usize].unsigned_abs() as usize;
                    (10_000 + 10 * VAL[victim] - VAL[attacker] / 10) as f64
                } else if (m >> 12) & 7 != 0 {
                    9000.0
                } else {
                    match &logits {
                        Some(l) => 4000.0 + 100.0 * l[i],
                        None => 0.0,
                    }
                };
                (k, m)
            })
            .collect();
        keyed.sort_by(|a, b| b.0.partial_cmp(&a.0).unwrap_or(std::cmp::Ordering::Equal));
        keyed.into_iter().map(|x| x.1).collect()
    }
}
