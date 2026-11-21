//! One Parliament Chess position and every rule of the game: src/Engine/Position.php, operation for
//! operation, so that move lists come out in the same order and every position hashes to PHP's key.
//!
//! Squares run a1 = 0 .. h8 = 63. A piece is +type for White, -type for Black, P=1 N=2 B=3 R=4 Q=5 K=6.
//! A move is one u32: from | to << 6 | promo << 12 | flags << 16; a Writ is from = to = the Candidate.

use crate::zobrist::{ZC, ZE, ZP, ZPEND, ZS, ZW};
use std::sync::OnceLock;

pub const P: i8 = 1;
pub const N: i8 = 2;
pub const B: i8 = 3;
pub const R: i8 = 4;
pub const Q: i8 = 5;
pub const K: i8 = 6;

pub const F_CAPTURE: u32 = 1;
pub const F_DOUBLE: u32 = 2;
pub const F_EP: u32 = 4;
pub const F_CASTLE: u32 = 8;
pub const F_PROMO: u32 = 16;
pub const F_WRIT: u32 = 32;

pub const L_REIGNED: u8 = 1;
pub const L_PRETENDER: u8 = 2;
pub const L_WAS_QUEEN: u8 = 4;

pub const PEACE_UNTIL: i32 = 10;
pub const TERM: i32 = 5;
pub const WRIT_CAP: i32 = 3;
pub const WRIT_START: i32 = 1;
pub const VOTES: [i32; 7] = [0, 1, 2, 2, 3, 0, 0];

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Outcome {
    Coronation,
    Contested,
    Swap,
    Defeat,
    Failed,
    Void,
}

impl Outcome {
    pub fn name(self) -> &'static str {
        match self {
            Outcome::Coronation => "coronation",
            Outcome::Contested => "contested",
            Outcome::Swap => "swap",
            Outcome::Defeat => "defeat",
            Outcome::Failed => "failed",
            Outcome::Void => "void",
        }
    }
    pub fn moves_crown(self) -> bool {
        matches!(self, Outcome::Coronation | Outcome::Contested | Outcome::Swap)
    }
}

pub struct Tables {
    kn: [[u8; 8]; 64],
    knl: [u8; 64],
    kg: [[u8; 8]; 64],
    kgl: [u8; 64],
    /// Eight rays a square: N, S, E, W, NE, NW, SE, SW, nearest square first.
    rays: [[[u8; 7]; 8]; 64],
    rayl: [[u8; 8]; 64],
    pub cmask: [u8; 64],
}

impl Tables {
    /// Knight targets of sq.
    #[inline]
    pub fn kn(&self, sq: usize) -> &[u8] {
        &self.kn[sq][..self.knl[sq] as usize]
    }
    /// King steps from sq.
    #[inline]
    pub fn kg(&self, sq: usize) -> &[u8] {
        &self.kg[sq][..self.kgl[sq] as usize]
    }
    /// Ray d from sq (0..4 orthogonal, 4..8 diagonal).
    #[inline]
    pub fn ray(&self, sq: usize, d: usize) -> &[u8] {
        &self.rays[sq][d][..self.rayl[sq][d] as usize]
    }
}

