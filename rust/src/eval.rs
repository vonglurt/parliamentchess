//! Static evaluation in centipawns from the side to move's point of view: src/AI/Evaluator.php.
//! The floating-point terms are added in PHP's order, so rounding lands on the same integer.

use crate::net2::Net2;
use crate::position::{ci, tables, Position, L_PRETENDER, L_REIGNED, PEACE_UNTIL};
use std::sync::{Arc, OnceLock};

pub const VAL: [i32; 7] = [0, 100, 320, 330, 500, 900, 0];
const PAWN_ADV: [f64; 8] = [0.0, 0.0, 5.0, 10.0, 20.0, 35.0, 60.0, 0.0];
const PASSED: [f64; 8] = [0.0, 5.0, 12.0, 25.0, 45.0, 80.0, 130.0, 0.0];

fn cen() -> &'static [f64; 64] {
    static C: OnceLock<[f64; 64]> = OnceLock::new();
    C.get_or_init(|| {
        let mut c = [0.0; 64];
        for sq in 0..64 {
            let (f, r) = ((sq & 7) as f64, (sq >> 3) as f64);
            c[sq] = 3.0 - (f - 3.5).abs().max((r - 3.5).abs()) + 0.5;
        }
        c
    })
}

#[derive(Clone, Copy, Debug)]
pub struct Style {
    pub writ_value: i32,
    pub coronation_bonus: i32,
    pub files: bool,
}

impl Style {
    pub const WHIG: Style = Style { writ_value: 18, coronation_bonus: 0, files: true };
    pub const MONARCHIST: Style = Style { writ_value: 0, coronation_bonus: 0, files: false };
    pub const POPULIST: Style = Style { writ_value: 8, coronation_bonus: 90, files: true };
    pub fn by_name(s: &str) -> Option<Style> {
        match s {
            "whig" => Some(Style::WHIG),
            "monarchist" => Some(Style::MONARCHIST),
            "populist" => Some(Style::POPULIST),
            _ => None,
        }
    }
}

#[derive(Clone)]
pub struct Evaluator {
    pub style: Style,
    pub net2: Option<Arc<Net2>>,
    /// false: the net only orders moves (its policy head); the hand-written evaluation scores them.
    pub net2_eval: bool,
}

pub fn material(p: &Position) -> i32 {
    p.b.iter().map(|&v| if v > 0 { VAL[v as usize] } else if v < 0 { -VAL[(-v) as usize] } else { 0 }).sum()
}

pub fn phase(p: &Position) -> f64 {
    let mut npm = 0i32;
    for &v in p.b.iter() {
        let t = v.abs();
        if (2..=5).contains(&t) {
            npm += VAL[t as usize];
        }
    }
    (npm as f64 / 6200.0).min(1.0)
}

/// PHP's round() to an integer: half away from zero.
#[inline]
pub fn php_round(x: f64) -> i32 {
    x.round() as i32
}

impl Evaluator {
    pub fn new(style: Style) -> Evaluator {
        Evaluator { style, net2: None, net2_eval: true }
    }

    pub fn evaluate(&self, p: &Position) -> i32 {
        // A pending election is scored as its count would come out now: exact, not a guess.
        if p.pending[0] != 0 || p.pending[1] != 0 {
            let mut q = p.clone();
            for c in [1i8, -1] {
                if q.pending[ci(c)] != 0 {
                    q.count_now(c);
                }
            }
            return self.evaluate(&q);
        }
        if let Some(net) = &self.net2 {
            if self.net2_eval && !net.residual {
                let v = net.value(p).clamp(-0.98, 0.98);
                return php_round(400.0 * v.atanh());
            }
        }
        let mut white = self.white(p);
        if let Some(net) = &self.net2 {
            if self.net2_eval {
                let corr = net.raw(p, None).clamp(-2.0, 2.0);
                white += php_round(if p.side == 1 { 400.0 } else { -400.0 } * corr);
            }
        }
        if p.side == 1 {
            white
        } else {
            -white
        }
    }

