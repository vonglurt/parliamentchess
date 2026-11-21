//! A game: the position, the repetition counts and the end states of src/Engine/Game.php (judged after
//! the count, as RULES.md section G says), without the log and notation the labeller does not need.

use crate::eval::{Evaluator, Style};
use crate::net2::Net2;
use crate::position::Position;
use crate::search::Search;
use std::collections::HashMap;
use std::sync::Arc;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Status {
    Playing,
    Checkmate,
    Stalemate,
    Draw,
}

pub struct Game {
    pub pos: Position,
    pub seen: HashMap<i64, i32>,
    pub plies: usize,
    pub status: Status,
    pub winner: Option<i8>,
    pub termination: &'static str,
}

impl Game {
    pub fn new() -> Game {
        Game::from_position(Position::start())
    }

    pub fn from_position(pos: Position) -> Game {
        let mut seen = HashMap::new();
        seen.insert(pos.hash(), 1);
        Game { pos, seen, plies: 0, status: Status::Playing, winner: None, termination: "" }
    }

    pub fn play(&mut self, m: u32) {
        let after = self.pos.after(m);
        let legal = !after.legal_moves().is_empty();
        let check = after.in_check();
        self.plies += 1;
        let key = after.hash();
        let n = self.seen.entry(key).or_insert(0);
        *n += 1;
        let n = *n;
        self.pos = after;
        if !legal {
            if check {
                self.finish(Status::Checkmate, Some(-self.pos.side), "checkmate");
            } else {
                self.finish(Status::Stalemate, None, "stalemate");
            }
        } else if self.pos.half >= 100 {
            self.finish(Status::Draw, None, "fifty-move rule");
        } else if n >= 3 {
            self.finish(Status::Draw, None, "threefold repetition");
        } else if self.pos.insufficient() {
            self.finish(Status::Draw, None, "insufficient material");
        }
    }

    fn finish(&mut self, status: Status, winner: Option<i8>, why: &'static str) {
        self.status = status;
        self.winner = winner;
        self.termination = why;
    }
}

impl Default for Game {
    fn default() -> Self {
        Game::new()
    }
}

/// Players by name, as src/AI/Players.php: "<level>[/<style>][+net2[:path]|+net2policy[:path]]".
pub fn player(spec: &str, seed: u64) -> Result<Search, String> {
    let mut spec = spec.to_string();
    let mut net: Option<(String, bool)> = None;
    for (tag, policy_only) in [("+net2policy", true), ("+net2", false)] {
        if let Some(i) = spec.find(tag) {
            let rest = &spec[i + tag.len()..];
            let path = rest.strip_prefix(':').map(|s| s.to_string()).unwrap_or_else(|| {
                std::env::var("PC_NET2").unwrap_or_else(|_| "data/models/net2.json".to_string())
            });
            net = Some((path, policy_only));
            spec.truncate(i);
            break;
        }
    }
    let mut it = spec.splitn(2, '/');
    let level = it.next().unwrap_or("medium");
    let style = it.next().unwrap_or("whig");
    let (depth, margin) = match level {
        "easy" => (1, 60),
        "medium" => (2, 12),
        "hard" => (3, 0),
        _ => return Err(format!("unknown level {level}")),
    };
    let st = Style::by_name(style).ok_or(format!("unknown style {style}"))?;
    let mut ev = Evaluator::new(st);
    if let Some((path, policy_only)) = net {
        ev.net2 = Some(Arc::new(Net2::load(&path)?));
        ev.net2_eval = !policy_only;
    }
    Ok(Search::new(ev, depth, margin, seed))
}
