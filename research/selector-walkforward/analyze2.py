"""Análisis complementario: filtro incondicional, capa 2 (cartera), estructura de la selección, stops, comparaciones múltiples.
Uso: python analyze2.py real  -> results/summary2_real.json ; python analyze2.py nulls -> compara universos nulos."""
import sys, json, glob, os
import numpy as np
import core
from analyze import block_ci, SEED, DAY

tag = sys.argv[1]


def uncond_block(tag):
    z = np.load(f"results/uncond_{tag}.npz")
    sums, cnts, k0, sym = z["sums"], z["cnts"], z["k0"], z["sym"]
    t = np.load("data/BTCUSDT.npy")[:, 0].astype(np.int64)
    tk = t[k0]
    bid = (tk - tk.min()) // (14 * DAY)
    out = {"instants": int(len(k0))}
    mean = lambda g, sc: np.where(cnts[:, g] > 0, sums[:, g, sc] / np.maximum(cnts[:, g], 1), np.nan)
    for sc, nm in ((0, "S0"), (1, "S2")):
        m = {g: mean(g, sc) for g in range(5)}
        r = {"mean_pct": {n: float(np.nanmean(m[g]) * 100) for g, n in enumerate(("surv", "disc_only", "nodisc", "catalog", "selected_A"))}}
        for (a, b, nm2) in ((0, 1, "surv-disc_only"), (0, 2, "surv-nodisc"), (0, 3, "surv-catalog"), (4, 0, "selectedA-surv"), (4, 3, "selectedA-catalog")):
            ok = ~np.isnan(m[a]) & ~np.isnan(m[b])
            d = (m[a] - m[b])[ok]
            lo, hi, _, nb = block_ci(d, bid[ok])
            r[nm2] = dict(delta_pct=float(d.mean() * 100), ci95_pct=[lo * 100, hi * 100], n=int(ok.sum()), blocks=nb)
        out[nm] = r
    return out


def stats_from_trades(tr):
    r = tr[:, 3]
    if len(r) == 0:
        return {}
    pos, neg = r[r > 0].sum(), -r[r < 0].sum()
    return dict(n=int(len(r)), wr_pct=float((r > 0).mean() * 100), expectancy_pct=float(r.mean() * 100), pf=float(pos / neg) if neg > 0 else None,
                avg_hold_bars=float(tr[:, 5].mean()), stop_pct=float((tr[:, 4] == core.R_STOP).mean() * 100), time_pct=float((tr[:, 4] == core.R_TIME).mean() * 100),
                sell_pct=float((tr[:, 4] == core.R_SELL).mean() * 100))


def layer2_block(tag):
    z = np.load(f"results/l2_{tag}.npz", allow_pickle=True)
    out = {}
    for sc in ("S0", "S1", "S2", "S3"):
        r = {}
        for name in ("A", "Bsurv", "Bfront"):
            eq = z[f"{sc}_{name}_eq"].astype(np.float64); ex = z[f"{sc}_{name}_exp"]; act = z[f"{sc}_{name}_act"]
            trn = z[f"{sc}_{name}_trn"]; tr = z[f"{sc}_{name}_tr"].astype(np.float64)
            off = np.concatenate([[0], np.cumsum(trn)])
            final = eq[:, -1] - 1.0
            peak = np.maximum.accumulate(np.maximum(eq, 1.0), axis=1)
            mdd = ((peak - eq) / peak).max(axis=1)
            per = []
            for i in range(len(trn)):
                per.append(stats_from_trades(tr[off[i]:off[i + 1]]))
            g = lambda key: np.array([p.get(key, np.nan) if p.get(key) is not None else np.nan for p in per], dtype=float)
            r[name] = dict(
                paths=int(len(trn)), final_return_pct=[float(np.median(final) * 100), float(np.percentile(final, 2.5) * 100), float(np.percentile(final, 97.5) * 100)],
                max_drawdown_pct=[float(np.median(mdd) * 100), float(np.percentile(mdd, 2.5) * 100), float(np.percentile(mdd, 97.5) * 100)],
                trades=[float(np.median(trn)), float(np.percentile(trn, 2.5)), float(np.percentile(trn, 97.5))],
                wr_pct=[float(np.nanmedian(g("wr_pct"))), float(np.nanpercentile(g("wr_pct"), 2.5)), float(np.nanpercentile(g("wr_pct"), 97.5))],
                expectancy_pct=[float(np.nanmedian(g("expectancy_pct"))), float(np.nanpercentile(g("expectancy_pct"), 2.5)), float(np.nanpercentile(g("expectancy_pct"), 97.5))],
                pf=[float(np.nanmedian(g("pf"))), float(np.nanpercentile(g("pf"), 2.5)), float(np.nanpercentile(g("pf"), 97.5))],
                avg_hold_hours=float(np.nanmedian(g("avg_hold_bars")) / 2), stop_pct=float(np.nanmedian(g("stop_pct"))), time_pct=float(np.nanmedian(g("time_pct"))),
                sell_pct=float(np.nanmedian(g("sell_pct"))),
                exposure_mean_pct=float(ex.mean() * 100), active_cycles_mean=float(act.mean()), active_cycles_max=int(act.max()),
                pct_time_at_slot_cap=float((act >= 20).mean() * 100))
            if name == "A":
                r["A_final_return_pct"] = float(final[0] * 100); r["A_mdd_pct"] = float(mdd[0] * 100)
        fa = r["A_final_return_pct"]
        bf = (z[f"{sc}_Bsurv_eq"][:, -1].astype(np.float64) - 1.0) * 100
        r["A_percentile_in_Bsurv_final_return"] = float((bf < fa).mean() * 100)
        out[sc] = r
    return out


