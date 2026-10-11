"""E3: señal C (48 h) frente a controles comparables en mercado, régimen y timing de corto plazo.
Ver PREREGISTRO.md (cerrado antes de escribir/ejecutar este script) y AUDITORIA_E2.md.

Uso (desde research/e3-market-control):
  python -I e3.py audit      # re-verifica E2 en solo lectura -> results/audit_e2.json
  python -I e3.py selftest   # validaciones básicas (incluye reproducción de E1 y prueba sintética de confusión)
  python -I e3.py run        # puerta V0 + primaria, ventana 28 d, ablación -> results/
  python -I e3.py placebo    # placebo del procedimiento completo (paralelo, reproducible)
  python -I e3.py decide     # aplica la sección 10 del preregistro -> results/decision.json, tables.md
  python -I e3.py repro      # recalcula la primaria y compara con results/ (reproducibilidad)
Solo lectura sobre ../signal-audit, ../e1-multiday y ../e2-market-control. Sin red, sin BD.
"""
import sys, os, json, hashlib, time
sys.dont_write_bytecode = True
import numpy as np
from numpy.lib.stride_tricks import sliding_window_view as swv

try:
    sys.stdout.reconfigure(encoding="utf-8")
except Exception:
    pass

HERE = os.path.dirname(os.path.abspath(__file__))
E1D = os.path.normpath(os.path.join(HERE, "..", "e1-multiday"))
E2D = os.path.normpath(os.path.join(HERE, "..", "e2-market-control"))
SA = os.path.normpath(os.path.join(HERE, "..", "signal-audit"))
OUT = os.path.join(HERE, "results")
sys.path.insert(0, SA)
sys.path.insert(0, E1D)
import audit_4h as A  # noqa: E402
import e1  # noqa: E402

SEED = 20261011
FEE, SLIP = 0.001, 0.0005
N_PAR, HZ = 24, 12
DRAWS, NBOOT = 2000, 10000
BLOCKS = (14, 7, 28)
W14, W28 = 84, 168                 # ventanas en velas de 4h
ZONE = 18                          # |t'-t| <= ZONE excluido (= cooldown de C)
CAL_K = 0.25                       # caliper = 0.25 * desviación típica
MINPOOL, MINFRAC = 5, 0.5
PL_N, PL_DRAWS, PL_B = 200, 100, 2000
KEEP_PORT = 100
COSTS = [0.0020, 0.0030, 0.0040, 0.0050]
GATE_SMD, GATE_SMD_DRAW = 0.10, 0.15
COVS = ("btc_ret180", "btc_vol42", "btc_ret6", "mkt_ret6")
DELTA_DESCARTE = 0.0010            # 0,10 pp
SPECS = {
    "PRIMARIA": dict(window=W14, calip=True, zone=ZONE, seed=SEED + 301),
    "W28": dict(window=W28, calip=True, zone=ZONE, seed=SEED + 302),
    "AB0": dict(window=W14, calip=False, zone=0, seed=SEED + 401),
    "AB1": dict(window=W14, calip=False, zone=ZONE, seed=SEED + 402),
    "AB2": dict(window=W14, calip=True, zone=0, seed=SEED + 403),
}


# ------------------------------------------------------------------ contexto
def build_ctx():
    ts, o, h, l, c, v = e1.load_all()
    Aa, T = c.shape
    cal = e1.Cal(ts)
    f = e1.cfac(FEE, SLIP)
    R, XB = e1.ratios(o, h, l, c, HZ, "R1")
    raw = A.signals("C", N_PAR, o, h, l, c, v, {})
    elig = e1.elig_mask(Aa, cal)
    sig = A.cooldown(raw) & elig
    ra, rt = np.nonzero(sig)
    order = np.lexsort((ra, rt))
    ra, rt = ra[order], rt[order]
    c0 = c[0]
    ret = np.full(T, np.nan); ret[180:] = c0[180:] / c0[:-180] - 1
    trend = np.full(T, -1); trend[180:] = np.where(ret[180:] > 0.10, 2, np.where(ret[180:] < -0.10, 0, 1))
    r1 = np.zeros(T); r1[1:] = c0[1:] / c0[:-1] - 1
    vol = np.full(T, np.nan); vol[41:] = swv(r1, 42).std(axis=1)
    pct = np.full(T, np.nan)
    W = swv(vol[41:], 720)
    pct[41 + 719:] = (W <= W[:, -1:]).mean(axis=1)
    terc = np.full(T, -1); okv = ~np.isnan(pct)
    terc[okv] = np.where(pct[okv] < 1 / 3, 0, np.where(pct[okv] < 2 / 3, 1, 2))
    key9 = np.where((trend >= 0) & (terc >= 0), trend * 3 + terc, -1)
    ret6 = np.full(T, np.nan); ret6[6:] = c0[6:] / c0[:-6] - 1
    a6 = np.full((Aa, T), np.nan); a6[:, 6:] = c[:, 6:] / c[:, :-6] - 1
    mk6 = np.full(T, np.nan); mk6[6:] = a6[:, 6:].mean(axis=0)
    mk6x = np.full((Aa, T), np.nan); mk6x[:, 6:] = (a6[:, 6:].sum(axis=0)[None, :] - a6[:, 6:]) / (Aa - 1)
    a180 = np.full((Aa, T), np.nan); a180[:, 180:] = c[:, 180:] / c[:, :-180] - 1
    cov = {"btc_ret180": ret, "btc_vol42": vol, "btc_ret6": ret6, "mkt_ret6": mk6}
    cov2 = {"mkt_ret6_ex_own": mk6x, "asset_ret6": a6, "asset_ret180": a180}
    sl = slice(cal.first, cal.last + 1)
    sig_b, sig_m = float(np.std(ret6[sl])), float(np.std(mk6[sl]))
    boots = {}
    for days in BLOCKS:
        _, nb = e1.block_ids(ts, np.array([cal.first]), cal.first, cal.last, days)
        boots[days] = e1.boot_indices(nb, NBOOT, SEED + days)
    return dict(ts=ts, o=o, h=h, l=l, c=c, v=v, Aa=Aa, T=T, cal=cal, f=f, R=R, XB=XB, raw=raw, elig=elig, sig=sig,
                ra=ra, rt=rt, trend=trend, terc=terc, key9=key9, cov=cov, cov2=cov2, boots=boots,
                sigma_btc6=sig_b, sigma_mkt6=sig_m, cal_b=CAL_K * sig_b, cal_m=CAL_K * sig_m,
                gap=np.concatenate([o[:, 1:] / c[:, :-1] - 1, np.full((Aa, 1), np.nan)], axis=1))