pub fn tables() -> &'static Tables {
    static T: OnceLock<Tables> = OnceLock::new();
    T.get_or_init(|| {
        let dirs: [(i32, i32); 8] = [(0, 1), (0, -1), (1, 0), (-1, 0), (1, 1), (-1, 1), (1, -1), (-1, -1)];
        let jumps: [(i32, i32); 8] = [(1, 2), (2, 1), (2, -1), (1, -2), (-1, -2), (-2, -1), (-2, 1), (-1, 2)];
        let mut t = Tables { kn: [[0; 8]; 64], knl: [0; 64], kg: [[0; 8]; 64], kgl: [0; 64], rays: [[[0; 7]; 8]; 64], rayl: [[0; 8]; 64], cmask: [15; 64] };
        for sq in 0..64usize {
            let (f, r) = ((sq & 7) as i32, (sq >> 3) as i32);
            for (df, dr) in jumps {
                let (nf, nr) = (f + df, r + dr);
                if (0..8).contains(&nf) && (0..8).contains(&nr) {
                    t.kn[sq][t.knl[sq] as usize] = (nr * 8 + nf) as u8;
                    t.knl[sq] += 1;
                }
            }
            for (d, (df, dr)) in dirs.into_iter().enumerate() {
                let (mut nf, mut nr) = (f + df, r + dr);
                if (0..8).contains(&nf) && (0..8).contains(&nr) {
                    t.kg[sq][t.kgl[sq] as usize] = (nr * 8 + nf) as u8;
                    t.kgl[sq] += 1;
                }
                while (0..8).contains(&nf) && (0..8).contains(&nr) {
                    t.rays[sq][d][t.rayl[sq][d] as usize] = (nr * 8 + nf) as u8;
                    t.rayl[sq][d] += 1;
                    nf += df;
                    nr += dr;
                }
            }
        }
        t.cmask[0] = 15 & !2;
        t.cmask[4] = 15 & !3;
        t.cmask[7] = 15 & !1;
        t.cmask[56] = 15 & !8;
        t.cmask[60] = 15 & !12;
        t.cmask[63] = 15 & !4;
        t
    })
}

/// Colour to array slot: White 0, Black 1.
#[inline]
pub fn ci(c: i8) -> usize {
    if c == 1 {
        0
    } else {
        1
    }
}

#[derive(Clone, Debug)]
pub struct Poll {
    pub commons: [i32; 2],
    pub college: [i32; 2],
    pub speaker: Option<usize>,
    pub outcome: Outcome,
}

#[derive(Clone)]
pub struct Position {
    pub b: [i8; 64],
    pub id: [u8; 64],
    /// Ledger flags by identity.
    pub lf: [u8; 64],
    /// The type each identity was born as.
    pub born: [u8; 64],
    pub side: i8,
    pub castle: u8,
    pub ep: i32,
    pub half: i32,
    pub full: i32,
    pub ksq: [usize; 2],
    pub writs: [i32; 2],
    pub last_count: [i32; 2],
    /// Identity of each colour's pending Candidate, 0 = none.
    pub pending: [u8; 2],
    pub next_id: u8,
    pub elections: bool,
    pub queen_eligible: bool,
    pub last_outcome: Option<Outcome>,
}

impl Position {
    pub fn start() -> Position {
        Position::from_fen("rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1").unwrap()
    }

    pub fn from_fen(fen: &str) -> Result<Position, String> {
        let parts: Vec<&str> = fen.split_whitespace().collect();
        let rows: Vec<&str> = parts.first().ok_or("empty FEN")?.split('/').collect();
        if rows.len() != 8 {
            return Err(format!("bad FEN: {fen}"));
        }
        let mut p = Position {
            b: [0; 64],
            id: [0; 64],
            lf: [0; 64],
            born: [0; 64],
            side: 1,
            castle: 15,
            ep: -1,
            half: 0,
            full: 1,
            ksq: [4, 60],
            writs: [WRIT_START, WRIT_START],
            last_count: [-100, -100],
            pending: [0, 0],
            next_id: 1,
            elections: true,
            queen_eligible: false,
            last_outcome: None,
        };
        for (i, row) in rows.iter().enumerate() {
            let r = 7 - i as i32;
            let mut f = 0i32;
            for ch in row.chars() {
                if ('1'..='8').contains(&ch) {
                    f += ch as i32 - '0' as i32;
                    continue;
                }
                let t = match ch.to_ascii_lowercase() {
                    'p' => 1,
                    'n' => 2,
                    'b' => 3,
                    'r' => 4,
                    'q' => 5,
                    'k' => 6,
                    _ => return Err(format!("bad FEN piece {ch}")),
                };
                let sq = (r * 8 + f) as usize;
                p.b[sq] = if ch.is_ascii_uppercase() { t } else { -t };
                f += 1;
            }
        }
        for c in [1i8, -1] {
            for sq in 0..64 {
                let v = p.b[sq];
                if v != 0 && (v > 0) == (c > 0) {
                    let pid = p.next_id;
                    p.next_id += 1;
                    p.id[sq] = pid;
                    p.born[pid as usize] = v.unsigned_abs();
                    p.lf[pid as usize] = if v.abs() == K { L_REIGNED } else if v.abs() == Q { L_WAS_QUEEN } else { 0 };
                    if v.abs() == K {
                        p.ksq[ci(c)] = sq;
                    }
                }
            }
        }
        p.side = if parts.get(1).copied().unwrap_or("w") == "b" { -1 } else { 1 };
        let cs = parts.get(2).copied().unwrap_or("-");
        p.castle = (cs.contains('K') as u8) | ((cs.contains('Q') as u8) << 1) | ((cs.contains('k') as u8) << 2) | ((cs.contains('q') as u8) << 3);
        let eps = parts.get(3).copied().unwrap_or("-");
        p.ep = if eps == "-" { -1 } else { sq_index(eps) as i32 };
        p.half = parts.get(4).and_then(|x| x.parse().ok()).unwrap_or(0);
        p.full = parts.get(5).and_then(|x| x.parse().ok()).unwrap_or(1);
        Ok(p)
    }