def selection_block(tag):
    z = np.load(f"results/l1_{tag}.npz", allow_pickle=True)
    selA = z["selA"].astype(int); sym = z["sym"].astype(int); syms = list(z["syms"]); D = len(selA)
    status, ratio, reason = z["status"], z["ratio"], z["reason"]
    out = {}
    lp = core.S_LP[selA]; kind = core.S_KIND[selA]
    out["A_long200_pct"] = float((lp == 200).mean() * 100)
    out["A_long_ge175_pct"] = float((lp >= 175).mean() * 100)
    out["A_sma_pct"] = float((kind == 0).mean() * 100)
    names, counts = np.unique(selA, return_counts=True)
    top = np.argsort(-counts)[:10]
    out["A_distinct_strategies"] = int(len(names))
    out["A_top10_strategies"] = [(core.NAMES[names[i]], int(counts[i])) for i in top]
    out["A_top10_share_pct"] = float(counts[top].sum() / D * 100)
    # selección repetida consecutiva por símbolo
    rep = tot = 0
    for si in range(len(syms)):
        s = selA[sym == si]
        if len(s) > 1:
            rep += (s[1:] == s[:-1]).sum(); tot += len(s) - 1
    out["A_same_strategy_as_previous_decision_same_symbol_pct"] = float(rep / max(tot, 1) * 100)
    # pérdidas realizadas en stops (todas las estrategias x decisiones y solo A)
    m = (status == 2) & (reason == core.R_STOP)
    loss = (1 - ratio[m]) * 100
    out["stop_realized_loss_pct_all"] = dict(n=int(m.sum()), mean=float(loss.mean()), median=float(np.median(loss)), p90=float(np.percentile(loss, 90)), p99=float(np.percentile(loss, 99)),
                                             max=float(loss.max()), share_gt4=float((loss > 4).mean() * 100), share_gt5=float((loss > 5).mean() * 100), share_gt10=float((loss > 10).mean() * 100))
    ma = (status[np.arange(D), selA] == 2) & (reason[np.arange(D), selA] == core.R_STOP)
    la = (1 - ratio[np.arange(D), selA][ma]) * 100
    out["stop_realized_loss_pct_A"] = dict(n=int(ma.sum()), mean=float(la.mean()) if len(la) else None, max=float(la.max()) if len(la) else None)
    # backtest (validación) vs hacia adelante
    sm = z["selm"]
    ent = status[np.arange(D), selA] == 2
    fwd_gross = (ratio[np.arange(D), selA][ent] - 1) * 100
    out["A_validation_wr_median_pct"] = float(np.median(sm[:, 6])); out["A_validation_plp_median"] = float(np.median(sm[:, 7]))
    out["A_forward_gross_wr_pct"] = float((fwd_gross > 0).mean() * 100); out["A_forward_gross_mean_pct"] = float(fwd_gross.mean())
    out["A_forward_gross_wr_pct_excluding_flat"] = float((fwd_gross > 0).sum() / max((fwd_gross != 0).sum(), 1) * 100)
    out["A_validation_trades_pctiles"] = {p: float(np.percentile(sm[:, 5], p)) for p in (10, 25, 50, 75, 90)}
    out["A_val_trades_le10_pct"] = float((sm[:, 5] <= 10).mean() * 100)
    # dependencia: autocorrelación lag-1 de retornos S0 de A dentro del símbolo
    r0 = np.where(status[np.arange(D), selA] == 2, ratio[np.arange(D), selA] - 1, 0.0)
    xs, ys = [], []
    for si in range(len(syms)):
        v = r0[sym == si]
        if len(v) > 2:
            xs.append(v[:-1]); ys.append(v[1:])
    out["A_lag1_autocorr_gross_returns_within_symbol"] = float(np.corrcoef(np.concatenate(xs), np.concatenate(ys))[0, 1])
    return out


def multiple_block(tags):
    res = {}
    for tg in tags:
        files = sorted(glob.glob(f"results/sweep_{tg}_*.npz"))
        nd = ns = ne = nsel = 0
        for f in files:
            z = np.load(f); fl = z["flags"]
            nd += (fl & 1).sum(); ns += ((fl & 2) > 0).sum(); ne += len(fl); nsel += (z["selm"][:, 0] >= 0).sum()
        has_s = sum((((np.load(f)["flags"] & 2) > 0).any(axis=1)).sum() for f in files)
        res[tg] = dict(evals=int(ne), disc_passers_per_eval=float(nd / ne), val_survivors_per_eval=float(ns / ne), pct_evals_with_survivor=float(has_s / ne * 100), pct_evals_selected=float(nsel / ne * 100))
    return res


if tag == "real":
    out = dict(uncond=uncond_block("real"), layer2=layer2_block("real"), selection=selection_block("real"))
    nulls = [os.path.basename(p)[len("sweep_"):].split("_")[0] for p in glob.glob("results/sweep_null*_ATOMUSDT.npz")]
    out["multiple_comparisons"] = multiple_block(["real"] + sorted(set(nulls)))
    json.dump(out, open("results/summary2_real.json", "w"), indent=1)
    print(json.dumps(out["uncond"], indent=1)); print(json.dumps(out["multiple_comparisons"], indent=1))
else:   # nulls
    out = {}
    for p in sorted(glob.glob("results/summary_null*.json")):
        s = json.load(open(p))["scenarios"]
        out[os.path.basename(p)] = {sc: dict(A=s[sc]["A_mean_pct"], Bsurv=s[sc]["Bsurv_mean_pct"], delta=s[sc]["delta_surv_pct"], ci14=s[sc]["delta_surv_ci_14d"][:2],
                                              A_entry=s[sc]["A_stats"]["entry_rate_pct"], A_wr=s[sc]["A_stats"]["wr_net_pct"]) for sc in ("S0", "S2")}
    print(json.dumps(out, indent=1))
