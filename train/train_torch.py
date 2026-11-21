#!/usr/bin/env python3
"""Train the second-generation Parliament Chess net on GPU (CUDA), Apple GPU (MPS) or CPU.

    python train/train_torch.py --data 'data/labels/*/shard.jsonl.gz' --out data/models/net2.json
        [--epochs 12] [--h1 128] [--h2 32] [--batch 1024] [--lr 1e-3] [--lam 0.25] [--temp 60]
        [--beta 1.0] [--device auto|cuda|mps|cpu] [--init data/models/net2.json]

The net (SensorNet):
    sparse Sensors v2 inputs (1,203, ~100 active) -> EmbeddingBag sum (h1) -> clipped ReLU
    -> linear (h2) -> clipped ReLU -> value head (tanh, side to move's expected result)
                                   -> policy head (one logit per move: h2 . (from + to), or h2 . writ)

Targets come from bin/label.php's teacher search, which looks depth plies deeper than the player:
    value  = lam * game result + (1 - lam) * tanh(teacher score / 400)
    policy = softmax(teacher action values / temp) over the legal moves
so the student learns, in one look, what the teacher saw by searching. Games are split 90/10
by game id, never by position. The export is plain JSON that src/AI/Net2.php reads.

Generations:
    --mode residual    the net learns teacher score minus the hand evaluation; with no --init its value
                       head starts at zero, so generation 0 IS the deterministic hand-written player and
                       learning only adds corrections to it (--no-zero-init to start at random)
    --init FILE        warm start from the champion; a larger --h1/--h2 than the champion's widens it
                       without changing what it computes (new units start with zero outgoing weights)
Cookies for non-standard moves (labels carry move flags: 1 promotion, 2 Writ, 4 castling, 8 capture,
16 a Writ whose count would already crown its Candidate):
    --cookie-writ CP   centipawns added to a sound Writ's teacher score (flag 16), in the policy target
                       and in the value target (the position is worth its best move counting the cookie)
    --cookie-promo CP  the same for a promotion
    --weight-special W loss weight for positions where a Writ or promotion is within 50 cp of best
Early stopping:
    --patience N       stop after N epochs in a row without a better held-out loss (default 3; 0 runs
                       every epoch); the best epoch's weights are exported, epoch 0 (the start) included
    --last             export the last epoch instead (the behaviour before generation 13's code)
Reinforcing the result:
    --reinforce RHO    residual mode: the target leans RHO of the way from the teacher's score to the
                       game's result (+-400 cp); value mode uses --lam for the same thing
"""
import argparse
import glob
import gzip
import json
import math
import os
import random
import sys
import time

import torch
import torch.nn as nn
import torch.nn.functional as F

INPUTS = 1203
MOVES = 4160


def load(pattern):
    recs = []
    for path in sorted(glob.glob(pattern)):
        with gzip.open(path, "rt") as fh:
            for line in fh:
                r = json.loads(line)
                r["game"] = r.get("g") or path
                recs.append(r)
    return recs


def split(recs, frac=0.1, seed=7):
    games = sorted({r["game"] for r in recs})
    if len(games) < 2:  # one game only: hold out its last tenth
        cut = int(len(recs) * (1 - frac))
        return recs[:cut], recs[cut:], 0
    rng = random.Random(seed)
    rng.shuffle(games)
    held = set(games[: max(1, int(len(games) * frac))])
    return [r for r in recs if r["game"] not in held], [r for r in recs if r["game"] in held], len(held)