    pub fn to_fen(&self) -> String {
        let chars = [' ', 'p', 'n', 'b', 'r', 'q', 'k'];
        let mut rows = Vec::new();
        for r in (0..8).rev() {
            let mut row = String::new();
            let mut empty = 0;
            for f in 0..8 {
                let v = self.b[r * 8 + f];
                if v == 0 {
                    empty += 1;
                    continue;
                }
                if empty > 0 {
                    row.push_str(&empty.to_string());
                    empty = 0;
                }
                let ch = chars[v.unsigned_abs() as usize];
                row.push(if v > 0 { ch.to_ascii_uppercase() } else { ch });
            }
            if empty > 0 {
                row.push_str(&empty.to_string());
            }
            rows.push(row);
        }
        let mut cs = String::new();
        for (bit, ch) in [(1, 'K'), (2, 'Q'), (4, 'k'), (8, 'q')] {
            if self.castle & bit != 0 {
                cs.push(ch);
            }
        }
        format!(
            "{} {} {} {} {} {}",
            rows.join("/"),
            if self.side == 1 { "w" } else { "b" },
            if cs.is_empty() { "-".to_string() } else { cs },
            if self.ep >= 0 { sq_name(self.ep as usize) } else { "-".to_string() },
            self.half,
            self.full
        )
    }

    // ------------------------------------------------------------------ attacks

    /// Is sq attacked by colour `by` on board b?
    pub fn attacked(b: &[i8; 64], sq: usize, by: i8) -> bool {
        let t = tables();
        let f = sq & 7;
        if by == 1 {
            if f > 0 && sq >= 9 && b[sq - 9] == 1 {
                return true;
            }
            if f < 7 && sq >= 7 && b[sq - 7] == 1 {
                return true;
            }
        } else {
            if f > 0 && sq + 7 < 64 && b[sq + 7] == -1 {
                return true;
            }
            if f < 7 && sq + 9 < 64 && b[sq + 9] == -1 {
                return true;
            }
        }
        let n = 2 * by;
        if t.kn(sq).iter().any(|&x| b[x as usize] == n) {
            return true;
        }
        let k = 6 * by;
        if t.kg(sq).iter().any(|&x| b[x as usize] == k) {
            return true;
        }
        let (r, q, bb) = (4 * by, 5 * by, 3 * by);
        for d in 0..4 {
            for &x in t.ray(sq, d) {
                let v = b[x as usize];
                if v != 0 {
                    if v == r || v == q {
                        return true;
                    }
                    break;
                }
            }
        }
        for d in 4..8 {
            for &x in t.ray(sq, d) {
                let v = b[x as usize];
                if v != 0 {
                    if v == bb || v == q {
                        return true;
                    }
                    break;
                }
            }
        }
        false
    }

    pub fn in_check_of(&self, c: i8) -> bool {
        Position::attacked(&self.b, self.ksq[ci(c)], -c)
    }

    pub fn in_check(&self) -> bool {
        self.in_check_of(self.side)
    }

