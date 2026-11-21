//! The second-generation net's forward pass (src/AI/Net2.php): a trunk of two clipped layers over the
//! Sensors v2 features, a value head, and policy logits from move embeddings.

use crate::json::{self, Json};
use crate::position::Position;
use crate::sensors::{self, INPUTS};

pub struct Net2 {
    pub h1: usize,
    pub h2: usize,
    pub emb: Vec<Vec<f64>>,
    pub b1: Vec<f64>,
    pub w2: Vec<Vec<f64>>,
    pub b2: Vec<f64>,
    pub wv: Vec<f64>,
    pub bv: f64,
    pub from: Vec<Vec<f64>>,
    pub to: Vec<Vec<f64>>,
    pub writ: Vec<Vec<f64>>,
    pub mbias: Vec<f64>,
    /// "residual": a correction added to the hand evaluation; otherwise the net is the evaluation.
    pub residual: bool,
}

impl Net2 {
    pub fn load(path: &str) -> Result<Net2, String> {
        let text = std::fs::read_to_string(path).map_err(|e| format!("{path}: {e}"))?;
        let a = json::parse(&text)?;
        if a.get("version").map(|v| v.i64()) != Some(2) || a.get("inputs").map(|v| v.i64()) != Some(INPUTS as i64) {
            return Err(format!("{path} is not a Sensors v2 net"));
        }
        let rows = |k: &str| -> Vec<Vec<f64>> { a.get(k).map(|v| v.arr().iter().map(Json::f64s).collect()).unwrap_or_default() };
        let vec = |k: &str| -> Vec<f64> { a.get(k).map(Json::f64s).unwrap_or_default() };
        Ok(Net2 {
            h1: a.get("h1").map(|v| v.i64() as usize).unwrap_or(0),
            h2: a.get("h2").map(|v| v.i64() as usize).unwrap_or(0),
            emb: rows("emb"),
            b1: vec("b1"),
            w2: rows("w2"),
            b2: vec("b2"),
            wv: vec("wv"),
            bv: a.get("bv").map(|v| v.f64()).unwrap_or(0.0),
            from: rows("from"),
            to: rows("to"),
            writ: rows("writ"),
            mbias: vec("mbias"),
            residual: a.get("mode").map(|v| v.str() == "residual").unwrap_or(false),
        })
    }

    pub fn trunk(&self, p: &Position) -> Vec<f64> {
        let mut acc = self.b1.clone();
        for i in sensors::features(p) {
            let row = &self.emb[i as usize];
            for j in 0..self.h1 {
                acc[j] += row[j];
            }
        }
        let mut h = self.b2.clone();
        for j in 0..self.h1 {
            let mut a = acc[j];
            if a <= 0.0 {
                continue;
            }
            if a > 1.0 {
                a = 1.0;
            }
            let w = &self.w2[j];
            for k in 0..self.h2 {
                h[k] += a * w[k];
            }
        }
        for x in h.iter_mut() {
            *x = if *x < 0.0 { 0.0 } else if *x > 1.0 { 1.0 } else { *x };
        }
        h
    }

    pub fn raw(&self, p: &Position, h: Option<&[f64]>) -> f64 {
        let own;
        let h = match h {
            Some(h) => h,
            None => {
                own = self.trunk(p);
                &own
            }
        };
        let mut z = self.bv;
        for (k, x) in h.iter().enumerate() {
            z += x * self.wv[k];
        }
        z
    }

    pub fn value(&self, p: &Position) -> f64 {
        self.raw(p, None).tanh()
    }

    pub fn logits(&self, p: &Position, moves: &[u32]) -> Vec<f64> {
        let h = self.trunk(p);
        moves
            .iter()
            .map(|&m| {
                let idx = sensors::move_index(p, m) as usize;
                let mut z = self.mbias[idx];
                if idx >= 4096 {
                    let v = &self.writ[idx - 4096];
                    for (k, x) in h.iter().enumerate() {
                        z += x * v[k];
                    }
                } else {
                    let (f, t) = (&self.from[idx >> 6], &self.to[idx & 63]);
                    for (k, x) in h.iter().enumerate() {
                        z += x * (f[k] + t[k]);
                    }
                }
                z
            })
            .collect()
    }
}