class SensorNet(nn.Module):
    def __init__(self, h1=128, h2=32, residual=False):
        super().__init__()
        self.residual = residual
        self.ft = nn.EmbeddingBag(INPUTS, h1, mode="sum")
        self.ft_bias = nn.Parameter(torch.zeros(h1))
        self.l2 = nn.Linear(h1, h2)
        self.value = nn.Linear(h2, 1)
        self.efrom = nn.Embedding(64, h2)
        self.eto = nn.Embedding(64, h2)
        self.ewrit = nn.Embedding(64, h2)
        self.mbias = nn.Parameter(torch.zeros(MOVES))
        nn.init.normal_(self.ft.weight, std=0.05)
        for e in (self.efrom, self.eto, self.ewrit):
            nn.init.normal_(e.weight, std=0.1)
        idx = torch.arange(4096)
        self.register_buffer("mv_from", idx // 64)
        self.register_buffer("mv_to", idx % 64)

    def hidden(self, flat, offsets):
        x = torch.clamp(self.ft(flat, offsets) + self.ft_bias, 0, 1)
        return torch.clamp(self.l2(x), 0, 1)

    def move_vectors(self):
        return torch.cat([self.efrom(self.mv_from) + self.eto(self.mv_to), self.ewrit.weight], 0)  # MOVES x h2

    def forward(self, flat, offsets):
        h = self.hidden(flat, offsets)
        v = self.value(h).squeeze(1)
        if not self.residual:
            v = torch.tanh(v)
        logits = h @ self.move_vectors().t() + self.mbias
        return v, logits


WRIT_PRIOR = -50.0  # the logit that puts Writs last in Search's move order, as the hand order does


def flags_of(m):
    """A label's move flags; old labels carry none, but a Writ is still known by its index."""
    f = m[2] if len(m) > 2 else 0
    return f | (2 if m[0] >= 4096 else 0)


def special_weight(r, args):
    if args.weight_special == 1.0:
        return 1.0
    best = max(s for _, s, *_ in r["p"])
    near = any((flags_of(m) & 1 or flags_of(m) & 18 == 18) and m[1] >= best - 50 for m in r["p"])
    return args.weight_special if near else 1.0


def cookie(m, args):
    """Centipawns of encouragement for a non-standard move: a Writ, a promotion."""
    if args is None:
        return 0.0
    f = flags_of(m)
    # A Writ earns its cookie only if the crown-move theory approves it (flag 16: its count would
    # already crown the Candidate). Labels made before the flag existed reward every Writ.
    sound = f & 16 or len(m) < 3
    return (args.cookie_writ if f & 2 and sound else 0.0) + (args.cookie_promo if f & 1 else 0.0)


def value_target(r, lam, mode, args=None):
    s = r["s"]
    if args is not None and (args.cookie_writ or args.cookie_promo) and r.get("p"):
        # With cookies the position is worth its best move counting the cookie, so a position with a
        # good Writ or promotion within reach is worth a little more, and the search steers toward it.
        s = max(m[1] + cookie(m, args) for m in r["p"])
    if args is not None and args.reinforce and mode == "residual":
        # Reinforce winning: lean the teacher's score toward the game's real result, a win counting
        # as +400 cp for the side that went on to win it and a loss as -400.
        s = (1 - args.reinforce) * s + args.reinforce * 400.0 * r["r"]
    if mode == "residual":  # the correction, in units of 400 cp, clipped
        return max(-2.0, min(2.0, (s - r.get("h", 0)) / 400.0))
    return lam * r["r"] + (1 - lam) * math.tanh(s / 400.0)


def batches(recs, size, device, lam, temp, shuffle, mode="value", args=None):
    order = list(range(len(recs)))
    if shuffle:
        random.shuffle(order)
    for i in range(0, len(order), size):
        chunk = [recs[j] for j in order[i:i + size]]
        flat, offs = [], []
        for r in chunk:
            offs.append(len(flat))
            flat.extend(r["f"])
        b = len(chunk)
        vt = torch.tensor([value_target(r, lam, mode, args) for r in chunk])
        mask = torch.full((b, MOVES), float("-inf"))
        target = torch.zeros(b, MOVES)
        best = torch.zeros(b, dtype=torch.long)
        w = torch.ones(b)
        for k, r in enumerate(chunk):
            ids = torch.tensor([m[0] for m in r["p"]], dtype=torch.long)
            raw = torch.tensor([m[1] for m in r["p"]], dtype=torch.float)
            sc = raw.clone()
            if args is not None and (args.cookie_writ or args.cookie_promo):
                # Cookies: a non-standard move looks a little better to the student than to the teacher.
                sc += torch.tensor([cookie(m, args) for m in r["p"]])
            mask[k, ids] = 0.0
            target[k, ids] = torch.softmax(sc / temp, 0)
            best[k] = ids[int(torch.argmax(raw))]
            if args is not None:
                w[k] = special_weight(r, args)
        yield (torch.tensor(flat, dtype=torch.long, device=device), torch.tensor(offs, dtype=torch.long, device=device),
               vt.to(device), mask.to(device), target.to(device), best.to(device), chunk, w.to(device))


class Packed:
    """The whole dataset as tensors on the device, built once. An epoch is then index arithmetic on
    the device; building every batch in Python held a GTX 1080 Ti to 12 s an epoch (g003).

    feats/fmask  [N, Fmax]  active input indices, padded with 0 at weight 0
    ids/valid    [N, Pmax]  legal moves' policy indices; tgt the teacher's policy over them
    best [N], vt [N], w [N] the teacher's best move, the value target, the loss weight
    h, s, r      [N]        hand evaluation, teacher score, result, for the held-out checks"""

    def __init__(self, recs, device, lam, temp, mode, args=None):
        n = len(recs)
        self.n = n
        cook = args is not None and bool(args.cookie_writ or args.cookie_promo)
        flen = torch.tensor([len(r["f"]) for r in recs])
        fflat = torch.tensor([x for r in recs for x in r["f"]], dtype=torch.long)
        # One policy slot per from-to pair: the four promotions of one pawn share a slot. Their teacher
        # probabilities are summed into it (a log-sum-exp of their scores at the temperature), and its
        # best score is their best. (The old batches kept the last promotion's probability only.)
        pid_l, raw_l, sc_l, plen_l = [], [], [], []
        for r in recs:
            slots = {}
            for m in r["p"]:
                sc = m[1] + (cookie(m, args) if cook else 0.0)
                e = slots.get(m[0])
                if e is None:
                    slots[m[0]] = [m[1], [sc]]
                else:
                    e[0] = max(e[0], m[1])
                    e[1].append(sc)
            plen_l.append(len(slots))
            for i, (best_raw, scs) in slots.items():
                top = max(scs)
                pid_l.append(i)
                raw_l.append(float(best_raw))
                sc_l.append(top if len(scs) == 1 else top + temp * math.log(sum(math.exp((x - top) / temp) for x in scs)))
        plen = torch.tensor(plen_l)
        pid = torch.tensor(pid_l, dtype=torch.long)
        raw = torch.tensor(raw_l)
        sc_all = torch.tensor(sc_l)

        def pad(flat, lens, fill):
            rows = torch.repeat_interleave(torch.arange(n), lens)
            start = torch.cumsum(lens, 0) - lens
            cols = torch.arange(len(flat)) - torch.repeat_interleave(start, lens)
            out = torch.full((n, int(lens.max())), fill, dtype=flat.dtype)
            out[rows, cols] = flat
            return out

        self.feats = pad(fflat, flen, 0)
        self.fmask = pad(torch.ones(len(fflat)), flen, 0.0)
        self.ids = pad(pid, plen, 0)
        self.valid = pad(torch.ones(len(pid), dtype=torch.bool), plen, False)
        rawp = pad(raw, plen, float("-inf"))
        scp = pad(sc_all, plen, float("-inf"))
        self.tgt = torch.softmax(scp / temp, 1).nan_to_num(0.0)
        self.best = self.ids.gather(1, rawp.argmax(1, keepdim=True)).squeeze(1)
        self.vt = torch.tensor([value_target(r, lam, mode, args) for r in recs])
        self.w = torch.tensor([special_weight(r, args) if args is not None else 1.0 for r in recs])
        self.h = torch.tensor([float(r.get("h", 0)) for r in recs])
        self.s = torch.tensor([float(r["s"]) for r in recs])
        self.r = torch.tensor([float(r.get("r", 0)) for r in recs])
        for k in ("feats", "fmask", "ids", "valid", "tgt", "best", "vt", "w", "h", "s", "r"):
            setattr(self, k, getattr(self, k).to(device))


def forward_packed(model, P, idx):
    """SensorNet's forward on rows idx of a Packed set: the value, and the logits of the legal moves only
    (the same numbers the full forward gives them; illegal moves never enter the softmax either way)."""
    x = torch.clamp(model.ft(P.feats[idx], per_sample_weights=P.fmask[idx]) + model.ft_bias, 0, 1)
    h = torch.clamp(model.l2(x), 0, 1)
    v = model.value(h).squeeze(1)
    if not model.residual:
        v = torch.tanh(v)
    ids = P.ids[idx]
    # A multiply and a sum, not einsum: torch 2.14 sends a batched product's backward to a Triton
    # kernel even in eager mode, and Triton cannot build on the Pascal lanes (no Python headers there).
    lg = (h.unsqueeze(1) * model.move_vectors()[ids]).sum(-1) + model.mbias[ids]
    return v, lg.masked_fill(~P.valid[idx], float("-inf"))


def losses_packed(model, P, idx, args):
    v, lg = forward_packed(model, P, idx)
    w = P.w[idx]
    wn = w / w.mean()
    loss_v = (wn * (v - P.vt[idx]) ** 2).mean()
    logp = F.log_softmax(lg, 1).masked_fill(~P.valid[idx], 0.0)
    loss_p = (wn * -(P.tgt[idx] * logp).sum(1)).mean()
    return loss_v, loss_p


def evaluate_packed(model, P, args):
    model.eval()
    se = agree = ok = cnt = ce = 0.0
    with torch.no_grad():
        for i in range(0, P.n, 4096):
            idx = torch.arange(i, min(P.n, i + 4096), device=P.vt.device)
            v, lg = forward_packed(model, P, idx)
            se += float(((v - P.vt[idx]) ** 2).sum())
            logp = F.log_softmax(lg, 1).masked_fill(~P.valid[idx], 0.0)
            ce += float(-(P.tgt[idx] * logp).sum())
            agree += float((P.ids[idx].gather(1, lg.argmax(1, keepdim=True)).squeeze(1) == P.best[idx]).sum())
            if args.mode == "residual":  # does hand + correction land on the teacher's side of zero?
                m = P.s[idx] != 0
                ok += float((((P.h[idx] + 400 * v) > 0) == (P.s[idx] > 0))[m].sum())
            else:
                m = P.r[idx] != 0
                ok += float(((v > 0) == (P.r[idx] > 0))[m].sum())
            cnt += float(m.sum())
    model.train()
    return se / max(1, P.n), agree / max(1, P.n), ok / max(1.0, cnt), ce / max(1, P.n)


def evaluate(model, recs, args, device):
    model.eval()
    se = n = agree = sign_ok = sign_n = ce = 0.0
    with torch.no_grad():
        for flat, offs, vt, mask, target, best, chunk, _w in batches(recs, 4096, device, args.lam, args.temp, False, args.mode):
            v, logits = model(flat, offs)
            se += float(((v - vt) ** 2).sum())
            logp = F.log_softmax(logits + mask, 1)
            ce += float(-(target * torch.where(torch.isfinite(logp), logp, torch.zeros_like(logp))).sum())
            n += len(chunk)
            agree += float((torch.argmax(logits + mask, 1) == best).sum())
            for k, r in enumerate(chunk):
                if args.mode == "residual":  # does hand + correction land on the teacher's side of zero?
                    pred = r.get("h", 0) + 400 * float(v[k])
                    if r["s"] != 0:
                        sign_n += 1
                        sign_ok += (pred > 0) == (r["s"] > 0)
                elif r["r"] != 0:
                    sign_n += 1
                    sign_ok += (float(v[k]) > 0) == (r["r"] > 0)
    model.train()
    return se / max(1, n), agree / max(1, n), sign_ok / max(1, sign_n), ce / max(1, n)


def export(model, path, meta):
    sd = {k: v.detach().cpu() for k, v in model.state_dict().items()}
    out = {
        "version": 2, "inputs": INPUTS, "moves": MOVES, "mode": meta["args"]["mode"],
        "h1": sd["ft.weight"].shape[1], "h2": sd["l2.weight"].shape[0],
        "emb": sd["ft.weight"].tolist(), "b1": sd["ft_bias"].tolist(),
        "w2": sd["l2.weight"].t().tolist(), "b2": sd["l2.bias"].tolist(),
        "wv": sd["value.weight"][0].tolist(), "bv": float(sd["value.bias"][0]),
        "from": sd["efrom.weight"].tolist(), "to": sd["eto.weight"].tolist(), "writ": sd["ewrit.weight"].tolist(),
        "mbias": sd["mbias"].tolist(), "meta": meta,
    }
    os.makedirs(os.path.dirname(os.path.abspath(path)), exist_ok=True)
    with open(path, "w") as fh:
        json.dump(out, fh)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--data", required=True)
    ap.add_argument("--out", default="data/models/net2.json")
    ap.add_argument("--epochs", type=int, default=12)
    ap.add_argument("--h1", type=int, default=128)
    ap.add_argument("--h2", type=int, default=32)
    ap.add_argument("--batch", type=int, default=1024)
    ap.add_argument("--lr", type=float, default=1e-3)
    ap.add_argument("--wd", type=float, default=1e-5)
    ap.add_argument("--lam", type=float, default=0.1, help="weight of the game result; the rest is the teacher's score")
    ap.add_argument("--mode", default="value", choices=["value", "residual"],
                    help="value: the net is the evaluation; residual: it learns teacher score minus the hand evaluation")
    ap.add_argument("--temp", type=float, default=60.0)
    ap.add_argument("--beta", type=float, default=1.0)
    ap.add_argument("--device", default="auto")
    ap.add_argument("--init", default=None)
    ap.add_argument("--no-zero-init", action="store_true", help="residual nets start random instead of at zero")
    ap.add_argument("--cookie-writ", type=float, default=0.0)
    ap.add_argument("--cookie-promo", type=float, default=0.0)
    ap.add_argument("--weight-special", type=float, default=1.0)
    ap.add_argument("--reinforce", type=float, default=0.0, help="residual mode: weight of the game result in the target")
    ap.add_argument("--unpacked", action="store_true", help="build every batch in Python, as before 20 November (slower)")
    ap.add_argument("--seed", type=int, default=7)
    ap.add_argument("--patience", type=int, default=3,
                    help="early stopping: stop after this many epochs without a better held-out loss (0: run every epoch)")
    ap.add_argument("--last", action="store_true", help="export the last epoch, not the best one on held-out data")
    args = ap.parse_args()
    random.seed(args.seed)
    torch.manual_seed(args.seed)
    if args.device == "auto":
        device = "cuda" if torch.cuda.is_available() else ("mps" if torch.backends.mps.is_available() else "cpu")
    else:
        device = args.device
    gpu = torch.cuda.get_device_name(0) if device == "cuda" else device
    t0 = time.time()
    recs = load(args.data)
    if args.mode == "residual":  # only records that carry the hand evaluation can teach a correction
        recs = [r for r in recs if "h" in r]
    if not recs:
        sys.exit("no records match " + args.data)
    train, val, held = split(recs)
    print(f"{len(recs)} positions from {len({r['game'] for r in recs})} games: {len(train)} train, {len(val)} held out "
          f"({held} games); device {gpu}; loaded in {time.time() - t0:.1f}s", flush=True)
    model = SensorNet(args.h1, args.h2, args.mode == "residual").to(device)
    if args.init and os.path.exists(args.init):
        prev = json.load(open(args.init))
        a1, a2 = prev["h1"], prev["h2"]
        if a1 > args.h1 or a2 > args.h2:
            sys.exit(f"cannot shrink the champion ({a1}, {a2}) to ({args.h1}, {args.h2})")
        print(f"warm start from {args.init}" + (f", grown from ({a1}, {a2}) to ({args.h1}, {args.h2})" if (a1, a2) != (args.h1, args.h2) else ""))
        with torch.no_grad():
            # Growth that keeps the function: the champion's weights fill the top-left corner; the new
            # units keep their small random inputs but every weight leaving them starts at zero, so the
            # grown net computes exactly what the champion did until training gives them a use.
            model.ft.weight[:, :a1] = torch.tensor(prev["emb"])
            model.ft_bias[:a1] = torch.tensor(prev["b1"])
            model.ft_bias[a1:] = 0.0
            w2 = torch.zeros(args.h2, args.h1)
            w2[:a2, :a1] = torch.tensor(prev["w2"]).t()
            if args.h2 > a2:
                w2[a2:, :a1] = torch.randn(args.h2 - a2, a1) * 0.01
            model.l2.weight.copy_(w2)
            model.l2.bias.zero_()
            model.l2.bias[:a2] = torch.tensor(prev["b2"])
            model.value.weight.zero_()
            model.value.weight[0, :a2] = torch.tensor(prev["wv"])
            model.value.bias.fill_(prev["bv"])
            for emb, key in ((model.efrom, "from"), (model.eto, "to"), (model.ewrit, "writ")):
                emb.weight.zero_()
                emb.weight[:, :a2] = torch.tensor(prev[key])
            model.mbias.copy_(torch.tensor(prev["mbias"]))
    elif args.mode == "residual" and not args.no_zero_init:
        # Generation 0 is the deterministic program, in both of the net's jobs:
        #   value   a correction of exactly zero, so the evaluation is the hand-written one;
        #   policy  every move embedding zero and a bias of WRIT_PRIOR on Writs, so Search ranks
        #           quiet moves at 4000 + 100 * 0 and Writs at 4000 + 100 * -50 = -1000: the
        #           hand-written move order (src/AI/Search.php, order()) exactly.
        # The hidden layers keep their random start; with every weight leaving them at zero they
        # change nothing until training gives them a use.
        with torch.no_grad():
            model.value.weight.zero_()
            model.value.bias.zero_()
            for emb in (model.efrom, model.eto, model.ewrit):
                emb.weight.zero_()
            model.mbias.zero_()
            model.mbias[4096:] = WRIT_PRIOR
        print("zero start: before training, this net plays exactly like the hand-written program")
    opt = torch.optim.AdamW(model.parameters(), lr=args.lr, weight_decay=args.wd)
    sched = torch.optim.lr_scheduler.CosineAnnealingLR(opt, T_max=max(1, args.epochs))
    base = sum(value_target(r, args.lam, args.mode) ** 2 for r in val) / max(1, len(val))
    print(f"held-out baseline (predict 0) value MSE {base:.4f}", flush=True)
    history = []
    if not args.unpacked:
        tp = time.time()
        PT = Packed(train, device, args.lam, args.temp, args.mode, args)
        PV = Packed(val, device, args.lam, args.temp, args.mode)  # held out: the teacher's own targets
        print(f"packed {PT.n} + {PV.n} positions onto {device} in {time.time() - tp:.1f}s", flush=True)
    def check(ep, vl=None, pl=None, tot=0, te=None):
        """Held-out numbers after epoch ep (0: the starting net), and the early-stopping bookkeeping."""
        if args.unpacked:
            mse, top1, sign, ce = evaluate(model, val, args, device)
        else:
            mse, top1, sign, ce = evaluate_packed(model, PV, args)
        loss = mse + args.beta * ce  # the training objective, on the held-out games' teacher targets
        row = {"epoch": ep}
        if tot:
            row.update({"trainValueMSE": round(vl / tot, 5), "trainPolicyCE": round(pl / tot, 4)})
        row.update({"valValueMSE": round(mse, 5), "valPolicyTop1": round(top1, 4), "valSignAcc": round(sign, 4),
                    "valPolicyCE": round(ce, 4), "valLoss": round(loss, 5)})
        if te is not None:
            row["seconds"] = round(time.time() - te, 2)
        history.append(row)
        print(json.dumps(row), flush=True)
        if loss < best["loss"] - 1e-6:
            best.update(loss=loss, epoch=ep, bad=0,
                                 state={k: v.detach().clone() for k, v in model.state_dict().items()})
        else:
            best["bad"] += 1

    # Early stopping (from generation 13's code on): the held-out loss is read before training and
    # after every epoch; the weights of the best reading are kept and exported, and training stops
    # after --patience epochs in a row without a better one. g006-g011 exported their last epoch while
    # their held-out value error drifted up (0.163 -> 0.182).
    best = {"loss": float("inf"), "epoch": 0, "bad": 0, "state": None}
    check(0)
    best["bad"] = 0
    stopped = None
    for ep in range(1, args.epochs + 1):
        te = time.time()
        tot = vl = pl = 0.0
        if not args.unpacked:
            perm = torch.randperm(PT.n, device=device)
            for i in range(0, PT.n, args.batch):
                idx = perm[i:i + args.batch]
                loss_v, loss_p = losses_packed(model, PT, idx, args)
                loss = loss_v + args.beta * loss_p
                opt.zero_grad()
                loss.backward()
                opt.step()
                b = len(idx)
                tot += b
                vl += loss_v.item() * b
                pl += loss_p.item() * b
        else:
            for flat, offs, vt, mask, target, best_m, chunk, w in batches(train, args.batch, device, args.lam, args.temp, True, args.mode, args):
                v, logits = model(flat, offs)
                wn = w / w.mean()
                loss_v = (wn * (v - vt) ** 2).mean()
                logp = F.log_softmax(logits + mask, 1)
                loss_p = (wn * -(target * torch.where(torch.isfinite(logp), logp, torch.zeros_like(logp))).sum(1)).mean()
                loss = loss_v + args.beta * loss_p
                opt.zero_grad()
                loss.backward()
                opt.step()
                tot += len(chunk)
                vl += loss_v.item() * len(chunk)
                pl += loss_p.item() * len(chunk)
        sched.step()
        check(ep, vl, pl, tot, te)
        if args.patience > 0 and best["bad"] >= args.patience:
            stopped = ep
            print(f"early stop after epoch {ep}: {args.patience} epochs without a better held-out loss "
                  f"(best {best['loss']:.5f} at epoch {best['epoch']})", flush=True)
            break
    last = history[-1]["epoch"]
    if not args.last and best["state"] is not None and best["epoch"] != last:
        model.load_state_dict(best["state"])
        print(f"exporting epoch {best['epoch']} (held-out loss {best['loss']:.5f}), not epoch {last}", flush=True)
    meta = {"trained": time.strftime("%Y-%m-%dT%H:%M:%S"), "device": gpu, "torch": torch.__version__,
            "positions": len(recs), "train": len(train), "held_out": len(val), "args": vars(args),
            "baselineValueMSE": round(base, 5), "history": history, "seconds": round(time.time() - t0, 1),
            "bestEpoch": history[-1]["epoch"] if args.last else best["epoch"], "epochsRun": history[-1]["epoch"],
            "stoppedEarly": stopped is not None, "patience": args.patience,
            "selection": "last epoch" if args.last else "best held-out valLoss (value MSE + beta x policy CE)"}
    export(model, args.out, meta)
    print("saved", args.out, f"in {time.time() - t0:.1f}s total", flush=True)


if __name__ == "__main__":
    main()
