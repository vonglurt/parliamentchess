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


def value_target(r, lam, mode):
    if mode == "residual":  # the correction, in units of 400 cp, clipped
        return max(-2.0, min(2.0, (r["s"] - r.get("h", 0)) / 400.0))
    return lam * r["r"] + (1 - lam) * math.tanh(r["s"] / 400.0)


def batches(recs, size, device, lam, temp, shuffle, mode="value"):
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
        vt = torch.tensor([value_target(r, lam, mode) for r in chunk])
        mask = torch.full((b, MOVES), float("-inf"))
        target = torch.zeros(b, MOVES)
        best = torch.zeros(b, dtype=torch.long)
        for k, r in enumerate(chunk):
            ids = torch.tensor([m for m, _ in r["p"]], dtype=torch.long)
            sc = torch.tensor([s for _, s in r["p"]], dtype=torch.float)
            mask[k, ids] = 0.0
            target[k, ids] = torch.softmax(sc / temp, 0)
            best[k] = ids[int(torch.argmax(sc))]
        yield (torch.tensor(flat, dtype=torch.long, device=device), torch.tensor(offs, dtype=torch.long, device=device),
               vt.to(device), mask.to(device), target.to(device), best.to(device), chunk)


def evaluate(model, recs, args, device):
    model.eval()
    se = n = agree = sign_ok = sign_n = 0.0
    with torch.no_grad():
        for flat, offs, vt, mask, target, best, chunk in batches(recs, 4096, device, args.lam, args.temp, False, args.mode):
            v, logits = model(flat, offs)
            se += float(((v - vt) ** 2).sum())
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
    return se / max(1, n), agree / max(1, n), sign_ok / max(1, sign_n)


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
    ap.add_argument("--seed", type=int, default=7)
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
        print("warm start from", args.init)
        prev = json.load(open(args.init))
        if prev.get("h1") == args.h1 and prev.get("h2") == args.h2:
            with torch.no_grad():
                model.ft.weight.copy_(torch.tensor(prev["emb"]))
                model.ft_bias.copy_(torch.tensor(prev["b1"]))
                model.l2.weight.copy_(torch.tensor(prev["w2"]).t())
                model.l2.bias.copy_(torch.tensor(prev["b2"]))
                model.value.weight.copy_(torch.tensor([prev["wv"]]))
                model.value.bias.fill_(prev["bv"])
                model.efrom.weight.copy_(torch.tensor(prev["from"]))
                model.eto.weight.copy_(torch.tensor(prev["to"]))
                model.ewrit.weight.copy_(torch.tensor(prev["writ"]))
                model.mbias.copy_(torch.tensor(prev["mbias"]))
    opt = torch.optim.AdamW(model.parameters(), lr=args.lr, weight_decay=args.wd)
    sched = torch.optim.lr_scheduler.CosineAnnealingLR(opt, T_max=max(1, args.epochs))
    base = sum(value_target(r, args.lam, args.mode) ** 2 for r in val) / max(1, len(val))
    print(f"held-out baseline (predict 0) value MSE {base:.4f}", flush=True)
    history = []
    for ep in range(1, args.epochs + 1):
        te = time.time()
        tot = vl = pl = 0.0
        for flat, offs, vt, mask, target, best, chunk in batches(train, args.batch, device, args.lam, args.temp, True, args.mode):
            v, logits = model(flat, offs)
            loss_v = F.mse_loss(v, vt)
            logp = F.log_softmax(logits + mask, 1)
            loss_p = -(target * torch.where(torch.isfinite(logp), logp, torch.zeros_like(logp))).sum(1).mean()
            loss = loss_v + args.beta * loss_p
            opt.zero_grad()
            loss.backward()
            opt.step()
            tot += len(chunk)
            vl += loss_v.item() * len(chunk)
            pl += loss_p.item() * len(chunk)
        sched.step()
        mse, top1, sign = evaluate(model, val, args, device)
        row = {"epoch": ep, "trainValueMSE": round(vl / tot, 5), "trainPolicyCE": round(pl / tot, 4),
               "valValueMSE": round(mse, 5), "valPolicyTop1": round(top1, 4), "valSignAcc": round(sign, 4),
               "seconds": round(time.time() - te, 2)}
        history.append(row)
        print(json.dumps(row), flush=True)
    meta = {"trained": time.strftime("%Y-%m-%dT%H:%M:%S"), "device": gpu, "torch": torch.__version__,
            "positions": len(recs), "train": len(train), "held_out": len(val), "args": vars(args),
            "baselineValueMSE": round(base, 5), "history": history, "seconds": round(time.time() - t0, 1)}
    export(model, args.out, meta)
    print("saved", args.out, f"in {time.time() - t0:.1f}s total", flush=True)


if __name__ == "__main__":
    main()
