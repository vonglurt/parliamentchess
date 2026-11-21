//! pc-parity: does the Rust engine give PHP's answers? Reads test vectors written by
//! tools/rust_parity.php (positions from real games with PHP's own results) and compares, position by
//! position: the hash, the move list in order, perft, the Sensors v2 features, the hand evaluation in
//! two styles, and the teacher's score for every root move at depths 2 and 3.
//!
//!   pc-parity vectors.jsonl        exit 0 only if everything matches

use parliament::eval::{Evaluator, Style};
use parliament::json::{self, Json};
use parliament::net2::Net2;
use std::sync::Arc;
use parliament::position::{ci, Position};
use parliament::search::Search;
use parliament::sensors;
use std::collections::HashMap;
use std::time::Instant;

fn position(st: &Json) -> Position {
    let mut p = Position::from_fen(st.get("fen").unwrap().str()).unwrap();
    let arr64 = |k: &str| -> [u8; 64] {
        let mut a = [0u8; 64];
        for (i, v) in st.get(k).unwrap().arr().iter().enumerate().take(64) {
            a[i] = v.i64() as u8;
        }
        a
    };
    p.id = arr64("id");
    p.lf = arr64("lf");
    p.born = arr64("born");
    let pair = |k: &str| -> [i64; 2] {
        let a = st.get(k).unwrap().arr();
        [a[0].i64(), a[1].i64()]
    };
    let k = pair("ksq");
    p.ksq = [k[0] as usize, k[1] as usize];
    let w = pair("writs");
    p.writs = [w[0] as i32, w[1] as i32];
    let l = pair("lastCount");
    p.last_count = [l[0] as i32, l[1] as i32];
    let pe = pair("pending");
    p.pending = [pe[0] as u8, pe[1] as u8];
    p.next_id = st.get("nextId").unwrap().i64() as u8;
    p.elections = st.get("elections").unwrap().bool();
    p.queen_eligible = st.get("queenEligible").unwrap().bool();
    p
}

fn perft(p: &Position, d: u32) -> u64 {
    let moves = p.legal_moves();
    if d == 1 {
        return moves.len() as u64;
    }
    moves.iter().map(|&m| perft(&p.after(m), d - 1)).sum()
}