# ------------------------------------------------------------------ puerta V0
def repro(ctx):
    ra, rt, R, XB, f = ctx["ra"], ctx["rt"], ctx["R"], ctx["XB"], ctx["f"]
    ref = json.load(open(os.path.join(E1D, "results", "variants.json")))["C-48h-R1"]
    ratio, xb = R[ra, rt], XB[ra, rt]
    m = e1.metrics(f * ratio - 1, np.lexsort((ra, xb)))
    chk = {"n": (m["n"], ref["metrics"]["n"], 0)}
    for k in ("expectancy", "win_rate", "avg_win", "avg_loss", "pf", "sum", "worst", "p5"):
        chk[k] = (m[k], ref["metrics"][k], 1e-9)
    chk["max_loss_streak"] = (m["max_loss_streak"], ref["metrics"]["max_loss_streak"], 0)
    pf = e1.portfolio(ra, rt, xb, ratio, ctx["o"], ctx["c"], f)
    for k in ("ret", "dd", "exposure", "skipped"):
        chk["portfolio_" + k] = (pf[k], ref["portfolio"][k], 1e-9)
    ok = all(abs(a - b) <= tol for a, b, tol in chk.values())
    z = os.path.join(E2D, "results", "draws_primary.npz")
    same2 = None
    if os.path.exists(z):
        zz = np.load(z)
        same2 = bool(np.array_equal(zz["ra"].astype(int), ra) and np.array_equal(zz["rt"].astype(int), rt))
        ok = ok and same2
    return {"ok": bool(ok), "same_signals_as_E2": same2,
            "checks": {k: {"e3": float(a), "e1": float(b), "tol": tol, "diff": float(a - b)} for k, (a, b, tol) in chk.items()}}


# ------------------------------------------------------------------ controles y sorteos
def build_pools(ctx, ra, rt, spec, raw=None):
    ok = ctx["elig"] & ~(ctx["raw"] if raw is None else raw)
    key = ctx["key9"]
    first, last = ctx["cal"].first, ctx["cal"].last
    b6, m6 = ctx["cov"]["btc_ret6"], ctx["cov"]["mkt_ret6"]
    w, zone = spec["window"], spec["zone"]
    pools = []
    for a, t in zip(ra.tolist(), rt.tolist()):
        lo, hi = max(first, t - w), min(last, t + w)
        cond = ok[a, lo:hi + 1] & (key[lo:hi + 1] == key[t])
        idx = np.arange(lo, hi + 1)
        if zone:
            cond &= np.abs(idx - t) > zone
        else:
            cond &= idx != t
        if spec["calip"]:
            cond &= (np.abs(b6[lo:hi + 1] - b6[t]) <= ctx["cal_b"]) & (np.abs(m6[lo:hi + 1] - m6[t]) <= ctx["cal_m"])
        pools.append(idx[cond].tolist())
    return pools


def draw_matches(pools, ra, ndraws, seed, T, Aa):
    n = len(ra)
    ral = ra.tolist()
    rng = np.random.default_rng(seed)
    sel = np.full((ndraws, n), -1, dtype=np.int32)
    ones = b"\x01" * (2 * (e1.COOL - 1) + 1)
    active = [i for i in range(n) if len(pools[i]) >= MINPOOL]
    for d in range(ndraws):
        blk = [bytearray(T) for _ in range(Aa)]
        order = rng.permutation(n).tolist()
        U = rng.random((n, 6)).tolist()
        srow = sel[d]
        for i in order:
            pool = pools[i]
            m = len(pool)
            if m < MINPOOL:
                continue
            b = blk[ral[i]]
            u = U[i]
            got = -1
            for k in range(6):
                tt = pool[int(u[k] * m)]
                if not b[tt]:
                    got = tt
                    break
            if got < 0:
                off = int(u[0] * m)
                for j in range(m):
                    tt = pool[(off + j) % m]
                    if not b[tt]:
                        got = tt
                        break
            if got >= 0:
                lo = max(0, got - (e1.COOL - 1))
                b[lo:got + e1.COOL] = ones[:len(b[lo:got + e1.COOL])]
                srow[i] = got
    return sel


def core(ctx, ra, rt, sel, minfrac=MINFRAC):
    R, f = ctx["R"], ctx["f"]
    matched = sel >= 0
    RR = np.where(matched, R[ra[None, :], np.where(matched, sel, 0)], np.nan)
    frac = matched.mean(0)
    incl = frac >= minfrac
    rbar = np.full(len(ra), np.nan)
    if incl.any():
        rbar[incl] = np.nanmean(RR[:, incl], axis=0)
    ratio = R[ra, rt]
    return dict(RR=RR, frac=frac, incl=incl, rbar=rbar, ratio=ratio, net=f * ratio - 1, m=f * rbar - 1,
                d=(f * ratio - 1) - (f * rbar - 1))


def boot(ctx, x, rt, days_list=BLOCKS, B=None):
    out = {}
    for days in days_list:
        bid, nb = e1.block_ids(ctx["ts"], rt, ctx["cal"].first, ctx["cal"].last, days)
        idx = ctx["boots"][days] if B is None else ctx["boots"][days][:B]
        s, nn = e1.block_sums(bid, nb, x)
        b = e1.boot_stat(idx, s, nn)
        out[days] = {"mean": float(x.mean()), "ci95": [float(np.percentile(b, 2.5)), float(np.percentile(b, 97.5))],
                     "p_gt0": float((1 + (b <= 0).sum()) / (len(b) + 1)), "se": float(b.std()),
                     "blocks_nonempty": int((nn > 0).sum())}
    return out


# ------------------------------------------------------------------ diagnósticos
def smd_table(ctx, ra, rt, sel, incl):
    matched = (sel >= 0)[:, incl]
    s = np.where(sel >= 0, sel, 0)[:, incl]
    res = {}
    items = [(k, ctx["cov"][k][rt[incl]], ctx["cov"][k][s]) for k in COVS]
    items += [(k, ctx["cov2"][k][ra[incl], rt[incl]], ctx["cov2"][k][ra[incl][None, :], s]) for k in ctx["cov2"]]
    for k, xs, xr in items:
        Xs = np.where(matched, xs[None, :], np.nan)
        Xr = np.where(matched, xr, np.nan)
        ms, mr = np.nanmean(Xs, 1), np.nanmean(Xr, 1)
        smd = (ms - mr) / np.sqrt((np.nanvar(Xs, 1) + np.nanvar(Xr, 1)) / 2)
        p5, p95 = float(np.percentile(smd, 5)), float(np.percentile(smd, 95))
        sm = float(smd.mean())
        ok = (abs(sm) <= GATE_SMD and p5 >= -GATE_SMD_DRAW and p95 <= GATE_SMD_DRAW) if k in COVS else None
        res[k] = {"mean_signal": float(ms.mean()), "mean_ref": float(mr.mean()), "smd_mean": sm, "smd_p5_p95": [p5, p95], "gate": k in COVS, "ok": ok}
    return res


