"""Capa 1 (evento pareado): decisiones secuenciales de A por símbolo + resultado del ciclo para TODAS las estrategias en el mismo instante.
Uso: python l1.py <tag> [--null SEMILLA]   (lee results/sweep_<tag>_*.npz, escribe results/l1_<tag>.npz)"""
import sys, os, glob, argparse, time
import numpy as np
from numba import njit, prange
import core


@njit(cache=True)
def a_decisions(t, cs, cf, ks, selidx):
    """Recorre los instantes en orden; si A selecciona y el símbolo no tiene ciclo activo de A -> decisión.
    El símbolo queda ocupado hasta el fin del ciclo (índice end_idx); se libera para búsquedas en instantes > end_idx."""
    E = len(ks)
    de = np.zeros(E, np.int64)
    dk = np.zeros(E, np.int64)
    ds = np.zeros(E, np.int64)
    nd = 0
    ninv = 0
    busy = -1
    o = np.zeros(7)
    for e in range(E):
        k0 = ks[e]
        if k0 <= busy:
            continue
        s = selidx[e]
        if s < 0:
            continue
        core.cycle_sim(t, cs, cf, k0, s, o)
        if o[0] == 0.0:
            ninv += 1
            continue
        de[nd] = e
        dk[nd] = k0
        ds[nd] = s
        nd += 1
        busy = int(o[6])
    return de[:nd], dk[:nd], ds[:nd], ninv


@njit(parallel=True, cache=True)
def all_outcomes(t, cs, cf, k0s, status, ratio, ebars, xbars, reason):
    """Para cada decisión d y cada estrategia k: resultado del ciclo activado en k0s[d]."""
    D = len(k0s)
    for d in prange(D):
        o = np.zeros(7)
        for k in range(core.NSTRAT):
            core.cycle_sim(t, cs, cf, k0s[d], k, o)
            status[d, k] = np.int8(o[0])
            if o[0] == 2.0:
                ratio[d, k] = o[5] / o[4]
                ebars[d, k] = np.int16(o[1] - k0s[d])
                xbars[d, k] = np.int16(o[2] - o[1])
                reason[d, k] = np.int8(o[3])
            else:
                ratio[d, k] = 1.0
                ebars[d, k] = np.int16(-1)
                xbars[d, k] = np.int16(0)
                reason[d, k] = np.int8(0)


def build(tag, null=None):
    files = sorted(glob.glob(f"results/sweep_{tag}_*.npz"))
    cols = {k: [] for k in ("sym", "k0", "e", "selA", "surv", "front", "status", "ratio", "ebars", "xbars", "reason", "selm")}
    syms = []
    ninv_tot = 0
    for si, f in enumerate(files):
        sym = os.path.basename(f)[len(f"sweep_{tag}_"):-4]
        syms.append(sym)
        z = np.load(f)
        ks, flags, selm = z["k"], z["flags"], z["selm"]
        a = np.load(f"data/{sym}.npy")
        t = a[:, 0].astype(np.int64)
        cf = a[:, 4].copy()
        if null is not None:   # misma permutación que run_sweep.py
            rng = np.random.default_rng(null * 1000 + si)
            lr = np.diff(np.log(cf)); rng.shuffle(lr)
            cf = np.round(np.exp(np.concatenate([[np.log(cf[0])], np.log(cf[0]) + np.cumsum(lr)])), 8)
        ci = core.to_int_prices(cf)
        cs = core.make_prefix(ci)
        selidx = selm[:, 0].astype(np.int64)
        de, dk, ds, ninv = a_decisions(t, cs, cf, ks, selidx)
        ninv_tot += ninv
        D = len(dk)
        status = np.zeros((D, core.NSTRAT), np.int8)
        ratio = np.ones((D, core.NSTRAT))
        ebars = np.zeros((D, core.NSTRAT), np.int16)
        xbars = np.zeros((D, core.NSTRAT), np.int16)
        reason = np.zeros((D, core.NSTRAT), np.int8)
        all_outcomes(t, cs, cf, dk, status, ratio, ebars, xbars, reason)
        fl = flags[de]
        cols["sym"].append(np.full(D, si, np.int16)); cols["k0"].append(dk); cols["e"].append(de); cols["selA"].append(ds)
        cols["surv"].append((fl & 2) > 0); cols["front"].append((fl & 4) > 0)
        cols["status"].append(status); cols["ratio"].append(ratio); cols["ebars"].append(ebars); cols["xbars"].append(xbars); cols["reason"].append(reason)
        cols["selm"].append(selm[de])
        print(f"  {sym}: decisiones={D}", flush=True)
    out = {k: np.concatenate(v) for k, v in cols.items()}
    out["syms"] = np.array(syms)
    out["n_invalid"] = np.array(ninv_tot)
    np.savez_compressed(f"results/l1_{tag}.npz", **out)
    return out


if __name__ == "__main__":
    ap = argparse.ArgumentParser(); ap.add_argument("tag"); ap.add_argument("--null", type=int, default=None)
    a = ap.parse_args()
    t0 = time.time()
    o = build(a.tag, a.null)
    print(f"decisiones totales={len(o['k0'])} inválidas={int(o['n_invalid'])} t={time.time()-t0:.0f}s")
