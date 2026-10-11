"""Barrido del pipeline real (Discovery->Validation->Pareto) en cada cierre de vela 30m.
Uso: python run_sweep.py <tag> [--null SEMILLA] [--symbols A,B] [--limit N]
Salida: results/sweep_<tag>_<SYM>.npz con k (índice de vela), flags[E,400] (bit0 Discovery, bit1 Validation, bit2 frente Pareto), selm[E,9]."""
import sys, os, time, argparse, glob
import numpy as np
import core

ap = argparse.ArgumentParser()
ap.add_argument("tag"); ap.add_argument("--null", type=int, default=None)
ap.add_argument("--symbols", default=None); ap.add_argument("--limit", type=int, default=None)
args = ap.parse_args()
os.makedirs("results", exist_ok=True)
syms = sorted(os.path.basename(f)[:-4] for f in glob.glob("data/*.npy"))
if args.symbols:
    syms = args.symbols.split(",")
DAY = 86400000
t_all = time.time()


def load(sym, idx):
    a = np.load(f"data/{sym}.npy")
    t = a[:, 0].astype(np.int64); c = a[:, 4].copy()
    if args.null is not None:
        # universo nulo: permutación de los log-retornos (destruye toda estructura serial, conserva volatilidad y deriva total)
        rng = np.random.default_rng(args.null * 1000 + idx)
        lr = np.diff(np.log(c)); rng.shuffle(lr)
        c = np.exp(np.concatenate([[np.log(c[0])], np.log(c[0]) + np.cumsum(lr)]))
        c = np.round(c, 8)
    return t, c


for idx, sym in enumerate(syms):
    t0 = time.time()
    t, cf = load(sym, idx)
    ci = core.to_int_prices(cf)
    n = len(t)
    ks = np.arange(n)
    start = t[ks] - 20 * DAY + 3600000
    lo = np.searchsorted(t, start, side="left")
    hi = ks + 1
    ok = (t[ks] - t[0] >= 20 * DAY) & ((hi - lo) >= 900)
    ks = ks[ok]; lo = lo[ok].astype(np.int64); hi = hi[ok].astype(np.int64)
    if args.limit:
        ks, lo, hi = ks[:args.limit], lo[:args.limit], hi[:args.limit]
    E = len(ks)
    flags = np.zeros((E, core.NSTRAT), np.uint8)
    selm = np.zeros((E, 9))
    core.sweep(ci, cf, lo, hi, core.CFG_DEFAULT, flags, selm)
    np.savez_compressed(f"results/sweep_{args.tag}_{sym}.npz", k=ks, flags=flags, selm=selm)
    sel = (selm[:, 0] >= 0).sum()
    print(f"{sym}: evals={E} seleccionadas={sel} ({100*sel/E:.1f}%) disc>0={(flags&1).any(axis=1).sum()} t={time.time()-t0:.1f}s", flush=True)
print(f"TOTAL {time.time()-t_all:.0f}s")