def matching_quality(ctx, ra, rt, sel, pools, cr):
    incl = cr["incl"]
    n, nd = len(ra), sel.shape[0]
    matched = sel >= 0
    picks = int(matched.sum())
    pool_sz = np.array([len(p) for p in pools])
    dist_bars = np.where(matched, np.abs(sel - rt[None, :]), np.nan)
    tdist = dist_bars / 6.0
    topshare, distinct = np.full(n, np.nan), np.full(n, np.nan)
    for i in np.flatnonzero(incl):
        col = sel[:, i]; col = col[col >= 0]
        u, cnt = np.unique(col, return_counts=True)
        topshare[i] = cnt.max() / len(col); distinct[i] = len(u)
    allb = sel[matched]
    abase = np.broadcast_to(ra[None, :], sel.shape)[matched].astype(np.int64)
    ub, uc = np.unique(abase * ctx["T"] + allb, return_counts=True)
    ti = tdist[:, incl]
    bid14, _ = e1.block_ids(ctx["ts"], rt[incl], ctx["cal"].first, ctx["cal"].last, 14)
    q = {
        "signals": n, "included": int(incl.sum()), "included_share": float(incl.mean()), "excluded": int((~incl).sum()),
        "excluded_no_pool_ge5": int((pool_sz < MINPOOL).sum()),
        "pool_size_median_included": float(np.median(pool_sz[incl])), "pool_size_p10_included": float(np.percentile(pool_sz[incl], 10)),
        "pool_size_median_all": float(np.median(pool_sz)),
        "mean_abs_time_distance_days": float(np.nanmean(ti)), "median_abs_time_distance_days": float(np.nanmedian(ti)),
        "abs_time_distance_days_p10_p90": [float(np.nanpercentile(ti, 10)), float(np.nanpercentile(ti, 90))],
        "share_controls_within_18_bars": float(np.nanmean((dist_bars[:, incl] <= 18)[matched[:, incl]])),
        "share_controls_overlapping_trade_window_le12": float(np.nanmean((dist_bars[:, incl] <= 12)[matched[:, incl]])),
        "reuse_top_share_median": float(np.nanmedian(topshare)), "reuse_top_share_p90": float(np.nanpercentile(topshare, 90)),
        "share_included_with_top_share_le_0.35": float(np.nanmean(topshare[incl] <= 0.35)),
        "distinct_ref_bars_per_signal_median": float(np.nanmedian(distinct)),
        "distinct_ref_bars_total": int(len(ub)), "ref_picks_total": picks, "bar_reuse_ratio_picks_per_distinct_bar": float(picks / max(len(ub), 1)),
        "most_used_bar_picks_per_draw": float(uc.max() / nd), "bar_usage_p99_picks_per_draw": float(np.percentile(uc, 99) / nd),
        "blocks14_nonempty_included": int(len(np.unique(bid14))),
    }
    q["smd"] = smd_table(ctx, ra, rt, sel, incl)
    g = {"V1_muestra": bool(q["included_share"] >= 0.80 and q["blocks14_nonempty_included"] >= 60),
         "V2_candidatos": bool(q["pool_size_median_included"] >= 8),
         "V3_equilibrio": bool(all(q["smd"][k]["ok"] for k in COVS)),
         "V4_reutilizacion": bool(q["share_included_with_top_share_le_0.35"] >= 0.90)}
    g["valida"] = bool(all(g.values()))
    q["gates"] = g
    return q


def group_stats(cr, g, labels=None):
    incl = cr["incl"]
    out = {}
    for val in np.unique(g[incl]):
        s = incl & (g == val)
        out[str(labels[val] if labels else val)] = {"n": int(s.sum()), "net": float(cr["net"][s].mean()), "ref": float(cr["m"][s].mean()),
                                                      "D": float(cr["d"][s].mean()), "sum_d": float(cr["d"][s].sum())}
    return out


def concentration(cr, ra, rt, ctx):
    incl = cr["incl"]
    d, a, t = cr["d"][incl], ra[incl], rt[incl]
    qid = ctx["cal"].qid[t]
    tot = d.sum()
    out = {"sum_d": float(tot)}
    for nm, g in (("asset", a), ("quarter", qid)):
        sums = {int(k): float(d[g == k].sum()) for k in np.unique(g)}
        best = max(sums, key=sums.get)
        out[nm] = {"best": (A.SYMS[best] if nm == "asset" else best), "best_share": float(sums[best] / tot) if tot > 0 else None,
                   "D_without_best": float(d[g != best].mean()), "n_groups": len(sums), "n_groups_negative": int(sum(v < 0 for v in sums.values()))}
    return out


def episodes(cr, rt, ctx):
    incl = cr["incl"]
    d, t = cr["d"][incl], rt[incl]
    bid, nb = e1.block_ids(ctx["ts"], t, ctx["cal"].first, ctx["cal"].last, 14)
    cnt = np.bincount(bid, minlength=nb); sm = np.bincount(bid, weights=d, minlength=nb)
    out = {}
    for k in (5, 10):
        keep = ~np.isin(bid, np.argsort(-sm)[:k])
        out[f"D_without_{k}_top_contributing_blocks"] = float(d[keep].mean())
    keep = ~np.isin(bid, np.argsort(-cnt)[:10])
    out["D_without_10_busiest_blocks"] = float(d[keep].mean())
    out["share_sum_d_top5_blocks"] = float(np.sort(sm)[::-1][:5].sum() / sm.sum()) if sm.sum() > 0 else None
    out["share_sum_d_top10_blocks"] = float(np.sort(sm)[::-1][:10].sum() / sm.sum()) if sm.sum() > 0 else None
    return out


def risk(ctx, ra, rt, sel, incl):
    o, c, f, R, XB = ctx["o"], ctx["c"], ctx["f"], ctx["R"], ctx["XB"]
    allp = e1.portfolio(ra, rt, XB[ra, rt], R[ra, rt], o, c, f)
    ri, ti = ra[incl], rt[incl]
    incp = e1.portfolio(ri, ti, XB[ri, ti], R[ri, ti], o, c, f)
    mc = e1.metrics(f * R[ri, ti] - 1, np.lexsort((ri, XB[ri, ti])))
    rows, ports = [], []
    for d in range(min(KEEP_PORT, sel.shape[0])):
        m = (sel[d] >= 0) & incl
        ta, tt = ra[m], sel[d][m].astype(np.int64)
        ratio, xb = R[ta, tt], XB[ta, tt]
        rows.append(e1.metrics(f * ratio - 1, np.lexsort((ta, xb))))
        ports.append(e1.portfolio(ta, tt, xb, ratio, o, c, f))
    agg = {}
    for k in ("n", "win_rate", "avg_win", "avg_loss", "expectancy", "sum", "pf", "worst", "p5", "max_loss_streak"):
        vals = np.array([r[k] for r in rows], float)
        agg[k] = {"median": float(np.median(vals)), "p5_p95": [float(np.percentile(vals, 5)), float(np.percentile(vals, 95))]}
    pagg = {}
    for k in ("ret", "dd", "exposure", "skipped"):
        vals = np.array([p[k] for p in ports], float)
        pagg[k] = {"median": float(np.median(vals)), "range": [float(vals.min()), float(vals.max())]}
    return {"portfolio_C_all_signals": allp, "portfolio_C_included": incp, "C_included_metrics": mc,
            "ref_metrics_per_draw": agg, "ref_portfolio_100_draws": pagg,
            "dd_limit_E1": 0.15, "C_dd_meets_limit": bool(allp["dd"] <= 0.15 and incp["dd"] <= 0.15)}


def cost_block(ctx, cr, ra, rt):
    incl = cr["incl"]
    out = {}
    for X in COSTS:
        k, k2 = e1.cost_pair(X)
        fx = e1.cfac(k, k2)
        nc, nr = fx * cr["ratio"][incl] - 1, fx * cr["rbar"][incl] - 1
        d = nc - nr
        entry = {"C": float(nc.mean()), "ref": float(nr.mean()), "D": float(d.mean())}
        if X in (0.0030, 0.0040):
            entry["boot_D"] = boot(ctx, d, rt[incl])
        out[f"{X*100:.2f}%"] = entry
    return out


def sub_boot(ctx, cr, rt, mask):
    s = cr["incl"] & mask
    return boot(ctx, cr["d"][s], rt[s], days_list=(14,))[14]