    /// The hand-written evaluation, White's point of view.
    pub fn white(&self, p: &Position) -> i32 {
        let t = tables();
        let cen = cen();
        let b = &p.b;
        let ph = phase(p);
        let eg = 1.0 - ph;
        let mut score = 0.0f64;
        let mut mat = [0i32; 2];
        let mut bishops = [0i32; 2];
        let mut pawn_files = [[0i32; 8]; 2];
        let att = attack_maps(b);
        let mut pawns = [[0usize; 16]; 2];
        let mut npawns = [0usize; 2];
        for sq in 0..64 {
            let v = b[sq];
            if v == 1 || v == -1 {
                let i = ci(v);
                pawn_files[i][sq & 7] += 1;
                if npawns[i] < 16 {
                    pawns[i][npawns[i]] = sq;
                    npawns[i] += 1;
                }
            }
        }
        for sq in 0..64 {
            let v = b[sq];
            if v == 0 {
                continue;
            }
            let c: i8 = if v > 0 { 1 } else { -1 };
            let i = ci(c);
            let ty = (v * c) as usize;
            let f = sq & 7;
            let r = sq >> 3;
            let rel = if c == 1 { r } else { 7 - r };
            let ce = cen[sq];
            let mut s = VAL[ty] as f64;
            mat[i] += VAL[ty];
            match ty {
                1 => {
                    if rel == 7 {
                        s = 40.0; // L5: stranded on the last rank; it only votes
                    } else {
                        let pre = if p.lf[p.id[sq] as usize] & L_PRETENDER != 0 { 0.6 } else { 1.0 };
                        s += PAWN_ADV[rel] * (1.0 + eg) * pre;
                        if (f == 3 || f == 4) && rel >= 3 {
                            s += 10.0 * ph;
                        }
                        if pawn_files[i][f] > 1 {
                            s -= 12.0;
                        }
                        let isolated = (f == 0 || pawn_files[i][f - 1] == 0) && (f == 7 || pawn_files[i][f + 1] == 0);
                        if isolated {
                            s -= 10.0;
                        }
                        if passed(sq, c, &pawns[ci(-c)][..npawns[ci(-c)]]) {
                            s += PASSED[rel] * (1.0 + 2.0 * eg) * pre;
                        }
                    }
                }
                2 => {
                    s += 8.0 * ce - 12.0 + (2 * mobility(b, sq, 2, c)) as f64;
                }
                3 => {
                    bishops[i] += 1;
                    s += 3.0 * ce + (3 * mobility(b, sq, 3, c)) as f64;
                }
                4 => {
                    if rel == 6 {
                        s += 20.0;
                    }
                    if pawn_files[i][f] == 0 {
                        s += if pawn_files[ci(-c)][f] == 0 { 20.0 } else { 10.0 };
                    }
                    s += (2 * mobility(b, sq, 4, c)) as f64;
                }
                5 => {
                    s += 2.0 * ce + mobility(b, sq, 5, c) as f64;
                }
                6 => {
                    s = king_term(p, &att, sq, c, rel, ce, ph, eg);
                }
                _ => {}
            }
            score += c as f64 * s;
        }
        if bishops[0] >= 2 {
            score += 30.0;
        }
        if bishops[1] >= 2 {
            score -= 30.0;
        }
        let diff = mat[0] - mat[1];
        if diff.abs() >= 200 && p.half > 8 {
            score -= ((if diff > 0 { 1 } else { -1 }) * 3 * (p.half - 8)) as f64;
        }
        if eg > 0.6 && diff.abs() >= 300 {
            let c: i8 = if diff > 0 { 1 } else { -1 };
            let loser = p.ksq[ci(-c)];
            score += c as f64 * (12.0 * (3.5 - cen[loser]) * 2.0 + (4 * (14 - manhattan(p.ksq[ci(c)], loser))) as f64);
        }
        if p.elections {
            score += self.politics(p, 1) - self.politics(p, -1);
            let bonus = self.style.coronation_bonus;
            if bonus != 0 {
                let mut reigned = [-1i32; 2];
                for sq in 0..64 {
                    let v = p.b[sq];
                    if v != 0 && p.lf[p.id[sq] as usize] & L_REIGNED != 0 {
                        reigned[ci(if v > 0 { 1 } else { -1 })] += 1;
                    }
                }
                score += (bonus * (reigned[0].min(2) - reigned[1].min(2))) as f64;
            }
        }
        let _ = t;
        php_round(score)
    }

    fn politics(&self, p: &Position, c: i8) -> f64 {
        if !self.style.files {
            return 0.0;
        }
        (self.style.writ_value * p.writs[ci(c)]) as f64 * if p.full >= PEACE_UNTIL - 4 { 1.0 } else { 0.5 }
    }
}