    // ------------------------------------------------------------------ moves

    /// Pseudo-legal moves for the side to move (Writs included unless captures only).
    pub fn moves(&self, caps_only: bool) -> Vec<u32> {
        let t = tables();
        let b = &self.b;
        let s = self.side;
        let mut out = Vec::with_capacity(64);
        for sq in 0..64usize {
            let v = b[sq];
            if v == 0 || (v > 0) != (s > 0) {
                continue;
            }
            let ty = v * s;
            if ty == 1 {
                self.pawn_moves(sq, &mut out, caps_only);
                continue;
            }
            if ty == 2 || ty == 6 {
                let targets = if ty == 2 { t.kn(sq) } else { t.kg(sq) };
                for &to in targets {
                    let to = to as usize;
                    let w = b[to];
                    if w == 0 {
                        if !caps_only {
                            out.push(sq as u32 | ((to as u32) << 6));
                        }
                    } else if w * s < 0 {
                        out.push(sq as u32 | ((to as u32) << 6) | (F_CAPTURE << 16));
                    }
                }
                if ty == 6 && !caps_only {
                    self.castles(sq, &mut out);
                }
                continue;
            }
            let d0 = if ty == 3 { 4 } else { 0 };
            let d1 = if ty == 4 { 4 } else { 8 };
            for d in d0..d1 {
                for &to in t.ray(sq, d) {
                    let to = to as usize;
                    let w = b[to];
                    if w == 0 {
                        if !caps_only {
                            out.push(sq as u32 | ((to as u32) << 6));
                        }
                        continue;
                    }
                    if w * s < 0 {
                        out.push(sq as u32 | ((to as u32) << 6) | (F_CAPTURE << 16));
                    }
                    break;
                }
            }
        }
        if !caps_only && self.can_writ(s) {
            for sq in self.candidates(s) {
                out.push(sq as u32 | ((sq as u32) << 6) | (F_WRIT << 16));
            }
        }
        out
    }

    fn pawn_moves(&self, sq: usize, out: &mut Vec<u32>, caps_only: bool) {
        let b = &self.b;
        let s = self.side as i32;
        let r = (sq >> 3) as i32;
        let last = if s == 1 { 7 } else { 0 };
        if r == last {
            return; // L5: a pawn placed on its last rank is stranded
        }
        let fwd = (sq as i32 + 8 * s) as usize;
        let is_promo = (fwd >> 3) as i32 == last;
        let promos: &[u32] = if is_promo {
            if self.lf[self.id[sq] as usize] & L_PRETENDER != 0 {
                &[4, 3, 2]
            } else {
                &[5, 4, 3, 2]
            }
        } else {
            &[0]
        };
        if (!caps_only || is_promo) && b[fwd] == 0 {
            for &pr in promos {
                out.push(sq as u32 | ((fwd as u32) << 6) | (pr << 12) | ((if pr != 0 { F_PROMO } else { 0 }) << 16));
            }
            // L6: a pawn on its own first rank never double-steps; the usual start rank does.
            if !caps_only && r == (if s == 1 { 1 } else { 6 }) {
                let f2 = (fwd as i32 + 8 * s) as usize;
                if b[f2] == 0 {
                    out.push(sq as u32 | ((f2 as u32) << 6) | (F_DOUBLE << 16));
                }
            }
        }
        let f = (sq & 7) as i32;
        for df in [-1i32, 1] {
            let nf = f + df;
            if !(0..=7).contains(&nf) {
                continue;
            }
            let to = (fwd as i32 + df) as usize;
            let w = b[to];
            if (w as i32) * s < 0 {
                for &pr in promos {
                    out.push(sq as u32 | ((to as u32) << 6) | (pr << 12) | ((F_CAPTURE | if pr != 0 { F_PROMO } else { 0 }) << 16));
                }
            } else if w == 0 && to as i32 == self.ep {
                out.push(sq as u32 | ((to as u32) << 6) | ((F_CAPTURE | F_EP) << 16));
            }
        }
    }