# ------------------------------------------------------------------ una variante
def run_variant(ctx, name, full=False, ndraws=DRAWS):
    spec = SPECS[name]
    ra, rt = ctx["ra"], ctx["rt"]
    t0 = time.time()
    pools = build_pools(ctx, ra, rt, spec)
    sel = draw_matches(pools, ra, ndraws, spec["seed"], ctx["T"], ctx["Aa"])
    cr = core(ctx, ra, rt, sel)
    incl = cr["incl"]
    ri, ti = ra[incl], rt[incl]
    res = {"name": name, "spec": spec, "draws": ndraws, "n_included": int(incl.sum()),
           "C_expectancy_included": float(cr["net"][incl].mean()), "ref_expectancy": float(cr["m"][incl].mean()),
           "D": float(cr["d"][incl].mean()), "boot_D": boot(ctx, cr["d"][incl], ti), "boot_C": boot(ctx, cr["net"][incl], ti),
           "boot_ref": boot(ctx, cr["m"][incl], ti, days_list=(14,))}
    res["n_eff_from_bootstrap14"] = float(np.var(cr["d"][incl]) / max(res["boot_D"][14]["se"] ** 2, 1e-30))
    res["quality"] = matching_quality(ctx, ra, rt, sel, pools, cr)
    m = (sel >= 0) & incl[None, :]
    RRn = ctx["f"] * cr["RR"] - 1
    Dd = np.nanmean(np.where(m, cr["net"][None, :] - RRn, np.nan), axis=1)
    res["D_per_draw_p2.5_p97.5"] = [float(np.percentile(Dd, 2.5)), float(np.percentile(Dd, 97.5))]
    if not full:
        res["_cr"] = cr
        res["elapsed_s"] = round(time.time() - t0)
        return res
    net, d = cr["net"], cr["d"]
    res["cost_sensitivity"] = cost_block(ctx, cr, ra, rt)
    res["metrics_C_included"] = e1.metrics(net[incl], np.lexsort((ri, ctx["XB"][ri, ti])))
    res["metrics_C_all"] = e1.metrics(net, np.lexsort((ra, ctx["XB"][ra, rt])))
    mid = (ctx["ts"][ctx["cal"].first] + ctx["ts"][ctx["cal"].last]) // 2
    half = (ctx["ts"][rt] >= mid).astype(int)
    res["halves"] = group_stats(cr, half, ["primera", "segunda"])
    for nmh, h_ in (("primera", half == 0), ("segunda", half == 1)):
        res["halves"][nmh]["boot_D14"] = sub_boot(ctx, cr, rt, h_)
    res["regimen_tendencia"] = group_stats(cr, ctx["trend"][rt], {0: "bajista", 1: "lateral", 2: "alcista"})
    for k_, lab in ((0, "bajista"), (1, "lateral"), (2, "alcista")):
        s = incl & (ctx["trend"][rt] == k_)
        if s.sum() > 10:
            res["regimen_tendencia"][lab]["boot_D14"] = sub_boot(ctx, cr, rt, ctx["trend"][rt] == k_)
    res["tercil_volatilidad"] = group_stats(cr, ctx["terc"][rt], {0: "baja", 1: "media", 2: "alta"})
    res["celda_regimen"] = group_stats(cr, ctx["key9"][rt])
    res["por_activo"] = group_stats(cr, ra, A.SYMS)
    res["por_trimestre"] = group_stats(cr, ctx["cal"].qid[rt])
    yrs = ctx["ts"][rt].astype("datetime64[ms]").astype("datetime64[Y]").astype(int) + 1970
    res["por_anio"] = group_stats(cr, yrs)
    for y in np.unique(yrs[incl]):
        if (incl & (yrs == y)).sum() >= 30:
            res["por_anio"][str(y)]["boot_D14"] = sub_boot(ctx, cr, rt, yrs == y)
    res["concentracion"] = concentration(cr, ra, rt, ctx)
    res["episodios"] = episodes(cr, rt, ctx)
    res["riesgo"] = risk(ctx, ra, rt, sel, incl)
    # descriptivo (añadido tras ver la primera ejecución; no interviene en ningún criterio)
    res["incluidas_vs_excluidas"] = {
        nm: {"n": int(s.sum()), "C_net": float(net[s].mean()), "btc_ret6": float(ctx["cov"]["btc_ret6"][rt[s]].mean()),
             "mkt_ret6": float(ctx["cov"]["mkt_ret6"][rt[s]].mean()), "asset_ret6": float(ctx["cov2"]["asset_ret6"][ra[s], rt[s]].mean()),
             "share_by_trend": {lab: float((ctx["trend"][rt[s]] == k_).mean()) for k_, lab in ((0, "bajista"), (1, "lateral"), (2, "alcista"))},
             "share_by_vol_tercil": {lab: float((ctx["terc"][rt[s]] == k_).mean()) for k_, lab in ((0, "baja"), (1, "media"), (2, "alta"))}}
        for nm, s in (("incluidas", incl), ("excluidas", ~incl))}
    cnt = np.bincount(rt, minlength=ctx["T"]); act = cnt[cnt > 0]
    res["simultaneidad_C"] = {"bars_with_signals": int(len(act)), "share_signals_in_bars_ge3": float(act[act >= 3].sum() / act.sum()),
                              "share_signals_in_bars_ge5": float(act[act >= 5].sum() / act.sum()), "max_in_a_bar": int(act.max())}
    z = os.path.join(OUT, "draws_primary.npz")
    np.savez_compressed(z, sel=sel, ra=ra.astype(np.int16), rt=rt.astype(np.int32))
    with open(os.path.join(OUT, "draws_summary.csv"), "w") as fh:
        fh.write("draw,n_matched_included,mean_ref_net_matched,D_matched\n")
        mean_ref = np.nanmean(np.where(m, RRn, np.nan), axis=1)
        for dd in range(ndraws):
            fh.write(f"{dd},{int(m[dd].sum())},{mean_ref[dd]:.8f},{Dd[dd]:.8f}\n")
    res["sel_sha256"] = hashlib.sha256(sel.tobytes()).hexdigest()
    res["_cr"] = cr
    res["elapsed_s"] = round(time.time() - t0)
    return res


# ------------------------------------------------------------------ utilidades de salida
def jsonable(x):
    if isinstance(x, dict):
        return {str(k): jsonable(v) for k, v in x.items() if not str(k).startswith("_")}
    if isinstance(x, (list, tuple)):
        return [jsonable(v) for v in x]
    if isinstance(x, (np.floating, float)):
        return None if x != x else (float(x) if abs(x) != float("inf") else "inf")
    if isinstance(x, (np.integer,)):
        return int(x)
    if isinstance(x, (np.bool_,)):
        return bool(x)
    return x


def sha(path):
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def meta_base():
    files = {"PREREGISTRO.md": os.path.join(HERE, "PREREGISTRO.md"), "e3.py": os.path.join(HERE, "e3.py"),
             "e1.py": os.path.join(E1D, "e1.py"), "audit_4h.py": os.path.join(SA, "audit_4h.py"),
             "e1_variants.json": os.path.join(E1D, "results", "variants.json")}
    data = {s + ".npy": sha(os.path.join(SA, "data4h", s + ".npy")) for s in A.SYMS}
    return {"seed": SEED, "numpy": np.__version__, "python": sys.version.split()[0],
            "sha256_files": {k: sha(p) for k, p in files.items()}, "sha256_data4h": data}


def save(name, obj):
    os.makedirs(OUT, exist_ok=True)
    json.dump(jsonable(obj), open(os.path.join(OUT, name), "w", encoding="utf-8"), indent=1, ensure_ascii=False)


def load(name):
    return json.load(open(os.path.join(OUT, name), encoding="utf-8"))


