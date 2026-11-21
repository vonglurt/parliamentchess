//! Parliament Chess in Rust: the rules, the hand evaluation, the search and the Sensors v2 features of
//! the PHP engine, written to give the same answers (bin/pc-parity checks them against PHP), for
//! labelling positions many times faster than PHP can. MIT License.

pub mod eval;
pub mod game;
pub mod json;
pub mod net2;
pub mod position;
pub mod rng;
pub mod search;
pub mod sensors;
pub mod zobrist;
