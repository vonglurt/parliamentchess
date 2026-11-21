//! Sensors v2 (src/AI/Sensors.php): the net's 1,203 binary inputs, seen from the side to move, in the
//! same order PHP emits them; and the policy index of a move.

use crate::position::{ci, cheb, tables, Position, F_WRIT, L_PRETENDER, L_REIGNED, PEACE_UNTIL, TERM, VOTES};

pub const VERSION: u32 = 2;
pub const PRE: u32 = 768;
pub const ATT: u32 = 896;
pub const HANG: u32 = 1024;
pub const KZ: u32 = 1152;
pub const CHK: u32 = 1162;
pub const MOB: u32 = 1163;
pub const WR: u32 = 1173;
pub const EL: u32 = 1181;
pub const POLL: u32 = 1187;
pub const PEND: u32 = 1191;
pub const THREAT: u32 = 1193;
pub const INPUTS: usize = 1203;
pub const MOVES: usize = 4160;

const VAL: [i32; 7] = [0, 100, 320, 330, 500, 900, 0];

pub fn features(p: &Position) -> Vec<u32> {
    let b = &p.b;
    let s = p.side;
    let si = ci(s);
    let mir = if s == 1 { 0 } else { 56 };
    let mut cnt = [[0i32; 64]; 2];
    let mut min = [[99999i32; 64]; 2];
    let mut mob = [0i32; 2];
    let mut f: Vec<u32> = Vec::with_capacity(128);
    for sq in 0..64usize {
        let v = b[sq];
        if v == 0 {
            continue;
        }
        let c: i8 = if v > 0 { 1 } else { -1 };
        let i = ci(c);
        let t = (v * c) as usize;
        let side_ix = if c == s { 0 } else { 1 };
        let rs = (sq ^ mir) as u32;
        f.push(side_ix * 384 + (t as u32 - 1) * 64 + rs);
        if t == 1 && p.lf[p.id[sq] as usize] & L_PRETENDER != 0 {
            f.push(PRE + side_ix * 64 + rs);
        }
        let val = if VAL[t] != 0 { VAL[t] } else { 10000 };
        for to in targets(b, sq, t, c) {
            cnt[i][to] += 1;
            if val < min[i][to] {
                min[i][to] = val;
            }
            if t != 1 && (b[to] as i32) * (c as i32) <= 0 {
                mob[i] += 1;
            }
        }
    }
    let mut hang = [0i32; 2];
    let oi = ci(-s);
    for sq in 0..64usize {
        let rs = (sq ^ mir) as u32;
        if cnt[si][sq] != 0 {
            f.push(ATT + rs);
        }
        if cnt[oi][sq] != 0 {
            f.push(ATT + 64 + rs);
        }
        let v = b[sq];
        if v == 0 {
            continue;
        }
        let c: i8 = if v > 0 { 1 } else { -1 };
        let t = (v * c) as usize;
        if t == 6 || cnt[ci(-c)][sq] == 0 {
            continue;
        }
        if cnt[ci(c)][sq] == 0 || min[ci(-c)][sq] < VAL[t] {
            f.push(HANG + if c == s { 0 } else { 64 } + rs);
            hang[ci(c)] = hang[ci(c)].max(VAL[t]);
        }
    }
    let t = tables();
    for c in [1i8, -1] {
        let i = ci(c);
        let o = ci(-c);
        let k = p.ksq[i];
        let mut pressure = cnt[o][k];
        for &z in t.kg(k) {
            pressure += cnt[o][z as usize];
        }
        let bucket = if pressure >= 5 { 4 } else if pressure >= 3 { 3 } else { pressure as u32 };
        let us = c == s;
        f.push(KZ + if us { 0 } else { 5 } + bucket);
        f.push(MOB + if us { 0 } else { 5 } + (mob[i] / 10).min(4) as u32);
        let h = hang[i];
        let hb = if h == 0 {
            0
        } else if h <= 100 {
            1
        } else if h <= 330 {
            2
        } else if h <= 500 {
            3
        } else {
            4
        };
        f.push(THREAT + if us { 0 } else { 5 } + hb);
    }
    let in_check = cnt[oi][p.ksq[si]] > 0;
    if in_check {
        f.push(CHK);
    }
    f.push(WR + p.writs[si].min(3) as u32);
    f.push(WR + 4 + p.writs[oi].min(3) as u32);
    if !p.elections {
        return f;
    }
    let term_us = p.full - p.last_count[si] >= TERM;
    let can_writ = p.writs[si] > 0 && p.full >= PEACE_UNTIL && p.pending[si] == 0 && term_us && !in_check;
    if can_writ {
        f.push(EL);
    }
    if p.pending[si] != 0 {
        f.push(EL + 1);
    }
    if p.pending[oi] != 0 {
        f.push(EL + 2);
    }
    if p.full >= PEACE_UNTIL {
        f.push(EL + 3);
    }
    if term_us {
        f.push(EL + 4);
    }
    if p.full - p.last_count[oi] >= TERM {
        f.push(EL + 5);
    }
    if can_writ {
        let mut got = [false; 4];
        for sq in 0..64usize {
            let v = b[sq];
            if v == 0 || (v > 0) != (s > 0) || cnt[oi][sq] != 0 {
                continue;
            }
            let ty = v * s;
            if ty == 6 || (ty == 5 && !p.queen_eligible) || p.lf[p.id[sq] as usize] & L_REIGNED != 0 {
                continue;
            }
            got[poll_lite(p, s, sq, &cnt[oi])] = true;
        }
        let mut any = false;
        for (o, i) in [(0usize, 0u32), (1, 1), (2, 2)] {
            if got[o] {
                f.push(POLL + i);
                any = true;
            }
        }
        if !any {
            f.push(POLL + 3);
        }
    }
    for (c, slot) in [(-s, 0u32), (s, 1u32)] {
        let pend = p.pending[ci(c)];
        if pend != 0 {
            if let Some(csq) = (0..64).find(|&sq| p.id[sq] == pend) {
                if cnt[ci(-c)][csq] == 0 && poll_lite(p, c, csq, &cnt[ci(-c)]) != 3 {
                    f.push(PEND + slot);
                }
            }
        }
    }
    f
}