# ------------------------------------------------------------------ run
def run():
    t0 = time.time()
    os.makedirs(OUT, exist_ok=True)
    meta = meta_base(); meta["started"] = time.strftime("%Y-%m-%d %H:%M:%S")
    ctx = build_ctx()
    meta.update({"assets": ctx["Aa"], "bars": ctx["T"], "signals_C": int(len(ctx["ra"]))})
    save("config.json", {"seed": SEED, "fee": FEE, "slip": SLIP, "n_par": N_PAR, "horizon_bars": HZ, "cooldown": e1.COOL,
                         "draws": DRAWS, "n_boot": NBOOT, "blocks_days": list(BLOCKS), "windows_bars": {"W14": W14, "W28": W28},
                         "zone_bars": ZONE, "caliper_k": CAL_K, "sigma_btc_ret6": ctx["sigma_btc6"], "sigma_mkt_ret6": ctx["sigma_mkt6"],
                         "caliper_btc_ret6": ctx["cal_b"], "caliper_mkt_ret6": ctx["cal_m"], "min_pool": MINPOOL, "min_frac": MINFRAC,
                         "gate_smd": GATE_SMD, "gate_smd_draw": GATE_SMD_DRAW, "delta_descarte": DELTA_DESCARTE, "specs": SPECS})
    print("Puerta V0: reproducción de E1...", flush=True)
    rep = repro(ctx)
    save("repro_e1.json", rep)
    if not rep["ok"]:
        meta["V0"] = "FALLIDA"; save("run_meta.json", meta)
        print("V0 FALLIDA: se detiene el experimento."); sys.exit(2)
    print(f"V0 OK ({time.time()-t0:.0f}s). Primaria...", flush=True)
    prim = run_variant(ctx, "PRIMARIA", full=True)
    cr = prim.pop("_cr")
    q = prim["quality"]
    print(f"Primaria ({time.time()-t0:.0f}s): n={prim['n_included']} D={prim['D']*100:+.3f}pp valida={q['gates']['valida']} gates={q['gates']}", flush=True)
    sec = {}
    for nm in ("W28", "AB0", "AB1", "AB2"):
        r = run_variant(ctx, nm)
        cr_ = r.pop("_cr")
        sec[nm] = r
        print(f"{nm} ({time.time()-t0:.0f}s): n={r['n_included']} D={r['D']*100:+.3f}pp valida={r['quality']['gates']['valida']}", flush=True)
        if nm == "W28":
            cr28 = cr_
    # F2 (Holm): W28 si es válida, mitades, regímenes, años >= 30
    fam = {}
    if sec["W28"]["quality"]["gates"]["valida"]:
        fam["W28"] = sec["W28"]["boot_D"][14]["p_gt0"]
    for k, v in prim["halves"].items():
        fam["mitad_" + k] = v["boot_D14"]["p_gt0"]
    for k, v in prim["regimen_tendencia"].items():
        if "boot_D14" in v:
            fam["regimen_" + k] = v["boot_D14"]["p_gt0"]
    for k, v in prim["por_anio"].items():
        if "boot_D14" in v:
            fam["anio_" + k] = v["boot_D14"]["p_gt0"]
    names = list(fam)
    adj = e1.holm([fam[k] for k in names])
    sec["F2_holm"] = {k: {"p_unilateral_14d": fam[k], "p_holm": a} for k, a in zip(names, adj)}
    sec["F2_holm"]["_nota"] = "Familia secundaria: no modifica el veredicto."
    meta["finished"] = time.strftime("%Y-%m-%d %H:%M:%S"); meta["elapsed_s"] = round(time.time() - t0)
    save("run_meta.json", meta); save("primary.json", prim); save("secondary.json", sec)
    print("run completo", flush=True)


# ------------------------------------------------------------------ placebo
_G = {}


def _pl_init():
    _G["ctx"] = build_ctx()


def _pl_one(args):
    p, k = args
    ctx = _G["ctx"]
    first, last, T, Aa = ctx["cal"].first, ctx["cal"].last, ctx["T"], ctx["Aa"]
    sub_sig = ctx["sig"][:, first:last + 1]
    sub_raw = (ctx["raw"] & ctx["elig"])[:, first:last + 1]
    sig = np.zeros((Aa, T), bool); raw = np.zeros((Aa, T), bool)
    sig[:, first:last + 1] = np.roll(sub_sig, k, axis=1)
    raw[:, first:last + 1] = np.roll(sub_raw, k, axis=1)
    sig = A.cooldown(sig)
    ra, rt = np.nonzero(sig)
    order = np.lexsort((ra, rt)); ra, rt = ra[order], rt[order]
    pools = build_pools(ctx, ra, rt, SPECS["PRIMARIA"], raw=raw)
    sel = draw_matches(pools, ra, PL_DRAWS, SEED + 5000 + p, T, Aa)
    cr = core(ctx, ra, rt, sel)
    inc = cr["incl"]
    b = boot(ctx, cr["d"][inc], rt[inc], days_list=(14,), B=PL_B)[14]
    return {"shift": int(k), "n": int(inc.sum()), "n_signals": int(len(ra)), "D": b["mean"], "ci": b["ci95"], "p": b["p_gt0"]}


def placebo():
    from concurrent.futures import ProcessPoolExecutor
    t0 = time.time()
    ctx = build_ctx()
    L = ctx["cal"].last - ctx["cal"].first + 1
    rng = np.random.default_rng(SEED + 7)
    shifts = rng.choice(np.arange(360, L - 360), size=PL_N, replace=False).tolist()
    del ctx
    nw = max(1, min(6, (os.cpu_count() or 2) - 1))
    with ProcessPoolExecutor(max_workers=nw, initializer=_pl_init) as ex:
        rows = list(ex.map(_pl_one, list(enumerate(shifts)), chunksize=4))
    D = np.array([r["D"] for r in rows])
    rej = float(np.mean([(r["p"] < 0.05) and (r["D"] > 0) for r in rows]))
    cov_ = float(np.mean([r["ci"][0] <= 0 <= r["ci"][1] for r in rows]))
    out = {"placebos": PL_N, "draws_each": PL_DRAWS, "boot_each": PL_B, "rate_p_lt_0.05_and_D_gt0": rej, "ci95_covers_0": cov_,
           "mean_D": float(D.mean()), "sd_D": float(D.std()), "n_included_median": float(np.median([r["n"] for r in rows])),
           "mc_se_of_rate_at_5pct": float(np.sqrt(0.05 * 0.95 / PL_N)), "workers": nw, "elapsed_s": round(time.time() - t0),
           "calibrated": bool(rej <= 0.09 and cov_ >= 0.92 and abs(D.mean()) <= 0.001), "rows": rows}
    save("placebo.json", out)
    print({k: v for k, v in out.items() if k != "rows"}, flush=True)