    fn castles(&self, sq: usize, out: &mut Vec<u32>) {
        let b = &self.b;
        let s = self.side;
        if s == 1 && sq == 4 && (self.castle & 3) != 0 {
            if self.in_check_of(1) {
                return;
            }
            if (self.castle & 1) != 0 && b[5] == 0 && b[6] == 0 && b[7] == 4 && !Position::attacked(b, 5, -1) && !Position::attacked(b, 6, -1) {
                out.push(4 | (6 << 6) | (F_CASTLE << 16));
            }
            if (self.castle & 2) != 0
                && b[3] == 0
                && b[2] == 0
                && b[1] == 0
                && b[0] == 4
                && !Position::attacked(b, 3, -1)
                && !Position::attacked(b, 2, -1)
            {
                out.push(4 | (2 << 6) | (F_CASTLE << 16));
            }
        } else if s == -1 && sq == 60 && (self.castle & 12) != 0 {
            if self.in_check_of(-1) {
                return;
            }
            if (self.castle & 4) != 0 && b[61] == 0 && b[62] == 0 && b[63] == -4 && !Position::attacked(b, 61, 1) && !Position::attacked(b, 62, 1) {
                out.push(60 | (62 << 6) | (F_CASTLE << 16));
            }
            if (self.castle & 8) != 0
                && b[59] == 0
                && b[58] == 0
                && b[57] == 0
                && b[56] == -4
                && !Position::attacked(b, 59, 1)
                && !Position::attacked(b, 58, 1)
            {
                out.push(60 | (58 << 6) | (F_CASTLE << 16));
            }
        }
    }

    /// Does a pseudo-legal move keep the mover's King out of check?
    pub fn is_legal(&self, m: u32) -> bool {
        let fl = m >> 16;
        if fl & F_WRIT != 0 {
            return true; // can_writ() already required "not in check"
        }
        let from = (m & 63) as usize;
        let to = ((m >> 6) & 63) as usize;
        let s = self.side;
        let mut b = self.b;
        let v = b[from];
        b[to] = v;
        b[from] = 0;
        if fl & F_EP != 0 {
            b[(to as i32 - 8 * s as i32) as usize] = 0;
        }
        let k = if v * s == 6 { to } else { self.ksq[ci(s)] };
        !Position::attacked(&b, k, -s)
    }

    pub fn legal_moves(&self) -> Vec<u32> {
        self.moves(false).into_iter().filter(|&m| self.is_legal(m)).collect()
    }

    // ------------------------------------------------------------------ elections

    pub fn can_writ(&self, c: i8) -> bool {
        let i = ci(c);
        self.elections
            && self.writs[i] > 0
            && self.full >= PEACE_UNTIL
            && self.pending[i] == 0
            && self.full - self.last_count[i] >= TERM
            && !self.in_check_of(c)
    }

    /// Squares of colour c's eligible Candidates: not King, not Queen, never crowned, not attacked.
    pub fn candidates(&self, c: i8) -> Vec<usize> {
        let mut out = Vec::new();
        for sq in 0..64 {
            let v = self.b[sq];
            if v == 0 || (v > 0) != (c > 0) {
                continue;
            }
            let t = v * c;
            if t == K || (t == Q && !self.queen_eligible) {
                continue;
            }
            if self.lf[self.id[sq] as usize] & L_REIGNED != 0 {
                continue;
            }
            if Position::attacked(&self.b, sq, -c) {
                continue;
            }
            out.push(sq);
        }
        out
    }

