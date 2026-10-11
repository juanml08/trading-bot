"""Análisis incondicional del filtro: en TODOS los instantes con al menos un superviviente (sin estado ocupado),
¿rinden mejor hacia adelante las estrategias que pasaron Validation que las que no?
Para cada instante (símbolo, vela) y para 5 grupos de estrategias: media del retorno neto (S0 y S2) del ciclo simulado.
Grupos: 0 supervivientes, 1 pasaron Discovery pero no Validation, 2 no pasaron Discovery, 3 todas (catálogo), 4 seleccionada por A.
Uso: python uncond.py <tag> [--null SEMILLA] [--stride N]  -> results/uncond_<tag>.npz"""
import sys, os, glob, argparse, time
import numpy as np
from numba import njit, prange
import core

FEES = np.array([0.0, 0.001]); SLIPS = np.array([0.0, 0.0005])


@njit(parallel=True, cache=True)
def run(t, cs, cf, k0s, flags, sel, fees, slips, sums, cnts):
    E = len(k0s)
    for e in prange(E):
        o = np.zeros(7)
        for k in range(core.NSTRAT):
            core.cycle_sim(t, cs, cf, k0s[e], k, o)
            if o[0] == 0.0:
                continue
            f = flags[e, k]
            grp = 0 if (f & 2) else (1 if (f & 1) else 2)
            for sc in range(2):
                r = 0.0
                if o[0] == 2.0:
                    r = (1 - fees[sc]) ** 2 * (o[5] / o[4]) * (1 - slips[sc]) / (1 + slips[sc]) - 1.0
                sums[e, grp, sc] += r
                sums[e, 3, sc] += r
                if k == sel[e]:
                    sums[e, 4, sc] += r
            cnts[e, grp] += 1
            cnts[e, 3] += 1
            if k == sel[e]:
                cnts[e, 4] += 1


def main():
    ap = argparse.ArgumentParser(); ap.add_argument("tag"); ap.add_argument("--null", type=int, default=None); ap.add_argument("--stride", type=int, default=1)
    a = ap.parse_args()
    t0 = time.time()
    files = sorted(glob.glob(f"results/sweep_{a.tag}_*.npz"))
    cols = {k: [] for k in ("sym", "k0", "sums", "cnts")}
    for si, f in enumerate(files):
        sym = os.path.basename(f)[len(f"sweep_{a.tag}_"):-4]
        z = np.load(f); ks, flags, selm = z["k"], z["flags"], z["selm"]
        arr = np.load(f"data/{sym}.npy"); t = arr[:, 0].astype(np.int64); cf = arr[:, 4].copy()
        if a.null is not None:
            rng = np.random.default_rng(a.null * 1000 + si)
            lr = np.diff(np.log(cf)); rng.shuffle(lr)
            cf = np.round(np.exp(np.concatenate([[np.log(cf[0])], np.log(cf[0]) + np.cumsum(lr)])), 8)
        cs = core.make_prefix(core.to_int_prices(cf))
        has = ((flags & 2) > 0).any(axis=1) & (ks + 2 * core.HOLD_TICKS < len(t) - 1) & (np.arange(len(ks)) % a.stride == 0)
        idx = np.where(has)[0]
        sums = np.zeros((len(idx), 5, 2)); cnts = np.zeros((len(idx), 5))
        run(t, cs, cf, ks[idx].astype(np.int64), flags[idx], selm[idx, 0].astype(np.int64), FEES, SLIPS, sums, cnts)
        cols["sym"].append(np.full(len(idx), si, np.int16)); cols["k0"].append(ks[idx]); cols["sums"].append(sums); cols["cnts"].append(cnts)
        print(f"  {sym}: instantes con superviviente={len(idx)} t={time.time()-t0:.0f}s", flush=True)
    np.savez_compressed(f"results/uncond_{a.tag}.npz", **{k: np.concatenate(v) for k, v in cols.items()})


if __name__ == "__main__":
    main()
