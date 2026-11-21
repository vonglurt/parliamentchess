//! pc-label: teacher labels for the net, as bin/label.php writes them, many times faster.
//!
//!   pc-label --games 4 --workers 8 --depth 3 --every 3 --seed 1 --player medium/populist --out DIR
//!
//! Self-play games by the player; every `every` plies from `from-ply` the teacher (a full-width search
//! `depth` plies deep, hand evaluation, whig style) scores the position and every legal move. Each
//! record: g (game id), f (Sensors v2 features), s (teacher score), h (hand evaluation), p ([policy
//! index, score, flags] per legal move), ply, r (the game's result for the side to move). Flags:
//! 1 promotion, 2 Writ, 4 castling, 8 capture, 16 a Writ whose count would already crown. The shard is
//! DIR/shard.jsonl.gz (through the system's gzip) and DIR/summary.json the totals.

use parliament::eval::{Evaluator, Style};
use parliament::game::{player, Game, Status};
use parliament::position::{F_CAPTURE, F_CASTLE, F_WRIT};
use parliament::rng::Rng;
use parliament::search::Search;
use parliament::sensors::{self, INPUTS, VERSION};
use std::collections::BTreeMap;
use std::io::Write;
use std::process::{Command, Stdio};
use std::time::Instant;

struct Opts {
    games: usize,
    depth: i32,
    seed: u64,
    out: String,
    player: String,
    every: usize,
    from_ply: usize,
    random_plies: usize,
    max_plies: usize,
    workers: usize,
    job: String,
}

fn opts() -> Result<Opts, String> {
    let mut o = Opts {
        games: 10,
        depth: 3,
        seed: 1,
        out: String::new(),
        player: "medium/populist".into(),
        every: 3,
        from_ply: 8,
        random_plies: 4,
        max_plies: 200,
        workers: 1,
        job: "local".into(),
    };
    let a: Vec<String> = std::env::args().skip(1).collect();
    let mut i = 0;
    while i < a.len() {
        let k = a[i].as_str();
        if k == "--no-book" {
            i += 1; // labels never use the book here; accepted for label.php's command lines
            continue;
        }
        let v = a.get(i + 1).ok_or(format!("{k} needs a value"))?.clone();
        let n = || v.parse::<u64>().map_err(|_| format!("{k}: not a number: {v}"));
        match k {
            "--games" => o.games = n()? as usize,
            "--depth" => o.depth = n()? as i32,
            "--seed" => o.seed = n()?,
            "--out" => o.out = v.clone(),
            "--player" => o.player = v.clone(),
            "--every" => o.every = n()?.max(1) as usize,
            "--from-ply" => o.from_ply = n()? as usize,
            "--opening-random" => o.random_plies = n()? as usize,
            "--max-plies" => o.max_plies = n()? as usize,
            "--workers" => o.workers = n()? as usize,
            "--job" => o.job = v.clone(),
            _ => return Err(format!("unknown option {k}")),
        }
        i += 2;
    }
    if o.out.is_empty() {
        return Err("--out DIR is required".into());
    }
    if o.workers == 0 {
        o.workers = std::thread::available_parallelism().map(|n| n.get()).unwrap_or(1);
    }
    Ok(o)
}

struct Worker {
    lines: Vec<String>,
    results: Vec<&'static str>,
    games: usize,
    seconds: f64,
    teacher_seconds: f64,
}

fn run_worker(o: &Opts, job: &str, wseed: u64) -> Result<Worker, String> {
    let t0 = Instant::now();
    let hand = Evaluator::new(Style::WHIG);
    let mut lines = Vec::new();
    let mut results = Vec::new();
    let mut teacher_secs = 0.0;
    for gi in 0..o.games {
        let gs = wseed * 1000 + gi as u64;
        let mut rng = Rng::new(gs);
        let mut white = player(&o.player, gs)?;
        let mut black = player(&o.player, gs + 1)?;
        let mut teacher = Search::new(Evaluator::new(Style::WHIG), o.depth, 0, gs);
        teacher.max_nodes = u64::MAX;
        let mut g = Game::new();
        let mut pending: Vec<(String, i8)> = Vec::new();
        for _ in 0..o.random_plies {
            if g.status != Status::Playing {
                break;
            }
            let legal = g.pos.legal_moves();
            let m = legal[rng.int(0, legal.len() - 1)];
            g.play(m);
        }
        while g.status == Status::Playing && g.plies < o.max_plies {
            let ply = g.plies;
            if ply >= o.from_ply && (ply - o.from_ply) % o.every == 0 {
                let t = Instant::now();
                let info = teacher.choose(&g);
                teacher_secs += t.elapsed().as_secs_f64();
                let p = &g.pos;
                let mut pv = Vec::with_capacity(teacher.root_scores.len());
                for &(m, sc) in &teacher.root_scores {
                    let fl = m >> 16;
                    let mut flags = (if (m >> 12) & 7 != 0 { 1 } else { 0 })
                        | (if fl & F_WRIT != 0 { 2 } else { 0 })
                        | (if fl & F_CASTLE != 0 { 4 } else { 0 })
                        | (if fl & F_CAPTURE != 0 { 8 } else { 0 });
                    if flags & 2 != 0 && p.poll(p.side, ((m >> 6) & 63) as usize).outcome.moves_crown() {
                        flags |= 16;
                    }
                    pv.push(format!("[{},{},{}]", sensors::move_index(p, m), sc.clamp(-2000, 2000), flags));
                }
                if !pv.is_empty() {
                    let f: Vec<String> = sensors::features(p).iter().map(|x| x.to_string()).collect();
                    let rec = format!(
                        "{{\"g\":\"{}-{}\",\"f\":[{}],\"s\":{},\"h\":{},\"p\":[{}],\"ply\":{}",
                        job,
                        gs,
                        f.join(","),
                        info.score.clamp(-2000, 2000),
                        hand.evaluate(p).clamp(-2000, 2000),
                        pv.join(","),
                        ply
                    );
                    pending.push((rec, p.side));
                }
            }
            let mover = if g.pos.side == 1 { &mut white } else { &mut black };
            let c = mover.choose(&g);
            if c.mv == 0 {
                break;
            }
            g.play(c.mv);
        }
        let winner = if g.status == Status::Playing { None } else { g.winner };
        results.push(match winner {
            None => "1/2",
            Some(1) => "1-0",
            Some(_) => "0-1",
        });
        for (rec, side) in pending {
            let r = match winner {
                None => 0,
                Some(w) if w == side => 1,
                Some(_) => -1,
            };
            lines.push(format!("{rec},\"r\":{r}}}\n"));
        }
        eprintln!("{job} game {}/{} {}: {} labels so far, {:.1}s", gi + 1, o.games, results.last().unwrap(), lines.len(), t0.elapsed().as_secs_f64());
    }
    Ok(Worker { lines, results, games: o.games, seconds: t0.elapsed().as_secs_f64(), teacher_seconds: teacher_secs })
}