    /// The tally if colour c's election were counted now with the Candidate on cand.
    pub fn poll(&self, c: i8, cand: usize) -> Poll {
        let b = &self.b;
        let k = self.ksq[ci(c)];
        let (kf, kr) = ((k & 7) as i32, (k >> 3) as i32);
        let (cf, cr) = ((cand & 7) as i32, (cand >> 3) as i32);
        let mut commons = [0; 2];
        let mut college = [0; 2];
        let mut speaker = None;
        for sq in 0..64 {
            let v = b[sq];
            if v == 0 || (v > 0) != (c > 0) || sq == k || sq == cand {
                continue;
            }
            let t = v * c;
            if t == K {
                continue;
            }
            if Position::attacked(b, sq, -c) {
                continue;
            }
            let (f, r) = ((sq & 7) as i32, (sq >> 3) as i32);
            let dk = (f - kf).abs().max((r - kr).abs());
            let dc = (f - cf).abs().max((r - cr).abs());
            let side = if dc < dk { 0 } else { 1 };
            if t == P {
                commons[side] += 1;
            } else if t == Q {
                speaker = Some(side);
            } else {
                college[side] += VOTES[t as usize];
            }
        }
        let college_win = if college[0] > college[1] {
            0
        } else if college[0] < college[1] {
            1
        } else {
            speaker.unwrap_or(1)
        };
        let popular_win = if commons[0] > commons[1] { 0 } else { 1 };
        let outcome = match (college_win, popular_win) {
            (0, 0) => Outcome::Coronation,
            (0, _) => Outcome::Contested,
            (_, 0) => Outcome::Swap,
            _ => Outcome::Defeat,
        };
        Poll { commons, college, speaker, outcome }
    }

    /// Count colour c's pending election now (the evaluation scores a pending Writ exactly).
    pub fn count_now(&mut self, c: i8) {
        if self.pending[ci(c)] != 0 {
            self.count(c);
        }
    }

    fn count(&mut self, c: i8) {
        let i = ci(c);
        let o = ci(-c);
        let cid = self.pending[i];
        self.pending[i] = 0;
        self.last_count[i] = self.full;
        let csq = (0..64).find(|&sq| self.id[sq] == cid);
        let outcome = match csq {
            None => Outcome::Failed,
            Some(csq) if Position::attacked(&self.b, csq, -c) => Outcome::Failed,
            Some(csq) => {
                let out = self.poll(c, csq).outcome;
                if out.moves_crown() {
                    let save = (self.b, self.id, self.lf, self.ksq, self.castle);
                    let k = self.ksq[i];
                    if out == Outcome::Swap {
                        self.b.swap(k, csq);
                        self.id.swap(k, csq);
                    } else {
                        let kid = self.id[k] as usize;
                        self.b[csq] = K * c;
                        self.lf[cid as usize] |= L_REIGNED;
                        self.b[k] = P * c;
                        self.lf[kid] |= L_PRETENDER | L_REIGNED;
                    }
                    self.ksq[i] = csq;
                    self.castle &= if c == 1 { !3u8 } else { !12u8 };
                    // A result may not leave the new King in check, nor give check by ballot.
                    if Position::attacked(&self.b, csq, -c) || Position::attacked(&self.b, self.ksq[o], c) {
                        (self.b, self.id, self.lf, self.ksq, self.castle) = save;
                        Outcome::Void
                    } else {
                        self.half = 0;
                        if out == Outcome::Contested {
                            self.writs[o] = (self.writs[o] + 1).min(WRIT_CAP);
                        }
                        out
                    }
                } else {
                    out
                }
            }
        };
        self.last_outcome = Some(outcome);
    }

    // ------------------------------------------------------------------ making moves