fn passed(sq: usize, c: i8, enemy: &[usize]) -> bool {
    let f = (sq & 7) as i32;
    let r = (sq >> 3) as i32;
    for &e in enemy {
        let ef = (e & 7) as i32;
        let er = (e >> 3) as i32;
        if (ef - f).abs() <= 1 && (if c == 1 { er > r } else { er < r }) {
            return false;
        }
    }
    true
}

fn mobility(b: &[i8; 64], sq: usize, ty: i32, c: i8) -> i32 {
    let t = tables();
    let mut n = 0;
    if ty == 2 {
        for &to in t.kn(sq) {
            if (b[to as usize] as i32) * (c as i32) <= 0 {
                n += 1;
            }
        }
        return n - 4;
    }
    let d0 = if ty == 3 { 4 } else { 0 };
    let d1 = if ty == 4 { 4 } else { 8 };
    for d in d0..d1 {
        for &to in t.ray(sq, d) {
            let w = b[to as usize];
            if w == 0 {
                n += 1;
                continue;
            }
            if (w as i32) * (c as i32) < 0 {
                n += 1;
            }
            break;
        }
    }
    n - if ty == 5 { 12 } else { 6 }
}

fn king_term(p: &Position, att: &[u64; 2], sq: usize, c: i8, rel: usize, ce: f64, ph: f64, eg: f64) -> f64 {
    let mut s = eg * 10.0 * ce;
    let mut shield = 0i32;
    let f = (sq & 7) as i32;
    for step in [1i32, 2] {
        let rr = (sq >> 3) as i32 + c as i32 * step;
        if !(0..=7).contains(&rr) {
            continue;
        }
        for df in -1..=1 {
            let ff = f + df;
            if (0..8).contains(&ff) && p.b[(rr * 8 + ff) as usize] == c {
                shield += if step == 1 { 12 } else { 6 };
            }
        }
    }
    let tail = if rel == 0 { 15 } else { -10 * rel as i32 };
    s += ph * (shield as f64 - 10.0 * ce + tail as f64);
    s -= ph * danger_from(att, sq, c) as f64;
    s
}

/// Both sides' attack maps, one bit a square, built in one pass: the same answers as
/// Position::attacked for every square of this board, for a fraction of the cost.
pub fn attack_maps(b: &[i8; 64]) -> [u64; 2] {
    let t = tables();
    let mut att = [0u64; 2];
    for sq in 0..64usize {
        let v = b[sq];
        if v == 0 {
            continue;
        }
        let c: i8 = if v > 0 { 1 } else { -1 };
        let i = ci(c);
        let ty = v * c;
        let f = sq & 7;
        match ty {
            1 => {
                let to = sq as i32 + 8 * c as i32;
                if (0..64).contains(&to) {
                    if f > 0 {
                        att[i] |= 1u64 << (to - 1);
                    }
                    if f < 7 {
                        att[i] |= 1u64 << (to + 1);
                    }
                }
            }
            2 => {
                for &x in t.kn(sq) {
                    att[i] |= 1u64 << x;
                }
            }
            6 => {
                for &x in t.kg(sq) {
                    att[i] |= 1u64 << x;
                }
            }
            _ => {
                let d0 = if ty == 3 { 4 } else { 0 };
                let d1 = if ty == 4 { 4 } else { 8 };
                for d in d0..d1 {
                    for &x in t.ray(sq, d) {
                        att[i] |= 1u64 << x;
                        if b[x as usize] != 0 {
                            break;
                        }
                    }
                }
            }
        }
    }
    att
}

/// danger(), read from attack maps.
fn danger_from(att: &[u64; 2], sq: usize, c: i8) -> i32 {
    let t = tables();
    let enemy = att[ci(-c)];
    let mut d = if enemy >> sq & 1 != 0 { 30 } else { 0 };
    for &n in t.kg(sq) {
        if enemy >> n & 1 != 0 {
            d += 12;
        }
    }
    d
}

/// Attacked squares around a King, weighted: the cost of standing there.
pub fn danger(b: &[i8; 64], sq: usize, c: i8) -> i32 {
    let t = tables();
    let mut d = if Position::attacked(b, sq, -c) { 30 } else { 0 };
    for &n in t.kg(sq) {
        if Position::attacked(b, n as usize, -c) {
            d += 12;
        }
    }
    d
}

fn manhattan(a: usize, b: usize) -> i32 {
    (((a & 7) as i32) - ((b & 7) as i32)).abs() + (((a >> 3) as i32) - ((b >> 3) as i32)).abs()
}
