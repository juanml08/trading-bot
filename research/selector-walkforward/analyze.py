"""Análisis de la capa 1 (A vs B pareado) con IC por bootstrap de bloques.
Uso: python analyze.py <tag> [--null SEMILLA]  -> results/summary_<tag>.json (+ impresión)"""
import sys, json, argparse, time
import numpy as np
import core

SCEN = {"S0": (0.0, 0.0), "S1": (0.00075, 0.0002), "S2": (0.001, 0.0005), "S3": (0.001, 0.001)}
SEED = 20261011
DAY = 86400000


def net(ratio, status, fee, slip):
    r = (1 - fee) ** 2 * ratio * (1 - slip) / (1 + slip) - 1.0
    return np.where(status == 2, r, 0.0)


def block_ci(x, block_id, reps=10000, seed=SEED, stat="mean"):
    """IC 95% del cociente Σx/N con remuestreo de bloques (bootstrap por bloques de calendario)."""
    ub, inv = np.unique(block_id, return_inverse=True)
    B = len(ub)
    bs = np.bincount(inv, weights=x, minlength=B)
    bn = np.bincount(inv, minlength=B).astype(float)
    rng = np.random.default_rng(seed)
    idx = rng.integers(0, B, size=(reps, B))
    est = bs[idx].sum(1) / bn[idx].sum(1)
    return float(np.percentile(est, 2.5)), float(np.percentile(est, 97.5)), float(est.mean()), B