    /// Play a legal move in place. The opponent's pending election, if any, is counted at the end.
    pub fn apply(&mut self, m: u32) {
        let t = tables();
        let from = (m & 63) as usize;
        let to = ((m >> 6) & 63) as usize;
        let promo = ((m >> 12) & 7) as i8;
        let fl = m >> 16;
        let s = self.side;
        let si = ci(s);
        let oi = ci(-s);
        self.last_outcome = None;
        if fl & F_WRIT != 0 {
            self.pending[si] = self.id[from];
            self.writs[si] -= 1;
            self.ep = -1;
            self.half += 1;
        } else {
            let v = self.b[from];
            let mut cap = self.b[to];
            if fl & F_EP != 0 {
                let cs = (to as i32 - 8 * s as i32) as usize;
                cap = self.b[cs];
                self.b[cs] = 0;
                self.id[cs] = 0;
            }
            self.b[to] = v;
            self.id[to] = self.id[from];
            self.b[from] = 0;
            self.id[from] = 0;
            if promo != 0 {
                self.b[to] = promo * s;
                if promo == Q {
                    self.lf[self.id[to] as usize] |= L_WAS_QUEEN;
                }
            }
            if v * s == 6 {
                self.ksq[si] = to;
                if fl & F_CASTLE != 0 {
                    let (rf, rt) = match to {
                        6 => (7, 5),
                        2 => (0, 3),
                        62 => (63, 61),
                        _ => (56, 59),
                    };
                    self.b[rt] = self.b[rf];
                    self.id[rt] = self.id[rf];
                    self.b[rf] = 0;
                    self.id[rf] = 0;
                }
            }
            self.castle &= t.cmask[from] & t.cmask[to];
            // Grievance: losing a Knight, Bishop, Rook or Queen earns a Writ.
            let ct = cap.abs();
            if (2..=5).contains(&ct) {
                self.writs[oi] = (self.writs[oi] + 1).min(WRIT_CAP);
            }
            self.half = if v * s == 1 || cap != 0 { 0 } else { self.half + 1 };
            self.ep = if fl & F_DOUBLE != 0 { from as i32 + 8 * s as i32 } else { -1 };
        }
        if s == -1 {
            self.full += 1;
        }
        self.side = -s;
        if self.pending[oi] != 0 {
            self.count(-s);
        }
    }

    pub fn after(&self, m: u32) -> Position {
        let mut c = self.clone();
        c.apply(m);
        c
    }

    // ------------------------------------------------------------------ keys and end states

    pub fn hash(&self) -> i64 {
        let mut h = 0i64;
        for sq in 0..64 {
            let v = self.b[sq];
            if v != 0 {
                let p = if v > 0 { v - 1 } else { 5 - v } as usize;
                h ^= ZP[p][sq];
            }
        }
        if self.side == -1 {
            h ^= ZS;
        }
        h ^= ZC[self.castle as usize];
        if self.ep >= 0 {
            h ^= ZE[(self.ep & 7) as usize];
        }
        if self.elections {
            h ^= ZW[0][self.writs[0] as usize] ^ ZW[1][self.writs[1] as usize];
            if self.pending[0] != 0 {
                h ^= ZPEND[0];
            }
            if self.pending[1] != 0 {
                h ^= ZPEND[1];
            }
        }
        h
    }

    /// FIDE dead positions only.
    pub fn insufficient(&self) -> bool {
        let mut minors: Vec<(i8, usize, i8)> = Vec::new();
        for sq in 0..64 {
            let v = self.b[sq];
            let t = v.abs();
            if t == 0 || t == 6 {
                continue;
            }
            if t == 1 || t == 4 || t == 5 {
                return false;
            }
            minors.push((t, sq, if v > 0 { 1 } else { -1 }));
        }
        if minors.len() <= 1 {
            return true;
        }
        if minors.len() == 2 && minors[0].0 == 3 && minors[1].0 == 3 && minors[0].2 != minors[1].2 {
            let colour = |sq: usize| ((sq & 7) + (sq >> 3)) & 1;
            return colour(minors[0].1) == colour(minors[1].1);
        }
        false
    }
}

pub fn sq_name(sq: usize) -> String {
    format!("{}{}", (b'a' + (sq & 7) as u8) as char, (sq >> 3) + 1)
}

pub fn sq_index(s: &str) -> usize {
    let b = s.as_bytes();
    ((b[1] - b'1') as usize) * 8 + (b[0] - b'a') as usize
}

pub fn cheb(a: usize, b: usize) -> i32 {
    (((a & 7) as i32) - ((b & 7) as i32)).abs().max((((a >> 3) as i32) - ((b >> 3) as i32)).abs())
}

/// A move as UCI text ("e2e4", "e7e8q", "W@f5" for a Writ).
pub fn uci(m: u32) -> String {
    let from = (m & 63) as usize;
    let to = ((m >> 6) & 63) as usize;
    if (m >> 16) & F_WRIT != 0 {
        return format!("W@{}", sq_name(from));
    }
    let promo = match (m >> 12) & 7 {
        2 => "n",
        3 => "b",
        4 => "r",
        5 => "q",
        _ => "",
    };
    format!("{}{}{}", sq_name(from), sq_name(to), promo)
}