# ------------------------------------------------------------------ decisión (sección 10)
def decide():
    P, S, PL, rep = load("primary.json"), load("secondary.json"), load("placebo.json"), load("repro_e1.json")
    q, g = P["quality"], P["quality"]["gates"]
    b = P["boot_D"]
    lo = {k: b[k]["ci95"][0] for k in b}
    hi = {k: b[k]["ci95"][1] for k in b}
    c40 = P["cost_sensitivity"]["0.40%"]
    conc, epi = P["concentracion"], P["episodios"]
    yrs = [v for v in P["por_anio"].values() if v["n"] >= 30]
    cand = {
        "a_D_gt0_IC14_inferior_gt0": P["D"] > 0 and lo["14"] > 0,
        "b_IC7_y_IC28_inferior_gt0": lo["7"] > 0 and lo["28"] > 0,
        "c_costo_0.40_D_gt0_IC_gt0_C_gt0": c40["D"] > 0 and c40["boot_D"]["14"]["ci95"][0] > 0 and c40["C"] > 0,
        "d_mitades_y_anios": all(v["D"] > 0 for v in P["halves"].values()) and len(yrs) > 0 and sum(v["D"] > 0 for v in yrs) >= 0.6 * len(yrs),
        "e_concentracion": (conc["asset"]["best_share"] is not None and conc["asset"]["best_share"] <= 0.30
                            and conc["quarter"]["best_share"] is not None and conc["quarter"]["best_share"] <= 0.30
                            and conc["asset"]["D_without_best"] > 0 and conc["quarter"]["D_without_best"] > 0),
        "f_sin_top5_bloques": epi["D_without_5_top_contributing_blocks"] > 0,
    }
    upper_ok = all(hi[k] < DELTA_DESCARTE for k in ("7", "14", "28"))
    reasons = []
    if not rep["ok"]:
        verdict = "SIN RESULTADO (V0 fallida)"
    elif not g["valida"]:
        verdict = "INCONCLUSO"; reasons.append("comparación primaria no válida: " + ", ".join(k for k, v in g.items() if not v and k != "valida"))
    elif not PL["calibrated"]:
        verdict = "INCONCLUSO"; reasons.append("placebo no calibrado")
    elif upper_ok:
        verdict = "DESCARTAR LA HIPÓTESIS DE VENTAJA EN ESTE EXPERIMENTO"
    elif all(cand.values()):
        verdict = "CANDIDATA NO CONFIRMADA"
    else:
        verdict = "INCONCLUSO"; reasons.append("no se cumplen todos los criterios de candidata: " + ", ".join(k for k, v in cand.items() if not v))
    ddC = P["riesgo"]["portfolio_C_all_signals"]["dd"]
    dec = {"verdict": verdict, "reasons": reasons, "V0": rep["ok"], "gates": g, "placebo_calibrado": PL["calibrated"],
           "criterios_candidata": cand, "descartar_upper_lt_0.10pp_en_7_14_28": upper_ok,
           "D": P["D"], "D_ci7": b["7"]["ci95"], "D_ci14": b["14"]["ci95"], "D_ci28": b["28"]["ci95"], "p_unilateral_14d": b["14"]["p_gt0"],
           "W28_valida": S["W28"]["quality"]["gates"]["valida"], "W28_D": S["W28"]["D"], "W28_ci14": S["W28"]["boot_D"]["14"]["ci95"],
           "riesgo_veredicto_aparte": {"dd_cartera_C_todas": ddC, "dd_limite": 0.15, "cumple": bool(ddC <= 0.15),
                                       "racha_max_perdidas_C": P["metrics_C_all"]["max_loss_streak"],
                                       "nota": "C no se implementa mientras no cumpla la evaluación de riesgo, sea cual sea el veredicto de ventaja."}}
    save("decision.json", dec)
    write_tables(P, S, PL, dec)
    print(json.dumps(jsonable(dec), indent=1, ensure_ascii=False))


def write_tables(P, S, PL, dec):
    pp = lambda x: f"{x*100:+.3f} pp"
    L = ["# Tablas E3 (generado por `e3.py decide`)\n", f"**Veredicto:** {dec['verdict']}  {dec['reasons']}\n",
         "## Primaria (±14 d, calipers 0,25 DE, zona > 18 velas)\n",
         f"n incluidas {P['n_included']}/{P['quality']['signals']}; C {P['C_expectancy_included']*100:+.3f} %; ref {P['ref_expectancy']*100:+.3f} %; D {pp(P['D'])}; n_eff {P['n_eff_from_bootstrap14']:.0f}\n",
         "| bloque | IC95 D | p unilateral |", "|---|---|---|"]
    for k in ("7", "14", "28"):
        b = P["boot_D"][k]
        L.append(f"| {k} d | [{b['ci95'][0]*100:+.3f}; {b['ci95'][1]*100:+.3f}] pp | {b['p_gt0']:.4f} |")
    L += ["\n## Equilibrio (SMD medio; p5–p95 por sorteo)\n", "| covariable | señal | control | SMD | p5..p95 | puerta |", "|---|---|---|---|---|---|"]
    for k, v in P["quality"]["smd"].items():
        L.append(f"| {k} | {v['mean_signal']:+.4f} | {v['mean_ref']:+.4f} | {v['smd_mean']:+.3f} | {v['smd_p5_p95'][0]:+.3f}..{v['smd_p5_p95'][1]:+.3f} | {v['ok']} |")
    L += ["\n## Puertas\n", "```", json.dumps(jsonable(P["quality"]["gates"]), indent=1), "```", "\n## Variantes\n",
          "| variante | n | C | ref | D | IC95 14 d | válida | controles ≤18 velas |", "|---|---|---|---|---|---|---|---|"]
    for nm, r in [("PRIMARIA", P)] + [(k, S[k]) for k in ("W28", "AB0", "AB1", "AB2")]:
        b = r["boot_D"]["14"]
        L.append(f"| {nm} | {r['n_included']} | {r['C_expectancy_included']*100:+.3f} % | {r['ref_expectancy']*100:+.3f} % | {pp(r['D'])} | "
                 f"[{b['ci95'][0]*100:+.3f}; {b['ci95'][1]*100:+.3f}] | {r['quality']['gates']['valida']} | {r['quality']['share_controls_within_18_bars']*100:.1f} % |")
    L += ["\n## Subgrupos (primaria)\n", "| grupo | n | D | IC95 14 d |", "|---|---|---|---|"]
    for sec_, dct in (("mitad", P["halves"]), ("régimen", P["regimen_tendencia"]), ("volatilidad", P["tercil_volatilidad"]), ("año", P["por_anio"])):
        for k, v in dct.items():
            bb = v.get("boot_D14")
            ci = f"[{bb['ci95'][0]*100:+.2f}; {bb['ci95'][1]*100:+.2f}]" if bb else ""
            L.append(f"| {sec_} {k} | {v['n']} | {pp(v['D'])} | {ci} |")
    L += ["\n## Holm (F2, secundaria)\n", "| prueba | p | p Holm |", "|---|---|---|"]
    for k, v in S["F2_holm"].items():
        if isinstance(v, dict):
            L.append(f"| {k} | {v['p_unilateral_14d']:.4f} | {v['p_holm']:.4f} |")
    L += ["\n## Placebo\n", f"rechazo {PL['rate_p_lt_0.05_and_D_gt0']*100:.1f} %, cobertura {PL['ci95_covers_0']*100:.1f} %, media D {PL['mean_D']*100:+.3f} pp, sd {PL['sd_D']*100:.3f} pp, calibrado: {PL['calibrated']}\n",
          "\n## Riesgo (aparte)\n", "```", json.dumps(jsonable(P["riesgo"]), indent=1), "```"]
    open(os.path.join(OUT, "tables.md"), "w", encoding="utf-8").write("\n".join(L))