fn main() {
    let o = match opts() {
        Ok(o) => o,
        Err(e) => {
            eprintln!("pc-label: {e}");
            std::process::exit(2);
        }
    };
    let t0 = Instant::now();
    std::fs::create_dir_all(&o.out).expect("cannot make the output folder");
    // Workers as label.php makes them: worker i has seed*100+i and job "JOB-wi" (one worker: the seed itself).
    let plan: Vec<(String, u64)> = if o.workers > 1 {
        (0..o.workers).map(|i| (format!("{}-w{}", o.job, i), o.seed * 100 + i as u64)).collect()
    } else {
        vec![(o.job.clone(), o.seed)]
    };
    let done: Vec<Result<Worker, String>> = std::thread::scope(|s| {
        let hs: Vec<_> = plan.iter().map(|(job, seed)| s.spawn(|| run_worker(&o, job, *seed))).collect();
        hs.into_iter().map(|h| h.join().unwrap_or_else(|_| Err("worker panicked".into()))).collect()
    });
    let shard = format!("{}/shard.jsonl.gz", o.out);
    let file = std::fs::File::create(&shard).expect("cannot write the shard");
    let mut gz = Command::new("gzip").args(["-6", "-c"]).stdin(Stdio::piped()).stdout(file).spawn().expect("gzip is needed to write the shard");
    let mut labels = 0;
    let mut games = 0;
    let mut failed = 0;
    let mut cpu = 0.0;
    let mut teacher = 0.0;
    let mut results: BTreeMap<&str, usize> = BTreeMap::new();
    {
        let stdin = gz.stdin.as_mut().unwrap();
        for w in &done {
            match w {
                Ok(w) => {
                    for l in &w.lines {
                        stdin.write_all(l.as_bytes()).expect("writing the shard");
                    }
                    labels += w.lines.len();
                    games += w.games;
                    cpu += w.seconds;
                    teacher += w.teacher_seconds;
                    for r in &w.results {
                        *results.entry(r).or_insert(0) += 1;
                    }
                }
                Err(e) => {
                    eprintln!("pc-label: a worker failed: {e}");
                    failed += 1;
                }
            }
        }
    }
    drop(gz.stdin.take());
    let ok = gz.wait().map(|s| s.success()).unwrap_or(false);
    let secs = t0.elapsed().as_secs_f64();
    let res: Vec<String> = results.iter().map(|(k, v)| format!("\"{}\":{}", k.replace('/', "\\/"), v)).collect();
    let summary = format!(
        "{{\n    \"job\": \"{}\",\n    \"games\": {},\n    \"labels\": {},\n    \"depth\": {},\n    \"player\": \"{}\",\n    \"workers\": {},\n    \"failedWorkers\": {},\n    \"sensors\": {},\n    \"inputs\": {},\n    \"results\": {{{}}},\n    \"cpuSeconds\": {:.1},\n    \"teacherSeconds\": {:.1},\n    \"seconds\": {:.1},\n    \"labelsPerLaneHour\": {},\n    \"engine\": \"rust\"\n}}\n",
        o.job,
        games,
        labels,
        o.depth,
        o.player.replace('/', "\\/"),
        o.workers,
        failed,
        VERSION,
        INPUTS,
        res.join(","),
        cpu,
        teacher,
        secs,
        (labels as f64 / secs.max(1e-9) * 3600.0).round() as u64
    );
    std::fs::write(format!("{}/summary.json", o.out), &summary).expect("cannot write summary.json");
    println!("{}", summary.split_whitespace().collect::<Vec<_>>().join(" "));
    if !ok || labels == 0 {
        std::process::exit(1);
    }
}
