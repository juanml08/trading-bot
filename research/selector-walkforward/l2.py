"""Capa 2: réplica secuencial de la operativa completa del bot (scanner por volumen, hasta 20 ciclos simultáneos,
capital por slot = efectivo libre / 20) para A (selector real) y B (aleatorio entre supervivientes / frente de Pareto).
Uso: python l2.py <tag> [--reps 200] [--seed 20261011] [--null SEMILLA]
Salida: results/l2_<tag>.npz"""
import sys, os, glob, argparse, time
import numpy as np
from numba import njit, prange
import core

MAX_ACTIVE = 20
MAXTR = 20000


@njit(cache=True)
def run_path(mode, seed, T, C, CS, V23, FLAGS, SEL, g0, g1, fee, slip, eq_out, exp_out, act_out, tr):
    """mode 0=A, 1=B_surv, 2=B_front. tr[i]=(sym, entry_g, exit_g, net_ret, reason, hold_bars). Devuelve nº de trades."""
    if seed >= 0:
        np.random.seed(seed)
    NS = C.shape[0]
    state = np.zeros(NS, np.int8)
    entry_g = np.full(NS, -1, np.int64)
    end_g = np.full(NS, -1, np.int64)
    reason = np.zeros(NS, np.int64)
    units = np.zeros(NS)
    stake = np.zeros(NS)
    last_end = np.full(NS, -1, np.int64)
    cash = 1.0
    peak = 1.0
    ntr = 0
    o = np.zeros(7)
    for g in range(g0, g1):
        # 1) salidas / expiraciones
        for s in range(NS):
            if state[s] > 0 and end_g[s] == g:
                if state[s] == 2:
                    proceeds = units[s] * C[s, g] * (1.0 - slip) * (1.0 - fee)
                    cash += proceeds
                    if ntr < tr.shape[0]:
                        tr[ntr, 0] = s
                        tr[ntr, 1] = entry_g[s]
                        tr[ntr, 2] = g
                        tr[ntr, 3] = proceeds / stake[s] - 1.0
                        tr[ntr, 4] = reason[s]
                        tr[ntr, 5] = g - entry_g[s]
                    ntr += 1
                    units[s] = 0.0
                state[s] = 0
                last_end[s] = g
        # 2) entradas programadas
        for s in range(NS):
            if state[s] == 1 and entry_g[s] == g:
                st = cash / MAX_ACTIVE
                cash -= st
                stake[s] = st
                units[s] = st * (1.0 - fee) / (C[s, g] * (1.0 + slip))
                state[s] = 2
        # 3) búsqueda (orden del scanner: volumen de las últimas 23 velas, descendente)
        nact = 0
        for s in range(NS):
            if state[s] > 0:
                nact += 1
        avail = MAX_ACTIVE - nact
        if avail > 0:
            order = np.argsort(-V23[:, g])
            for r in range(NS):
                if avail <= 0:
                    break
                s = order[r]
                if SEL[s, g] == -2 or state[s] > 0 or g <= last_end[s]:
                    continue
                k = -1
                if mode == 0:
                    k = SEL[s, g]
                else:
                    bit = 2 if mode == 1 else 4
                    cnt = 0
                    for j in range(core.NSTRAT):
                        if FLAGS[s, g, j] & bit:
                            cnt += 1
                    if cnt > 0:
                        pick = np.random.randint(0, cnt)
                        c2 = 0
                        for j in range(core.NSTRAT):
                            if FLAGS[s, g, j] & bit:
                                if c2 == pick:
                                    k = j
                                    break
                                c2 += 1
                if k < 0:
                    continue
                core.cycle_sim(T, CS[s], C[s], g, k, o)
                if o[0] == 0.0:
                    continue
                avail -= 1
                state[s] = 1
                reason[s] = int(o[3])
                if o[0] == 1.0:
                    entry_g[s] = -1
                    end_g[s] = int(o[6])
                else:
                    entry_g[s] = int(o[1])
                    end_g[s] = int(o[2])
                    if entry_g[s] == g:
                        st = cash / MAX_ACTIVE
                        cash -= st
                        stake[s] = st
                        units[s] = st * (1.0 - fee) / (C[s, g] * (1.0 + slip))
                        state[s] = 2
        # 4) valoración
        inv = 0.0
        na = 0
        for s in range(NS):
            if state[s] == 2:
                inv += units[s] * C[s, g]
            if state[s] > 0:
                na += 1
        eq = cash + inv
        eq_out[g] = eq
        exp_out[g] = inv / eq
        act_out[g] = na
    return ntr