def main():
    ap = argparse.ArgumentParser(); ap.add_argument("tag"); ap.add_argument("--null", type=int, default=None)
    a = ap.parse_args()
    t0 = time.time()
    z = np.load(f"results/l1_{a.tag}.npz", allow_pickle=True)
    syms = list(z["syms"]); sym = z["sym"].astype(int); k0 = z["k0"]; selA = z["selA"].astype(int)
    surv = z["surv"]; front = z["front"]; status = z["status"]; ratio = z["ratio"]; ebars = z["ebars"].astype(int); xbars = z["xbars"].astype(int)
    reason = z["reason"]
    D = len(k0)
    d_idx = np.arange(D)
    tms = np.load(f"data/{syms[0]}.npy")[:, 0].astype(np.int64)
    tk = tms[k0]
    month = ((tk // DAY) // 30).astype(int)                 # bloque mensual simple (30 días) para estabilidad
    cal_month = np.array([int(np.datetime64(int(x), "ms").astype("datetime64[M]").astype(int)) for x in tk])
    out = {"D": int(D), "n_invalid": int(z["n_invalid"]), "symbols": syms}
    nsurv = surv.sum(1); nfront = front.sum(1)
    out["nsurv_mean"] = float(nsurv.mean()); out["nsurv_median"] = float(np.median(nsurv)); out["nsurv_eq1_pct"] = float((nsurv == 1).mean() * 100)
    out["nfront_mean"] = float(nfront.mean()); out["nfront_eq1_pct"] = float((nfront == 1).mean() * 100)
    out["A_in_front_pct"] = float(front[d_idx, selA].mean() * 100)
    sm = z["selm"]
    out["A_val_trades_le10_pct"] = float((sm[:, 5] <= 10).mean() * 100); out["A_val_trades_median"] = float(np.median(sm[:, 5]))
    out["A_val_wr_median"] = float(np.median(sm[:, 6])); out["A_train_trades_median"] = float(np.median(sm[:, 1]))
    # B&H de referencia: mismo símbolo, desde k0 hasta el fin del ciclo de A
    cl = {}
    for si, s in enumerate(syms):
        cl[si] = np.load(f"data/{s}.npy")[:, 4]
    if a.null is not None:
        for si in cl:
            rng = np.random.default_rng(a.null * 1000 + si)
            c = cl[si]; lr = np.diff(np.log(c)); rng.shuffle(lr)
            cl[si] = np.round(np.exp(np.concatenate([[np.log(c[0])], np.log(c[0]) + np.cumsum(lr)])), 8)
    entA = status[d_idx, selA] == 2
    cyc_len = np.where(entA, ebars[d_idx, selA] + xbars[d_idx, selA], core.HOLD_TICKS)
    bh_ratio = np.array([cl[sym[i]][k0[i] + cyc_len[i]] / cl[sym[i]][k0[i]] for i in range(D)])
    bh16 = np.array([cl[sym[i]][k0[i] + 16] / cl[sym[i]][k0[i]] for i in range(D)])
    btc = syms.index("BTCUSDT") if "BTCUSDT" in syms else None
    if btc is not None:
        cb = cl[btc]
        btc7 = np.array([cb[k] / cb[k - 336] - 1 for k in k0])
    res = {}
    for sc, (fee, slip) in SCEN.items():
        R = net(ratio, status, fee, slip)
        rA = R[d_idx, selA]
        mS = (R * surv).sum(1) / nsurv
        mF = (R * front).sum(1) / np.maximum(nfront, 1)
        mC = R.mean(1)
        dl = rA - mS
        bh = (1 - fee) ** 2 * bh_ratio * (1 - slip) / (1 + slip) - 1
        r = {}
        r["N"] = int(D)
        r["A_mean_pct"] = float(rA.mean() * 100); r["Bsurv_mean_pct"] = float(mS.mean() * 100)
        r["Bfront_mean_pct"] = float(mF.mean() * 100); r["Bcat_mean_pct"] = float(mC.mean() * 100)
        r["BH_same_span_mean_pct"] = float(bh.mean() * 100)
        r["BH16_mean_pct"] = float(((1 - fee) ** 2 * bh16 * (1 - slip) / (1 + slip) - 1).mean() * 100)
        for blk_days in (7, 14, 28):
            bid = (tk - tk.min()) // (blk_days * DAY)
            lo, hi, mean_b, nb = block_ci(dl, bid)
            r[f"delta_surv_ci_{blk_days}d"] = [lo * 100, hi * 100, nb]
            lo2, hi2, _, _ = block_ci(rA, bid)
            r[f"A_mean_ci_{blk_days}d"] = [lo2 * 100, hi2 * 100]
            lo3, hi3, _, _ = block_ci(rA - mC, bid)
            r[f"delta_cat_ci_{blk_days}d"] = [lo3 * 100, hi3 * 100]
            lo4, hi4, _, _ = block_ci(rA - mF, bid)
            r[f"delta_front_ci_{blk_days}d"] = [lo4 * 100, hi4 * 100]
            lo5, hi5, _, _ = block_ci(rA - bh, bid)
            r[f"delta_bh_ci_{blk_days}d"] = [lo5 * 100, hi5 * 100]
        r["delta_surv_pct"] = float(dl.mean() * 100); r["delta_front_pct"] = float((rA - mF).mean() * 100)
        r["delta_cat_pct"] = float((rA - mC).mean() * 100); r["delta_bh_pct"] = float((rA - bh).mean() * 100)
        # ¿decisiones donde A == único superviviente (sin elección real)?
        inf = nsurv > 1
        r["N_informative"] = int(inf.sum())
        r["delta_surv_informative_pct"] = float(dl[inf].mean() * 100) if inf.any() else None
        if inf.any():
            bid = (tk[inf] - tk.min()) // (14 * DAY)
            lo, hi, _, nb = block_ci(dl[inf], bid)
            r["delta_surv_informative_ci_14d"] = [lo * 100, hi * 100]
        # 2000 sorteos aleatorios reproducibles: percentil de A
        rng = np.random.default_rng(SEED)
        off = np.concatenate([[0], np.cumsum(nsurv)])
        flatR = R[surv]            # orden por fila (decisión) y estrategia
        draws = np.empty(2000)
        for j in range(2000):
            u = rng.integers(0, nsurv)
            draws[j] = flatR[off[:-1] + u].mean()
        r["Bdraws_mean_pct"] = float(draws.mean() * 100); r["Bdraws_p2.5_pct"] = float(np.percentile(draws, 2.5) * 100)
        r["Bdraws_p97.5_pct"] = float(np.percentile(draws, 97.5) * 100)
        r["A_percentile_in_Bdraws"] = float((draws < rA.mean()).mean() * 100)
        # robustez
        loo_sym = {}
        for si, s in enumerate(syms):
            m = sym != si
            if m.sum():
                loo_sym[s] = float(dl[m].mean() * 100)
        r["delta_leave_one_symbol_min"] = min(loo_sym.values()); r["delta_leave_one_symbol_max"] = max(loo_sym.values())
        r["delta_leave_one_symbol_all_positive"] = bool(all(v > 0 for v in loo_sym.values()))
        loo_m = {}
        for cm in np.unique(cal_month):
            m = cal_month != cm
            loo_m[int(cm)] = float(dl[m].mean() * 100)
        r["delta_leave_one_month_min"] = min(loo_m.values()); r["delta_leave_one_month_max"] = max(loo_m.values())
        r["delta_leave_one_month_all_positive"] = bool(all(v > 0 for v in loo_m.values()))
        q = np.quantile(rA, 0.99)
        trim = rA < q
        r["delta_trim_top1pct_A"] = float((rA[trim] - mS[trim]).mean() * 100)
        r["A_trim_top1pct_mean_pct"] = float(rA[trim].mean() * 100)
        # métricas de A vs B esperadas (exactas)
        def stats(Rm, w):    # w: máscara de pesos por estrategia (filas = decisiones), esperado sobre sorteo uniforme
            W = w / np.maximum(w.sum(1, keepdims=True), 1)
            ent = (status == 2)
            ent_rate = (ent * W).sum(1).mean()
            win = ((Rm > 0) & ent) * W
            pos = (np.where(Rm > 0, Rm, 0) * W).sum(); neg = (np.where(Rm < 0, -Rm, 0) * W).sum()
            hold = (xbars * ent * W).sum() / max((ent * W).sum(), 1e-12)
            stop = ((reason == core.R_STOP) * ent * W).sum() / max((ent * W).sum(), 1e-12)
            tim = ((reason == core.R_TIME) * ent * W).sum() / max((ent * W).sum(), 1e-12)
            sel = ((reason == core.R_SELL) * ent * W).sum() / max((ent * W).sum(), 1e-12)
            exp_bars = (xbars * ent * W).sum(1).sum()
            return dict(entry_rate_pct=float(ent_rate * 100), wr_net_pct=float(win.sum() / max((ent * W).sum(), 1e-12) * 100),
                        expectancy_per_closed_trade_pct=float((Rm * ent * W).sum() / max((ent * W).sum(), 1e-12) * 100),
                        expectancy_per_decision_pct=float((Rm * W).sum(1).mean() * 100),
                        profit_factor=float(pos / neg) if neg > 0 else None, avg_hold_bars=float(hold),
                        stop_pct=float(stop * 100), time_exit_pct=float(tim * 100), sell_pct=float(sel * 100),
                        exposure_bars_per_decision=float(exp_bars / D),
                        ret_per_exposure_hour_pct=float((Rm * W).sum() / max(exp_bars, 1e-12) / 0.5 * 100))
        wA = np.zeros_like(surv, dtype=float); wA[d_idx, selA] = 1.0
        r["A_stats"] = stats(R, wA); r["Bsurv_stats"] = stats(R, surv.astype(float)); r["Bfront_stats"] = stats(R, front.astype(float))
        r["Bcat_stats"] = stats(R, np.ones_like(surv, dtype=float))
        # por símbolo
        per = {}
        for si, s in enumerate(syms):
            m = sym == si
            if m.sum() == 0:
                continue
            per[s] = dict(N=int(m.sum()), A_pct=float(rA[m].mean() * 100), Bsurv_pct=float(mS[m].mean() * 100), delta_pct=float(dl[m].mean() * 100),
                          A_sum_pct=float(rA[m].sum() * 100), share_of_total_sum_pct=float(rA[m].sum() / rA.sum() * 100) if rA.sum() != 0 else None)
        r["per_symbol"] = per
        perm = {}
        for cm in np.unique(cal_month):
            m = cal_month == cm
            lab = str(np.datetime64(int(cm), "M"))
            perm[lab] = dict(N=int(m.sum()), A_pct=float(rA[m].mean() * 100), Bsurv_pct=float(mS[m].mean() * 100), delta_pct=float(dl[m].mean() * 100),
                             BH_pct=float(bh[m].mean() * 100), A_wr_pct=float((rA[m & entA] > 0).mean() * 100) if (m & entA).any() else None)
        r["per_month"] = perm
        if btc is not None:
            reg = {}
            for nm, m in (("BTC_7d_up", btc7 > 0.01), ("BTC_7d_flat", np.abs(btc7) <= 0.01), ("BTC_7d_down", btc7 < -0.01)):
                if m.sum():
                    reg[nm] = dict(N=int(m.sum()), A_pct=float(rA[m].mean() * 100), Bsurv_pct=float(mS[m].mean() * 100), delta_pct=float(dl[m].mean() * 100), BH_pct=float(bh[m].mean() * 100))
            r["per_regime"] = reg
        # tamaño efectivo
        clusters = len(set(zip(sym.tolist(), (tk // (7 * DAY)).tolist())))
        r["distinct_symbol_weeks"] = clusters
        # retrospectiva: mejor estrategia fija del catálogo sobre estos instantes
        by_strat = R.mean(0) * 100
        r["best_fixed_strategy_in_hindsight"] = dict(name=core.NAMES[int(by_strat.argmax())], mean_pct=float(by_strat.max()),
                                                     median_strategy_pct=float(np.median(by_strat)), worst_pct=float(by_strat.min()))
        res[sc] = r
    out["scenarios"] = res
    # frecuencia de selección y de aprobaciones por azar (a partir de los barridos)
    json.dump(out, open(f"results/summary_{a.tag}.json", "w"), indent=1)
    s2 = res["S2"]
    print(f"D={D} nsurv_mean={out['nsurv_mean']:.1f} nsurv==1: {out['nsurv_eq1_pct']:.0f}% t={time.time()-t0:.0f}s")
    for sc, r in res.items():
        print(f"{sc}: A={r['A_mean_pct']:+.3f}% Bsurv={r['Bsurv_mean_pct']:+.3f}% Δ={r['delta_surv_pct']:+.3f}% IC14d[{r['delta_surv_ci_14d'][0]:+.3f},{r['delta_surv_ci_14d'][1]:+.3f}] "
              f"Bcat={r['Bcat_mean_pct']:+.3f}% BH={r['BH_same_span_mean_pct']:+.3f}%")


if __name__ == "__main__":
    main()