# ------------------------------------------------------------------ reproducibilidad
def repro_cmd():
    ctx = build_ctx()
    rep = repro(ctx)
    r = run_variant(ctx, "PRIMARIA", full=False)
    r.pop("_cr")
    saved = load("primary.json")
    ok_sel = None
    z = os.path.join(OUT, "draws_primary.npz")
    pools = build_pools(ctx, ctx["ra"], ctx["rt"], SPECS["PRIMARIA"])
    sel = draw_matches(pools, ctx["ra"], DRAWS, SPECS["PRIMARIA"]["seed"], ctx["T"], ctx["Aa"])
    ok_sel = bool(np.array_equal(sel, np.load(z)["sel"]))
    same = {"V0": rep["ok"], "sorteos_identicos": ok_sel, "D_igual": r["D"] == saved["D"] or abs(r["D"] - saved["D"]) < 1e-15,
            "n_igual": r["n_included"] == saved["n_included"],
            "IC14_igual": r["boot_D"][14]["ci95"] == saved["boot_D"]["14"]["ci95"],
            "gates_iguales": jsonable(r["quality"]["gates"]) == saved["quality"]["gates"]}
    same["todo"] = all(same.values())
    save("repro_check.json", same)
    print(json.dumps(same, indent=1))
    sys.exit(0 if same["todo"] else 1)


# ------------------------------------------------------------------ autopruebas
def selftest():
    ctx = build_ctx()
    ok = True

    def chk(name, cond, info=""):
        nonlocal ok
        print(("OK   " if cond else "FALLA"), name, info, flush=True)
        ok &= bool(cond)

    for x in (0.0030, 0.0040):
        k, k2 = e1.cost_pair(x)
        chk(f"costos: cost_pair exacto {x*100:.2f}%", abs(1 - e1.cfac(k, k2) - x) < 1e-12)
    pe, ps = 100.0, 103.0
    buy = pe * (1 + SLIP); sell = ps * (1 - SLIP)
    manual = (1 / buy) * (1 - FEE) * sell * (1 - FEE) - 1
    chk("costos: fórmula frente a compra/venta explícita", abs(e1.cfac(FEE, SLIP) * ps / pe - 1 - manual) < 1e-12)
    chk("ida y vuelta base ≈ 0,2998 %", abs((1 - ctx["f"]) - 0.0029985) < 1e-5, f"{(1-ctx['f'])*100:.4f}%")
    # causalidad
    o, h, l, c, v = ctx["o"], ctx["h"], ctx["l"], ctx["c"], ctx["v"]
    t0 = 5000
    rng = np.random.default_rng(1)
    c2, o2, h2, l2, v2 = c.copy(), o.copy(), h.copy(), l.copy(), v.copy()
    mult = 1 + rng.normal(0, 0.1, size=c[:, t0 + 1:].shape)
    for arr in (c2, o2, h2, l2):
        arr[:, t0 + 1:] = arr[:, t0 + 1:] * mult
    s1 = A.signals("C", N_PAR, o, h, l, c, v, {}); s2 = A.signals("C", N_PAR, o2, h2, l2, c2, v2, {})
    chk("señales causales (alterar el futuro no cambia el pasado)", np.array_equal(s1[:, :t0 + 1], s2[:, :t0 + 1]))
    orig = e1.load_all
    e1.load_all = lambda: (ctx["ts"], o2, h2, l2, c2, v2)
    try:
        ctx2 = build_ctx()
    finally:
        e1.load_all = orig
    same = np.array_equal(ctx["key9"][:t0 + 1], ctx2["key9"][:t0 + 1]) and all(
        np.allclose(ctx["cov"][k][:t0 + 1], ctx2["cov"][k][:t0 + 1], equal_nan=True) for k in ctx["cov"]) and all(
        np.allclose(ctx["cov2"][k][:, :t0 + 1], ctx2["cov2"][k][:, :t0 + 1], equal_nan=True) for k in ctx["cov2"])
    chk("régimen y covariables causales (clave, btc_ret6, mkt_ret6 hasta t0 sin cambio)", same)
    chk("clave de régimen definida en todas las barras elegibles", (ctx["key9"][ctx["cal"].first:ctx["cal"].last + 1] >= 0).all())
    # covariables: definición explícita
    t = 7000
    chk("btc_ret6 = c[t]/c[t-6]-1", abs(ctx["cov"]["btc_ret6"][t] - (c[0, t] / c[0, t - 6] - 1)) < 1e-15)
    chk("mkt_ret6 = media de 20 activos", abs(ctx["cov"]["mkt_ret6"][t] - np.mean(c[:, t] / c[:, t - 6] - 1)) < 1e-15)
    chk("mkt_ret6_ex_own excluye al activo", abs(ctx["cov2"]["mkt_ret6_ex_own"][3, t] - np.mean(np.delete(c[:, t] / c[:, t - 6] - 1, 3))) < 1e-15)
    # reproducción de E1 / E2
    rep = repro(ctx)
    chk(f"reproducción de E1 y señales de E2 (n={len(ctx['ra'])})", rep["ok"])
    # parejas válidas (primaria y W28)
    ra, rt = ctx["ra"], ctx["rt"]
    for nm in ("PRIMARIA", "W28"):
        spec = SPECS[nm]
        pools = build_pools(ctx, ra, rt, spec)
        sel = draw_matches(pools, ra, 40, SEED + 3, ctx["T"], ctx["Aa"])
        bad = dict(win=0, reg=0, raw=0, elig=0, cool=0, dup=0, zone=0, cal_b=0, cal_m=0)
        key, b6, m6 = ctx["key9"], ctx["cov"]["btc_ret6"], ctx["cov"]["mkt_ret6"]
        for d in range(sel.shape[0]):
            seen = {}
            for i in np.flatnonzero(sel[d] >= 0):
                tt, a, t = int(sel[d, i]), int(ra[i]), int(rt[i])
                bad["win"] += abs(tt - t) > spec["window"]
                bad["zone"] += abs(tt - t) <= spec["zone"]
                bad["reg"] += key[tt] != key[t]
                bad["raw"] += bool(ctx["raw"][a, tt]) or tt == t
                bad["elig"] += not (ctx["cal"].first <= tt <= ctx["cal"].last)
                bad["cal_b"] += abs(b6[tt] - b6[t]) > ctx["cal_b"] + 1e-15
                bad["cal_m"] += abs(m6[tt] - m6[t]) > ctx["cal_m"] + 1e-15
                seen.setdefault(a, []).append(tt)
            for a, lst in seen.items():
                lst.sort()
                bad["dup"] += len(lst) != len(set(lst))
                bad["cool"] += int(any(y - x < e1.COOL for x, y in zip(lst, lst[1:])))
        bad = {k: int(v) for k, v in bad.items()}
        chk(f"parejas válidas {nm} (ventana, zona, régimen, calipers, sin señal bruta, elegible, cooldown, sin duplicados) {bad}", sum(bad.values()) == 0)
    chk("sorteos distintos", len({sel[d].tobytes() for d in range(sel.shape[0])}) > 30)
    # efecto sintético +1 pp
    pools = build_pools(ctx, ra, rt, SPECS["PRIMARIA"])
    sel = draw_matches(pools, ra, 60, SEED + 3, ctx["T"], ctx["Aa"])
    cr0 = core(ctx, ra, rt, sel)
    R2 = ctx["R"].copy()
    R2[ra, rt] = R2[ra, rt] + 0.01 / ctx["f"]
    ctxi = dict(ctx); ctxi["R"] = R2
    cr1 = core(ctxi, ra, rt, sel)
    dd = cr1["d"][cr1["incl"]].mean() - cr0["d"][cr0["incl"]].mean()
    chk(f"efecto sintético +1 pp recuperado (delta={dd*100:.4f} pp)", abs(dd - 0.01) < 1e-9)
    # prueba sintética de confusión: efecto que depende solo de mkt_ret6 (no de la señal)
    beta = 0.5
    R3 = ctx["R"] + beta * ctx["cov"]["mkt_ret6"][None, :] / ctx["f"]
    ctxc = dict(ctx); ctxc["R"] = R3
    shifts = {}
    for nm in ("AB0", "PRIMARIA"):
        pl = build_pools(ctx, ra, rt, SPECS[nm])
        sl = draw_matches(pl, ra, 60, SEED + 9, ctx["T"], ctx["Aa"])
        c0_, c1_ = core(ctx, ra, rt, sl), core(ctxc, ra, rt, sl)
        shifts[nm] = float(c1_["d"][c1_["incl"]].mean() - c0_["d"][c0_["incl"]].mean())
    chk(f"confusión sintética: referencia solo-régimen se sesga {shifts['AB0']*100:+.3f} pp; la primaria {shifts['PRIMARIA']*100:+.3f} pp",
        shifts["AB0"] > 0.003 and abs(shifts["PRIMARIA"]) < 0.25 * abs(shifts["AB0"]))
    print("SELFTEST", "OK" if ok else "FALLÓ")
    sys.exit(0 if ok else 1)