/// The squares a piece attacks (pawns: diagonals only; sliders up to and including the first blocker).
fn targets(b: &[i8; 64], sq: usize, t: usize, c: i8) -> Vec<usize> {
    let tb = tables();
    if t == 1 {
        let f = sq & 7;
        let to = sq as i32 + 8 * c as i32;
        if !(0..=63).contains(&to) {
            return Vec::new();
        }
        let to = to as usize;
        let mut out = Vec::with_capacity(2);
        if f > 0 {
            out.push(to - 1);
        }
        if f < 7 {
            out.push(to + 1);
        }
        return out;
    }
    if t == 2 {
        return tb.kn(sq).iter().map(|&x| x as usize).collect();
    }
    if t == 6 {
        return tb.kg(sq).iter().map(|&x| x as usize).collect();
    }
    let mut out = Vec::new();
    let d0 = if t == 3 { 4 } else { 0 };
    let d1 = if t == 4 { 4 } else { 8 };
    for d in d0..d1 {
        for &to in tb.ray(sq, d) {
            let to = to as usize;
            out.push(to);
            if b[to] != 0 {
                break;
            }
        }
    }
    out
}

/// Position::poll with intimidation read from an attack-count map: 0 coronation, 1 contested,
/// 2 swap, 3 defeat.
fn poll_lite(p: &Position, c: i8, cand: usize, enemy_cnt: &[i32; 64]) -> usize {
    let k = p.ksq[ci(c)];
    let mut commons = [0; 2];
    let mut college = [0; 2];
    let mut speaker: Option<usize> = None;
    for sq in 0..64usize {
        let v = p.b[sq];
        if v == 0 || (v > 0) != (c > 0) || sq == k || sq == cand {
            continue;
        }
        let t = v * c;
        if t == 6 || enemy_cnt[sq] != 0 {
            continue;
        }
        let side = if cheb(sq, cand) < cheb(sq, k) { 0 } else { 1 };
        if t == 1 {
            commons[side] += 1;
        } else if t == 5 {
            speaker = Some(side);
        } else {
            college[side] += VOTES[t as usize];
        }
    }
    let cw = if college[0] > college[1] {
        0
    } else if college[0] < college[1] {
        1
    } else {
        speaker.unwrap_or(1)
    };
    let pw = if commons[0] > commons[1] { 0 } else { 1 };
    match (cw, pw) {
        (0, 0) => 0,
        (0, _) => 1,
        (_, 0) => 2,
        _ => 3,
    }
}

/// Policy index of a move, mirrored for Black like the features.
pub fn move_index(p: &Position, m: u32) -> u32 {
    let mir = if p.side == 1 { 0 } else { 56 };
    let from = (m & 63) ^ mir;
    if (m >> 16) & F_WRIT != 0 {
        return 4096 + from;
    }
    from * 64 + (((m >> 6) & 63) ^ mir)
}