fn main() {
    let path = std::env::args().nth(1).expect("pc-parity VECTORS.jsonl [NET.json]");
    // With a net: its value-head input and policy logits, and the residual evaluation with it.
    let net = std::env::args().nth(2).map(|p| Arc::new(Net2::load(&p).expect("cannot load the net")));
    let mut net_max_diff = 0.0f64;
    let text = std::fs::read_to_string(&path).expect("cannot read the vectors");
    let (mut n, mut bad) = (0, 0);
    let mut checks: HashMap<&str, (u32, u32)> = HashMap::new();
    let t0 = Instant::now();
    let mut search_secs = 0.0;
    for line in text.lines().filter(|l| !l.trim().is_empty()) {
        let v = json::parse(line).expect("bad vector");
        let name = v.get("name").unwrap().str().to_string();
        let p = position(v.get("state").unwrap());
        let seen: HashMap<i64, i32> = v.get("seen").unwrap().arr().iter().map(|e| (e.arr()[0].i64(), e.arr()[1].i64() as i32)).collect();
        n += 1;
        let mut fails: Vec<String> = Vec::new();
        let mut check = |what: &'static str, ok: bool, detail: String, fails: &mut Vec<String>| {
            let e = checks.entry(what).or_insert((0, 0));
            e.0 += 1;
            if !ok {
                e.1 += 1;
                fails.push(format!("{what}: {detail}"));
            }
        };
        let h = v.get("hash").unwrap().i64();
        check("hash", p.hash() == h, format!("rust {} php {}", p.hash(), h), &mut fails);
        let moves: Vec<i64> = v.get("moves").unwrap().arr().iter().map(|x| x.i64()).collect();
        let mine: Vec<i64> = p.moves(false).iter().map(|&m| m as i64).collect();
        check("moves", mine == moves, format!("rust {} php {}", mine.len(), moves.len()), &mut fails);
        let perft2 = v.get("perft2").unwrap().i64() as u64;
        let p2 = perft(&p, 2);
        check("perft2", p2 == perft2, format!("rust {p2} php {perft2}"), &mut fails);
        if let Some(Json::Num(x)) = v.get("perft3") {
            let p3 = perft(&p, 3);
            check("perft3", p3 == *x as u64, format!("rust {p3} php {x}"), &mut fails);
        }
        let feats: Vec<i64> = v.get("features").unwrap().arr().iter().map(|x| x.i64()).collect();
        let mf: Vec<i64> = sensors::features(&p).iter().map(|&x| x as i64).collect();
        check("features", mf == feats, format!("rust {:?}\n      php  {:?}", mf, feats), &mut fails);
        for (key, style) in [("h", Style::WHIG), ("hPopulist", Style::POPULIST)] {
            let want = v.get(key).unwrap().i64() as i32;
            let got = Evaluator::new(style).evaluate(&p);
            check(if key == "h" { "eval whig" } else { "eval populist" }, got == want, format!("rust {got} php {want}"), &mut fails);
        }
        if let (Some(net), Some(raw)) = (&net, v.get("netRaw")) {
            let r = net.raw(&p, None);
            let d = (r - raw.f64()).abs();
            net_max_diff = net_max_diff.max(d);
            check("net raw", d < 1e-9, format!("rust {r} php {}", raw.f64()), &mut fails);
            let want = v.get("netLogits").unwrap().f64s();
            let got = net.logits(&p, &p.legal_moves());
            let d = got.iter().zip(want.iter()).map(|(a, b)| (a - b).abs()).fold(0.0, f64::max);
            net_max_diff = net_max_diff.max(d);
            check("net logits", got.len() == want.len() && d < 1e-9, format!("max difference {d}"), &mut fails);
            let mut ev = Evaluator::new(Style::WHIG);
            ev.net2 = Some(net.clone());
            let want = v.get("evalNet").unwrap().i64() as i32;
            let got = ev.evaluate(&p);
            check("eval with net", got == want, format!("rust {got} php {want}"), &mut fails);
        }
        if let Some(Json::Obj(t)) = v.get("teacher") {
            for (d, res) in t {
                let depth: i32 = d.parse().unwrap();
                let mut s = Search::new(Evaluator::new(Style::WHIG), depth, 0, 1);
                s.max_nodes = u64::MAX;
                let ts = Instant::now();
                let c = s.choose_from(&p, &seen);
                search_secs += ts.elapsed().as_secs_f64();
                let want: Vec<(i64, i64)> = res.get("scores").unwrap().arr().iter().map(|e| (e.arr()[0].i64(), e.arr()[1].i64())).collect();
                let got: Vec<(i64, i64)> = s.root_scores.iter().map(|&(m, sc)| (m as i64, sc as i64)).collect();
                let best = res.get("best").unwrap().i64();
                let what = if depth == 2 { "teacher depth 2" } else { "teacher depth 3" };
                let first_diff = got.iter().zip(want.iter()).position(|(a, b)| a != b);
                check(
                    what,
                    got == want && c.score as i64 == best,
                    format!("best rust {} php {best}; {} vs {} moves; first difference at {:?}: rust {:?} php {:?}",
                        c.score, got.len(), want.len(), first_diff, first_diff.map(|i| got[i]), first_diff.map(|i| want[i])),
                    &mut fails,
                );
            }
        }
        let _ = ci(1);
        if !fails.is_empty() {
            bad += 1;
            if bad <= 8 {
                println!("MISMATCH {name} ({})", p.to_fen());
                for f in &fails {
                    println!("    {f}");
                }
            }
        }
    }
    let mut keys: Vec<_> = checks.keys().copied().collect();
    keys.sort();
    for k in keys {
        let (all, failed) = checks[k];
        println!("{:<16} {:>4} checked, {:>3} differ", k, all, failed);
    }
    if net.is_some() {
        println!("largest net difference {net_max_diff:e}");
    }
    println!("{n} positions, {bad} with a difference; {:.1}s ({:.2}s in the teacher searches)", t0.elapsed().as_secs_f64(), search_secs);
    std::process::exit(if bad == 0 { 0 } else { 1 });
}