# ------------------------------------------------------------------ auditoría de E2 (solo lectura)
def audit():
    ctx = build_ctx()
    ts, T, c = ctx["ts"], ctx["T"], ctx["c"]
    out = {}
    out["datos"] = {"bars": T, "assets": ctx["Aa"], "first": str(np.datetime64(int(ts[0]), "ms")), "last": str(np.datetime64(int(ts[-1]), "ms")),
                    "eligible_first": str(np.datetime64(int(ts[ctx["cal"].first]), "ms")), "eligible_last": str(np.datetime64(int(ts[ctx["cal"].last]), "ms")),
                    "ts_gaps_unique_ms": [int(x) for x in np.unique(np.diff(ts))],
                    "nan_total": int(sum(np.isnan(x).sum() for x in (ctx["o"], ctx["h"], ctx["l"], c, ctx["v"]))),
                    "zero_volume": int((ctx["v"] == 0).sum()),
                    "ohlc_inconsistent": int((ctx["h"] < np.maximum(ctx["o"], c) - 1e-12).sum() + (ctx["l"] > np.minimum(ctx["o"], c) + 1e-12).sum())}
    out["repro_e1"] = repro(ctx)
    P2 = json.load(open(os.path.join(E2D, "results", "primary.json")))
    z = np.load(os.path.join(E2D, "results", "draws_primary.npz"))
    sel, ra, rt = z["sel"], z["ra"].astype(int), z["rt"].astype(int)
    cr = core(ctx, ra, rt, sel)
    inc = cr["incl"]
    out["recalculo_D_E2"] = {"n_incl": int(inc.sum()), "n_incl_E2": P2["n_included"], "D": float(cr["d"][inc].mean()), "D_E2": P2["D"],
                             "C": float(cr["net"][inc].mean()), "ref": float(cr["m"][inc].mean()), "ref_E2": P2["ref_expectancy"]}
    bz = boot(ctx, cr["d"][inc], rt[inc])
    out["recalculo_IC_E2"] = {str(k): {"ci95": v["ci95"], "ci95_E2": P2["boot_D"][str(k)]["ci95"], "p": v["p_gt0"], "p_E2": P2["boot_D"][str(k)]["p_gt0"]} for k, v in bz.items()}
    sm = smd_table(ctx, ra, rt, sel, inc)
    out["recalculo_SMD_E2"] = {k: {"smd": v["smd_mean"], "smd_E2": P2["quality"]["smd"].get(k, {}).get("smd_mean")} for k, v in sm.items()}
    # contaminación temporal de las referencias de E2
    m = sel >= 0
    dist = np.abs(sel - rt[None, :])
    RRn = np.where(m, ctx["f"] * ctx["R"][ra[None, :], np.where(m, sel, 0)] - 1, np.nan)
    pre = m & (sel < rt[None, :]) & (sel + HZ >= rt[None, :])
    cont = {"share_picks_le_18": float((dist[m] <= 18).mean()), "share_picks_le_12": float((dist[m] <= 12).mean()),
            "share_picks_window_contains_signal_bar": float(pre.sum() / m.sum())}
    for nm, mask in (("le_18", m & (dist <= 18)), ("gt_18", m & (dist > 18)), ("window_contains_signal_bar", pre)):
        cont["mean_ref_net_" + nm] = float(np.nanmean(np.where(mask, RRn, np.nan)))
    far = m & (dist > 18)
    okf = far.sum(0) >= 1
    mf = np.nanmean(np.where(far, RRn, np.nan)[:, okf], axis=0)
    cont["n_signals_with_far_controls"] = int(okf.sum())
    cont["D_using_only_controls_gt18_diagnostic"] = float((cr["net"][okf] - mf).mean())
    cont["D_same_signals_all_controls"] = float((cr["net"][okf] - np.nanmean(RRn, 0)[okf]).mean())
    out["contaminacion_temporal_E2"] = cont
    # simultaneidad de controles (portfolio de referencia)
    sh = []
    for d in range(0, sel.shape[0], 50):
        cnt = np.bincount(sel[d][sel[d] >= 0], minlength=T); act = cnt[cnt > 0]
        sh.append(float(act[act >= 3].sum() / act.sum()))
    cc = np.bincount(rt, minlength=T); a_ = cc[cc > 0]
    out["simultaneidad"] = {"C_share_in_bars_ge3": float(a_[a_ >= 3].sum() / a_.sum()), "ref_share_in_bars_ge3_mean_of_draws": float(np.mean(sh))}
    # costos
    cs = {}
    for X in COSTS:
        k, k2 = e1.cost_pair(X); fx = e1.cfac(k, k2)
        cs[f"{X*100:.2f}%"] = float((fx * (cr["ratio"][inc] - cr["rbar"][inc])).mean())
    out["D_por_costo_recalculado"] = cs
    # mitades
    mid = (ts[ctx["cal"].first] + ts[ctx["cal"].last]) // 2
    h2_ = ts[rt] >= mid
    out["mitades_recalculadas"] = {"primera": float(cr["d"][inc & ~h2_].mean()), "segunda": float(cr["d"][inc & h2_].mean())}
    # placebo E2: error de Monte Carlo
    PL2 = json.load(open(os.path.join(E2D, "results", "placebo.json")))
    out["placebo_E2"] = {"rate": PL2["rate_p_lt_0.05_and_D_gt0"], "coverage": PL2["ci95_covers_0"], "mean_D": PL2["mean_D"], "sd_D": PL2["sd_D"],
                         "mc_se_rate_5pct": float(np.sqrt(0.05 * 0.95 / PL2["placebos"])),
                         "nota": "desplazamientos del mismo calendario: no independientes; rompen la asociación señal-timing"}
    # E2: posibles covariables de equilibrio y fuga
    out["mkt_ret6_ex_own_SMD_E2"] = sm["mkt_ret6_ex_own"]["smd_mean"]
    save("audit_e2.json", out)
    print(json.dumps(jsonable(out), indent=1, ensure_ascii=False))


if __name__ == "__main__":
    {"audit": audit, "selftest": selftest, "run": run, "placebo": placebo, "decide": decide, "repro": repro_cmd}[sys.argv[1]]()