def load_all(tag, null=None):
    files = sorted(glob.glob(f"results/sweep_{tag}_*.npz"))
    syms = [os.path.basename(f)[len(f"sweep_{tag}_"):-4] for f in files]
    arrs = [np.load(f"data/{s}.npy") for s in syms]
    G = len(arrs[0])
    assert all(len(a) == G and (a[:, 0] == arrs[0][:, 0]).all() for a in arrs), "ejes de tiempo distintos"
    T = arrs[0][:, 0].astype(np.int64)
    C = np.zeros((len(syms), G)); V23 = np.zeros((len(syms), G))
    CS = np.zeros((len(syms), G + 1), np.int64)
    SEL = np.full((len(syms), G), -2, np.int16)
    FLAGS = np.zeros((len(syms), G, core.NSTRAT), np.uint8)
    for si, (f, a) in enumerate(zip(files, arrs)):
        c = a[:, 4].copy()
        if null is not None:
            rng = np.random.default_rng(null * 1000 + si)
            lr = np.diff(np.log(c)); rng.shuffle(lr)
            c = np.round(np.exp(np.concatenate([[np.log(c[0])], np.log(c[0]) + np.cumsum(lr)])), 8)
        C[si] = c
        CS[si] = core.make_prefix(core.to_int_prices(c))
        cv = np.cumsum(np.concatenate([[0.0], a[:, 5]]))
        V23[si, 22:] = cv[23:] - cv[:-23]
        z = np.load(f)
        k = z["k"]
        SEL[si, k] = z["selm"][:, 0].astype(np.int16)
        FLAGS[si, k, :] = z["flags"]
    return syms, T, C, CS, V23, FLAGS, SEL


SCEN = {"S0": (0.0, 0.0), "S1": (0.00075, 0.0002), "S2": (0.001, 0.0005), "S3": (0.001, 0.001)}


def main():
    ap = argparse.ArgumentParser(); ap.add_argument("tag"); ap.add_argument("--reps", type=int, default=200)
    ap.add_argument("--seed", type=int, default=20261011); ap.add_argument("--null", type=int, default=None)
    a = ap.parse_args()
    t0 = time.time()
    syms, T, C, CS, V23, FLAGS, SEL = load_all(a.tag, a.null)
    G = C.shape[1]
    valid_g = np.where((SEL >= -1).any(axis=0))[0]
    g0 = int(valid_g[0]); g1 = G - 2 * core.HOLD_TICKS - 1
    print(f"símbolos={len(syms)} G={G} g0={g0} g1={g1} carga={time.time()-t0:.0f}s", flush=True)
    out = {"syms": np.array(syms), "g0": g0, "g1": g1, "T": T}
    for sc, (fee, slip) in SCEN.items():
        modes = [(0, 1, "A"), (1, a.reps, "Bsurv"), (2, a.reps, "Bfront")]
        for mode, reps, name in modes:
            eqs = np.zeros((reps, G)); exps = np.zeros((reps, G)); acts = np.zeros((reps, G))
            trs = np.zeros((reps, MAXTR, 6)); nts = np.zeros(reps, np.int64)
            for r in range(reps):
                seed = -1 if mode == 0 else a.seed + r
                nts[r] = run_path(mode, seed, T, C, CS, V23, FLAGS, SEL, g0, g1, fee, slip, eqs[r], exps[r], acts[r], trs[r])
            if nts.max() > MAXTR:
                print("¡aviso! trades > MAXTR", nts.max())
            out[f"{sc}_{name}_eq"] = eqs[:, g0:g1].astype(np.float32)
            out[f"{sc}_{name}_exp"] = exps[:, g0:g1].astype(np.float32)
            out[f"{sc}_{name}_act"] = acts[:, g0:g1].astype(np.int16)
            out[f"{sc}_{name}_nt"] = nts
            # trades: guardar solo los de la primera réplica completa para A; para B guardar todos comprimidos
            out[f"{sc}_{name}_tr"] = np.concatenate([trs[r, :min(nts[r], MAXTR)] for r in range(reps)]).astype(np.float32)
            out[f"{sc}_{name}_trn"] = np.minimum(nts, MAXTR)
            print(f"{sc} {name}: reps={reps} final_eq(med)={np.median(eqs[:, g1-1]):.4f} trades(med)={np.median(nts):.0f} t={time.time()-t0:.0f}s", flush=True)
    np.savez_compressed(f"results/l2_{a.tag}.npz", **out)


if __name__ == "__main__":
    main()
