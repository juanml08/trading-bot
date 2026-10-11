"""E2: señal C (48 h) frente a referencias que controlan el mercado. Ver PREREGISTRO.md (cerrado antes de ejecutar).

Uso (desde research/e2-market-control):
  python -I e2.py selftest   # validaciones (incluye reproducción de E1 sin la referencia MC)
  python -I e2.py run        # paso 0 (reproducción de E1, puerta) + análisis primario y secundario -> results/
  python -I e2.py placebo    # placebo del procedimiento completo
  python -I e2.py decide     # aplica los criterios de la sección 11 -> results/decision.json, tables.md
Solo lectura sobre ../signal-audit y ../e1-multiday. No usa red ni BD.
"""
import sys, os, json, hashlib, time
sys.dont_write_bytecode = True
import numpy as np
from numpy.lib.stride_tricks import sliding_window_view as swv

HERE = os.path.dirname(os.path.abspath(__file__))
E1D = os.path.normpath(os.path.join(HERE, "..", "e1-multiday"))
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
WINDOWS = (84, 168, 336)          # 14 d, 28 d, 56 d en velas de 4h
MINPOOL, MINFRAC = 5, 0.5
PL_N, PL_DRAWS, PL_B = 200, 100, 2000
KEEP_PORT = 100
COSTS = [0.0020, 0.0030, 0.0040, 0.0050]
COVS = {"btc_ret180": 0.10, "btc_vol42": 0.10, "btc_ret6": 0.20, "mkt_ret6": 0.20}


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
    vol = np.full(T, np.nan); vol[41:] = swv(r1, 42).std(axis=1)[:]  # ventana [t-41..t]
    pct = np.full(T, np.nan)
    W = swv(vol[41:], 720)                      # ventanas de vol terminadas en t >= 41+719
    pct[41 + 719:] = (W <= W[:, -1:]).mean(axis=1)
    terc = np.full(T, -1); okv = ~np.isnan(pct)
    terc[okv] = np.where(pct[okv] < 1 / 3, 0, np.where(pct[okv] < 2 / 3, 1, 2))
    key9 = np.where((trend >= 0) & (terc >= 0), trend * 3 + terc, -1)
    ret6 = np.full(T, np.nan); ret6[6:] = c0[6:] / c0[:-6] - 1
    mk6 = np.full(T, np.nan); mk6[6:] = (c[:, 6:] / c[:, :-6] - 1).mean(axis=0)
    cov = {"btc_ret180": ret, "btc_vol42": vol, "btc_ret6": ret6, "mkt_ret6": mk6}
    a6 = np.full((Aa, T), np.nan); a6[:, 6:] = c[:, 6:] / c[:, :-6] - 1
    a180 = np.full((Aa, T), np.nan); a180[:, 180:] = c[:, 180:] / c[:, :-180] - 1
    cov2 = {"asset_ret6": a6, "asset_ret180": a180}
    boots = {}
    for days in BLOCKS:
        _, nb = e1.block_ids(ts, np.array([cal.first]), cal.first, cal.last, days)
        boots[days] = e1.boot_indices(nb, NBOOT, SEED + days)
    return dict(ts=ts, o=o, h=h, l=l, c=c, v=v, Aa=Aa, T=T, cal=cal, f=f, R=R, XB=XB, raw=raw, elig=elig, sig=sig,
                ra=ra, rt=rt, trend=trend, terc=terc, key9=key9, cov=cov, cov2=cov2, boots=boots,
                gap=np.concatenate([o[:, 1:] / c[:, :-1] - 1, np.full((Aa, 1), np.nan)], axis=1))


# ------------------------------------------------------------------ paso 0: reproducción de E1
def repro(ctx, full=True):
    ra, rt, R, XB, f = ctx["ra"], ctx["rt"], ctx["R"], ctx["XB"], ctx["f"]
    ref = json.load(open(os.path.join(E1D, "results", "variants.json")))["C-48h-R1"]
    ratio = R[ra, rt]; xb = XB[ra, rt]
    net = f * ratio - 1
    m = e1.metrics(net, np.lexsort((ra, xb)))
    chk = {"n": (m["n"], ref["metrics"]["n"], 0)}
    for k in ("expectancy", "win_rate", "avg_win", "avg_loss", "pf", "sum", "worst", "p5"):
        chk[k] = (m[k], ref["metrics"][k], 1e-9)
    chk["max_loss_streak"] = (m["max_loss_streak"], ref["metrics"]["max_loss_streak"], 0)
    pf = e1.portfolio(ra, rt, xb, ratio, ctx["o"], ctx["c"], f)
    for k in ("ret", "dd", "exposure", "skipped"):
        chk["portfolio_" + k] = (pf[k], ref["portfolio"][k], 1e-9)
    out = {}
    if full:
        NRst = (f * R - 1)[None]
        refexp, means, short, _ = e1.reference_mc(ra, rt, ctx["cal"], NRst, e1.DRAWS, SEED + 2, 0)
        ex = net - refexp[0][ctx["cal"].gid(ra, rt)]
        chk["excess_vs_E1_reference"] = (float(np.nanmean(ex)), ref["excess_mean"], 1e-9)
        ctx["refexp_A"] = refexp[0]
        out["ref_means_A"] = means[0]
    ok = all(abs(a - b) <= tol for a, b, tol in chk.values())
    rep = {"ok": bool(ok), "checks": {k: {"e2": float(a), "e1": float(b), "tol": tol, "diff": float(a - b)} for k, (a, b, tol) in chk.items()}}
    return rep, out


# ------------------------------------------------------------------ pools y sorteos
def build_pools(ctx, ra, rt, key, nlev, raw=None):
    ok = ctx["elig"] & ~(ctx["raw"] if raw is None else raw)
    first, last = ctx["cal"].first, ctx["cal"].last
    pools = []
    for a, t in zip(ra.tolist(), rt.tolist()):
        lv = []
        kt = key[t]
        for w in WINDOWS[:nlev]:
            lo, hi = max(first, t - w), min(last, t + w)
            idx = np.flatnonzero(ok[a, lo:hi + 1] & (key[lo:hi + 1] == kt)) + lo
            lv.append(idx[idx != t].tolist())
        pools.append(lv)
    return pools


def draw_matches(pools, ra, nlev, ndraws, seed, T, Aa):
    n = len(ra)
    ral = ra.tolist()
    start = [next((L for L in range(nlev) if len(p[L]) >= MINPOOL), -1) for p in pools]
    rng = np.random.default_rng(seed)
    sel = np.full((ndraws, n), -1, dtype=np.int32)
    lvl = np.full((ndraws, n), -1, dtype=np.int8)
    ones = b"\x01" * (2 * (e1.COOL - 1) + 1)
    for d in range(ndraws):
        blk = [bytearray(T) for _ in range(Aa)]
        order = rng.permutation(n).tolist()
        U = rng.random((n, 6)).tolist()
        srow, lrow = sel[d], lvl[d]
        for i in order:
            s = start[i]
            if s < 0:
                continue
            b = blk[ral[i]]
            for L in range(s, nlev):
                pool = pools[i][L]
                m = len(pool)
                if m == 0:
                    continue
                got = -1
                u = U[i]
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
                    lrow[i] = L
                    break
    return sel, lvl, start


def core(ctx, ra, rt, sel, minfrac=MINFRAC):
    """m_i (media neta de la referencia sobre los sorteos emparejados), inclusión y d_i."""
    R, f = ctx["R"], ctx["f"]
    matched = sel >= 0
    RR = np.where(matched, R[ra[None, :], np.where(matched, sel, 0)], np.nan)
    frac = matched.mean(0)
    incl = frac >= minfrac
    rbar = np.full(len(ra), np.nan)
    if incl.any():
        rbar[incl] = np.nanmean(RR[:, incl], axis=0)
    ratio = R[ra, rt]
    return dict(RR=RR, frac=frac, incl=incl, rbar=rbar, ratio=ratio, net=f * ratio - 1, m=f * rbar - 1, d=(f * ratio - 1) - (f * rbar - 1))


def boot(ctx, x, rt, days_list=BLOCKS, B=None, one_sided_gt0=True):
    out = {}
    for days in days_list:
        bid, nb = e1.block_ids(ctx["ts"], rt, ctx["cal"].first, ctx["cal"].last, days)
        idx = ctx["boots"][days] if B is None else ctx["boots"][days][:B]
        s, nn = e1.block_sums(bid, nb, x)
        b = e1.boot_stat(idx, s, nn)
        out[days] = {"mean": float(x.mean()), "ci95": [float(np.percentile(b, 2.5)), float(np.percentile(b, 97.5))],
                     "p_gt0": float((1 + (b <= 0).sum()) / (len(b) + 1)), "blocks_nonempty": int((nn > 0).sum())}
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
        vs, vr = np.nanvar(Xs, 1), np.nanvar(Xr, 1)
        smd = (ms - mr) / np.sqrt((vs + vr) / 2)
        res[k] = {"mean_signal": float(ms.mean()), "mean_ref": float(mr.mean()), "smd_mean": float(smd.mean()),
                  "smd_p5_p95": [float(np.percentile(smd, 5)), float(np.percentile(smd, 95))],
                  "criterion": COVS.get(k), "ok": (abs(float(smd.mean())) <= COVS[k]) if k in COVS else None}
    return res


def matching_quality(ctx, ra, rt, sel, lvl, start, pools, cr):
    incl = cr["incl"]
    n = len(ra)
    nd = sel.shape[0]
    matched = sel >= 0
    picks = int(matched.sum())
    lv_share = {f"nivel{L+1}": float((lvl == L).sum() / max(picks, 1)) for L in range(len(WINDOWS)) if (lvl == L).any() or L < 2}
    st = np.array(start)
    pool0 = np.array([len(pools[i][start[i]]) if start[i] >= 0 else 0 for i in range(n)])
    tdist = np.where(matched, np.abs(sel - rt[None, :]), np.nan) / 6.0
    topshare, distinct = np.full(n, np.nan), np.full(n, np.nan)
    for i in np.flatnonzero(incl):
        col = sel[:, i]; col = col[col >= 0]
        u, cnt = np.unique(col, return_counts=True)
        topshare[i] = cnt.max() / len(col); distinct[i] = len(u)
    allb = sel[matched]
    abase = np.broadcast_to(ra[None, :], sel.shape)[matched].astype(np.int64)
    ub, uc = np.unique(abase * ctx["T"] + allb, return_counts=True)
    q = {
        "signals": n, "included": int(incl.sum()), "included_share": float(incl.mean()),
        "excluded": int((~incl).sum()),
        "start_level_counts": {"nivel1": int((st == 0).sum()), "nivel2": int((st == 1).sum()), "sin_pool>=5": int((st < 0).sum())},
        "share_of_picks_by_level": lv_share,
        "pool_size_median_included": float(np.median(pool0[incl])), "pool_size_p10_included": float(np.percentile(pool0[incl], 10)),
        "mean_abs_time_distance_days": float(np.nanmean(tdist[:, incl])),
        "median_abs_time_distance_days": float(np.nanmedian(tdist[:, incl])),
        "reuse_top_share_median": float(np.nanmedian(topshare)), "reuse_top_share_p90": float(np.nanpercentile(topshare, 90)),
        "share_included_with_top_share_le_0.35": float(np.nanmean(topshare[incl] <= 0.35)),
        "distinct_ref_bars_per_signal_median": float(np.nanmedian(distinct)),
        "distinct_ref_bars_total": int(len(ub)), "ref_picks_total": picks,
        "most_used_bar_picks_per_draw": float(uc.max() / nd),
        "bar_usage_p99_picks_per_draw": float(np.percentile(uc, 99) / nd),
    }
    sm = smd_table(ctx, ra, rt, sel, incl)
    q["smd"] = sm
    q["M1_included_ge_85"] = bool(q["included_share"] >= 0.85)
    q["M2_pool_median_ge_10"] = bool(q["pool_size_median_included"] >= 10)
    q["M3_smd"] = bool(all(sm[k]["ok"] for k in COVS))
    q["M4_reuse"] = bool(q["share_included_with_top_share_le_0.35"] >= 0.90)
    q["adequate"] = bool(q["M1_included_ge_85"] and q["M2_pool_median_ge_10"] and q["M3_smd"] and q["M4_reuse"])
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
                   "D_without_best": float(d[g != best].mean()),
                   "n_groups": len(sums), "n_groups_negative": int(sum(v < 0 for v in sums.values()))}
        if nm == "quarter":
            out[nm]["min_D_leave_one_out"] = float(min(d[g != k].mean() for k in sums))
    return out


def episodes(cr, ra, rt, ctx):
    incl = cr["incl"]
    d, t = cr["d"][incl], rt[incl]
    bid, nb = e1.block_ids(ctx["ts"], t, ctx["cal"].first, ctx["cal"].last, 14)
    cnt = np.bincount(bid, minlength=nb); sm = np.bincount(bid, weights=d, minlength=nb)
    out = {}
    drop = np.argsort(-cnt)[:10]
    k = ~np.isin(bid, drop)
    out["D_without_10_busiest_blocks"] = float(d[k].mean()); out["n_without"] = int(k.sum())
    drop = np.argsort(-sm)[:10]
    k = ~np.isin(bid, drop)
    out["D_without_10_top_contributing_blocks"] = float(d[k].mean()); out["n_without_top"] = int(k.sum())
    out["share_sum_d_top10_blocks"] = float(np.sort(sm)[::-1][:10].sum() / sm.sum()) if sm.sum() > 0 else None
    return out


def simultaneity(ctx, ra, rt):
    cnt = np.bincount(rt, minlength=ctx["T"])
    act = cnt[cnt > 0]
    bid, nb = e1.block_ids(ctx["ts"], rt, ctx["cal"].first, ctx["cal"].last, 14)
    bc = np.bincount(bid, minlength=nb)
    return {"bars_with_signals": int(len(act)), "mean_signals_per_active_bar": float(act.mean()), "max_signals_in_a_bar": int(act.max()),
            "share_of_signals_in_bars_with_ge3": float(act[act >= 3].sum() / act.sum()),
            "share_of_signals_in_bars_with_ge5": float(act[act >= 5].sum() / act.sum()),
            "blocks14d": int(nb), "blocks14d_with_signals": int((bc > 0).sum()),
            "signals_per_block14_median": float(np.median(bc[bc > 0])), "signals_per_block14_max": int(bc.max())}


def ref_metrics_and_portfolio(ctx, ra, rt, sel, incl):
    o, c, f, R, XB = ctx["o"], ctx["c"], ctx["f"], ctx["R"], ctx["XB"]
    rows, ports = [], []
    for d in range(min(KEEP_PORT, sel.shape[0])):
        m = (sel[d] >= 0) & incl
        ta, tt = ra[m], sel[d][m].astype(np.int64)
        ratio, xb = R[ta, tt], XB[ta, tt]
        net = f * ratio - 1
        rows.append(e1.metrics(net, np.lexsort((ta, xb))))
        ports.append(e1.portfolio(ta, tt, xb, ratio, o, c, f))
    agg = {}
    for k in ("n", "win_rate", "avg_win", "avg_loss", "expectancy", "sum", "pf", "worst", "p5", "max_loss_streak"):
        vals = np.array([r[k] for r in rows], float)
        agg[k] = {"median": float(np.median(vals)), "p5_p95": [float(np.percentile(vals, 5)), float(np.percentile(vals, 95))]}
    pagg = {}
    for k in ("ret", "dd", "exposure", "skipped"):
        vals = np.array([p[k] for p in ports], float)
        pagg[k] = {"median": float(np.median(vals)), "range": [float(vals.min()), float(vals.max())]}
    return agg, pagg


def cost_sens(ctx, cr, incl):
    out = {}
    for X in COSTS:
        k, k2 = e1.cost_pair(X)
        fx = e1.cfac(k, k2)
        nc, nr = fx * cr["ratio"][incl] - 1, fx * cr["rbar"][incl] - 1
        out[f"{X*100:.2f}%"] = {"C": float(nc.mean()), "ref": float(nr.mean()), "D": float((nc - nr).mean())}
    return out


# ------------------------------------------------------------------ una variante de referencia
def run_variant(ctx, name, key, nlev, seed, full=False, ndraws=DRAWS):
    ra, rt = ctx["ra"], ctx["rt"]
    t0 = time.time()
    pools = build_pools(ctx, ra, rt, key, nlev)
    sel, lvl, start = draw_matches(pools, ra, nlev, ndraws, seed, ctx["T"], ctx["Aa"])
    cr = core(ctx, ra, rt, sel)
    incl = cr["incl"]
    ri, ti = ra[incl], rt[incl]
    res = {"name": name, "levels": nlev, "draws": ndraws, "seed": seed, "n_included": int(incl.sum()),
           "C_expectancy_included": float(cr["net"][incl].mean()), "ref_expectancy": float(cr["m"][incl].mean()),
           "D": float(cr["d"][incl].mean()), "boot_D": boot(ctx, cr["d"][incl], ti), "boot_C": boot(ctx, cr["net"][incl], ti),
           "boot_ref": boot(ctx, cr["m"][incl], ti, days_list=(14,))}
    res["quality"] = matching_quality(ctx, ra, rt, sel, lvl, start, pools, cr)
    # D por sorteo (ruido de simulación)
    m = (sel >= 0) & incl[None, :]
    RRn = ctx["f"] * cr["RR"] - 1
    Dd = np.nanmean(np.where(m, cr["net"][None, :] - RRn, np.nan), axis=1)
    res["D_per_draw_p2.5_p97.5"] = [float(np.percentile(Dd, 2.5)), float(np.percentile(Dd, 97.5))]
    if full:
        d, net, mm = cr["d"], cr["net"], cr["m"]
        res["cost_sensitivity"] = cost_sens(ctx, cr, incl)
        res["metrics_C_included"] = e1.metrics(net[incl], np.lexsort((ri, ctx["XB"][ri, ti])))
        res["metrics_C_all"] = e1.metrics(cr["net"], np.lexsort((ra, ctx["XB"][ra, rt])))
        mid = (ctx["ts"][ctx["cal"].first] + ctx["ts"][ctx["cal"].last]) // 2
        half = (ctx["ts"][rt] >= mid).astype(int)
        res["halves"] = group_stats(cr, half, ["primera", "segunda"])
        for nmh, h_ in (("primera", half == 0), ("segunda", half == 1)):
            s = incl & h_
            res["halves"][nmh]["boot_D14"] = boot(ctx, cr["d"][s], rt[s], days_list=(14,))[14]
        res["regimen_tendencia"] = group_stats(cr, ctx["trend"][rt], {0: "bajista", 1: "lateral", 2: "alcista"})
        for k_, lab in ((0, "bajista"), (1, "lateral"), (2, "alcista")):
            s = incl & (ctx["trend"][rt] == k_)
            if s.sum() > 10:
                res["regimen_tendencia"][lab]["boot_D14"] = boot(ctx, cr["d"][s], rt[s], days_list=(14,))[14]
        res["celda_regimen"] = group_stats(cr, key[rt])
        res["por_activo"] = group_stats(cr, ra, A.SYMS)
        qid = ctx["cal"].qid[rt]
        res["por_trimestre"] = group_stats(cr, qid)
        yrs = ctx["ts"][rt].astype("datetime64[ms]").astype("datetime64[Y]").astype(int) + 1970
        res["por_anio"] = group_stats(cr, yrs)
        res["concentracion"] = concentration(cr, ra, rt, ctx)
        res["episodios"] = episodes(cr, ra, rt, ctx)
        res["simultaneidad_C"] = simultaneity(ctx, ra, rt)
        agg, pagg = ref_metrics_and_portfolio(ctx, ra, rt, sel, incl)
        res["ref_metrics_per_draw"] = agg
        res["ref_portfolio_100_draws"] = pagg
        res["gap_entry"] = {"C_mean": float(ctx["gap"][ri, ti].mean()), "C_median": float(np.median(ctx["gap"][ri, ti])),
                            "ref_mean": float(np.nanmean(np.where(m, ctx["gap"][ra[None, :], np.where(sel >= 0, sel, 0)], np.nan))),
                            "ref_median": float(np.nanmedian(np.where(m, ctx["gap"][ra[None, :], np.where(sel >= 0, sel, 0)], np.nan)))}
        # registro de todos los sorteos
        np.savez_compressed(os.path.join(OUT, "draws_primary.npz"), sel=sel, lvl=lvl, ra=ra.astype(np.int16), rt=rt.astype(np.int32))
        with open(os.path.join(OUT, "draws_summary.csv"), "w") as fh:
            fh.write("draw,n_matched_included,mean_ref_net_matched,D_matched,share_level2\n")
            mean_ref = np.nanmean(np.where(m, RRn, np.nan), axis=1)
            for dd in range(ndraws):
                mm_ = m[dd]
                fh.write(f"{dd},{int(mm_.sum())},{mean_ref[dd]:.8f},{Dd[dd]:.8f},{float((lvl[dd][mm_] == 1).mean()) if mm_.any() else 0:.4f}\n")
    res["_cr"] = cr
    res["elapsed_s"] = round(time.time() - t0)
    return res


# ------------------------------------------------------------------ C-mercado (diagnóstica)
def market_decomp(ctx):
    ra, rt, f = ctx["ra"], ctx["rt"], ctx["f"]
    NR = f * ctx["R"] - 1
    net = NR[ra, rt]
    s = NR[:, rt]                                   # [A, n]
    others = (s.sum(0) - net) / (ctx["Aa"] - 1)
    out = {"mean_C": float(net.mean()), "mean_others_same_window": float(others.mean()),
           "D_vs_others": float((net - others).mean()), "boot_D_vs_others": boot(ctx, net - others, rt)}
    nb = ra != 0
    btc = NR[0, rt]
    out["n_vs_btc"] = int(nb.sum()); out["mean_C_ex_btc_signals"] = float(net[nb].mean()); out["mean_btc_same_window"] = float(btc[nb].mean())
    out["D_vs_btc"] = float((net[nb] - btc[nb]).mean()); out["boot_D_vs_btc"] = boot(ctx, (net - btc)[nb], rt[nb])
    return out, net - others, net - btc


# ------------------------------------------------------------------ run
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


def run():
    t0 = time.time()
    os.makedirs(OUT, exist_ok=True)
    pre = os.path.join(HERE, "PREREGISTRO.md")
    meta = {"preregistro_sha256": hashlib.sha256(open(pre, "rb").read()).hexdigest(), "seed": SEED, "numpy": np.__version__,
            "python": sys.version.split()[0], "started": time.strftime("%Y-%m-%d %H:%M:%S")}
    ctx = build_ctx()
    meta.update({"assets": ctx["Aa"], "bars": ctx["T"], "signals_C": int(len(ctx["ra"]))})
    print("Paso 0: reproducción de E1...", flush=True)
    rep, extra = repro(ctx, full=True)
    json.dump(jsonable(rep), open(os.path.join(OUT, "repro_e1.json"), "w"), indent=1)
    print(json.dumps(jsonable(rep["checks"]), indent=0)[:1500])
    if not rep["ok"]:
        print("REPRODUCCIÓN DE E1 FALLIDA: se detiene el experimento."); json.dump(jsonable(meta), open(os.path.join(OUT, "run_meta.json"), "w"), indent=1)
        sys.exit(2)
    print(f"Reproducción OK ({time.time()-t0:.0f}s). Primaria...", flush=True)
    prim = run_variant(ctx, "PRIMARIA B (clave 9 celdas, niveles 14/28 d)", ctx["key9"], 2, SEED + 101, full=True)
    cr = prim.pop("_cr")
    print(f"Primaria lista ({time.time()-t0:.0f}s): n={prim['n_included']} D={prim['D']*100:+.3f}pp adecuado={prim['quality']['adequate']}", flush=True)
    sec = {}
    zero = np.zeros(ctx["T"], dtype=int)
    tr = np.where(ctx["key9"] >= 0, ctx["trend"], -1)
    specs = [("S1_ventana14d_sin_fallback", ctx["key9"], 1, SEED + 201), ("S2_solo_tendencia", tr, 2, SEED + 202),
             ("S3_sin_regimen", zero, 2, SEED + 203), ("S4_permite_56d", ctx["key9"], 3, SEED + 204)]
    for nm, key, nlev, sd in specs:
        r = run_variant(ctx, nm, key, nlev, sd)
        r.pop("_cr"); sec[nm] = r
        print(f"{nm} listo ({time.time()-t0:.0f}s): D={r['D']*100:+.3f}pp", flush=True)
    # A: referencia histórica de E1 (activo × trimestre)
    gid = ctx["cal"].gid(ctx["ra"], ctx["rt"])
    netA = ctx["f"] * ctx["R"][ctx["ra"], ctx["rt"]] - 1
    exA = netA - ctx["refexp_A"][gid]
    sec["A_referencia_E1_activo_trimestre"] = {"D": float(np.nanmean(exA)), "boot_D": boot(ctx, np.nan_to_num(exA), ctx["rt"]),
                                               "n": int(len(exA)), "ref_expectancy": float(np.nanmean(ctx["refexp_A"][gid]))}
    mk, dothers, dbtc = market_decomp(ctx)
    sec["C_mercado_diagnostica_otros19"] = {"D": mk["D_vs_others"], "boot_D": mk["boot_D_vs_others"], "mean_C": mk["mean_C"],
                                            "mean_market_same_window": mk["mean_others_same_window"]}
    sec["C_mercado_diagnostica_BTC"] = {"D": mk["D_vs_btc"], "boot_D": mk["boot_D_vs_btc"], "n": mk["n_vs_btc"],
                                        "mean_C": mk["mean_C_ex_btc_signals"], "mean_btc_same_window": mk["mean_btc_same_window"]}
    names = [k for k in sec]
    ps = [sec[k]["boot_D"][14]["p_gt0"] for k in names]
    for k, pa in zip(names, e1.holm(ps)):
        sec[k]["p_holm_descriptivo"] = pa
    meta["finished"] = time.strftime("%Y-%m-%d %H:%M:%S"); meta["elapsed_s"] = round(time.time() - t0)
    json.dump(jsonable(meta), open(os.path.join(OUT, "run_meta.json"), "w"), indent=1)
    json.dump(jsonable(prim), open(os.path.join(OUT, "primary.json"), "w"), indent=1)
    json.dump(jsonable(sec), open(os.path.join(OUT, "secondary.json"), "w"), indent=1)
    print("run completo", flush=True)


# ------------------------------------------------------------------ placebo del procedimiento completo
def placebo():
    t0 = time.time()
    ctx = build_ctx()
    ra0, rt0 = ctx["ra"], ctx["rt"]
    first, last, T, Aa = ctx["cal"].first, ctx["cal"].last, ctx["T"], ctx["Aa"]
    L = last - first + 1
    rng = np.random.default_rng(SEED + 7)
    shifts = rng.choice(np.arange(360, L - 360), size=PL_N, replace=False)
    sub_sig = ctx["sig"][:, first:last + 1]
    sub_raw = (ctx["raw"] & ctx["elig"])[:, first:last + 1]
    rows = []
    for p, k in enumerate(shifts.tolist()):
        sig = np.zeros((Aa, T), bool); raw = np.zeros((Aa, T), bool)
        sig[:, first:last + 1] = np.roll(sub_sig, k, axis=1)
        raw[:, first:last + 1] = np.roll(sub_raw, k, axis=1)
        sig = A.cooldown(sig)
        ra, rt = np.nonzero(sig)
        order = np.lexsort((ra, rt)); ra, rt = ra[order], rt[order]
        pools = build_pools(ctx, ra, rt, ctx["key9"], 2, raw=raw)
        sel, lvl, start = draw_matches(pools, ra, 2, PL_DRAWS, SEED + 5000 + p, T, Aa)
        cr = core(ctx, ra, rt, sel)
        inc = cr["incl"]
        b = boot(ctx, cr["d"][inc], rt[inc], days_list=(14,), B=PL_B)[14]
        rows.append({"shift": k, "n": int(inc.sum()), "D": b["mean"], "ci": b["ci95"], "p": b["p_gt0"]})
        if p % 20 == 0:
            print(f"placebo {p}/{PL_N} ({time.time()-t0:.0f}s)", flush=True)
    D = np.array([r["D"] for r in rows])
    rej = np.mean([(r["p"] < 0.05) and (r["D"] > 0) for r in rows])
    cov_ = np.mean([r["ci"][0] <= 0 <= r["ci"][1] for r in rows])
    out = {"placebos": PL_N, "draws_each": PL_DRAWS, "boot_each": PL_B, "rate_p_lt_0.05_and_D_gt0": float(rej), "ci95_covers_0": float(cov_),
           "mean_D": float(D.mean()), "sd_D": float(D.std()), "n_signals_median": float(np.median([r["n"] for r in rows])),
           "calibrated": bool(rej <= 0.09 and cov_ >= 0.92 and abs(D.mean()) <= 0.001), "rows": rows}
    os.makedirs(OUT, exist_ok=True)
    json.dump(jsonable(out), open(os.path.join(OUT, "placebo.json"), "w"), indent=1)
    print({k: v for k, v in out.items() if k != "rows"}, flush=True)


# ------------------------------------------------------------------ decisión (sección 11)
def decide():
    P = json.load(open(os.path.join(OUT, "primary.json")))
    S = json.load(open(os.path.join(OUT, "secondary.json")))
    PL = json.load(open(os.path.join(OUT, "placebo.json")))
    rep = json.load(open(os.path.join(OUT, "repro_e1.json")))
    b14, b28 = P["boot_D"]["14"], P["boot_D"]["28"]
    conc = P["concentracion"]
    ex40 = P["cost_sensitivity"]["0.40%"]["C"]
    crit = {
        "0a_reproduccion_E1": rep["ok"], "0b_emparejamiento_adecuado": P["quality"]["adequate"],
        "placebo_calibrado": PL["calibrated"],
        "D_gt0_y_IC14_inferior_gt0": P["D"] > 0 and b14["ci95"][0] > 0,
        "IC28_inferior_gt0": b28["ci95"][0] > 0,
        "C_neto_gt0_a_0.40": ex40 > 0,
        "D_gt0_ambas_mitades": all(P["halves"][h]["D"] > 0 for h in P["halves"]),
        "D_gt0_tres_regimenes_tendencia": len(P["regimen_tendencia"]) == 3 and all(v["D"] > 0 for v in P["regimen_tendencia"].values()),
        "concentracion_activo_trimestre_le_30": (conc["asset"]["best_share"] is not None and conc["asset"]["best_share"] <= 0.30
                                                 and conc["quarter"]["best_share"] is not None and conc["quarter"]["best_share"] <= 0.30),
        "D_gt0_sin_mejor_activo_ni_trimestre": conc["asset"]["D_without_best"] > 0 and conc["quarter"]["D_without_best"] > 0,
    }
    if not (crit["0a_reproduccion_E1"] and crit["0b_emparejamiento_adecuado"]):
        verdict = "INCONCLUSO (puerta fallida)"
    elif P["D"] <= 0 or b14["ci95"][1] < 0 or (PL["calibrated"] and P["C_expectancy_included"] <= 0):
        verdict = "DESCARTAR C como candidata"
    elif all(crit.values()):
        verdict = "CANDIDATA NO CONFIRMADA"
    else:
        verdict = "INCONCLUSO"
    dec = {"verdict": verdict, "criteria": crit, "D": P["D"], "D_ci14": b14["ci95"], "D_ci28": b28["ci95"], "D_ci7": P["boot_D"]["7"]["ci95"],
           "p_H_E2": b14["p_gt0"], "dd_cartera_E1_independiente": "35,0 % (no cumple ≤15 %); C no se implementa"}
    json.dump(jsonable(dec), open(os.path.join(OUT, "decision.json"), "w"), indent=1)
    print(json.dumps(jsonable(dec), indent=1, ensure_ascii=False))


# ------------------------------------------------------------------ autopruebas
def selftest():
    ctx = build_ctx()
    ok = True

    def chk(name, cond):
        nonlocal ok
        print(("OK   " if cond else "FALLA"), name, flush=True)
        ok &= bool(cond)

    # costos
    x = 0.0030; k, k2 = e1.cost_pair(x)
    chk("costos: cost_pair exacto", abs(1 - e1.cfac(k, k2) - x) < 1e-12)
    pe, ps = 100.0, 103.0
    buy = pe * (1 + SLIP); sell = ps * (1 - SLIP)
    manual = (1 / buy) * (1 - FEE) * sell * (1 - FEE) / 1.0 - 1  # 1 unidad monetaria
    chk("costos: fórmula frente a compra/venta explícita", abs(e1.cfac(FEE, SLIP) * ps / pe - 1 - manual) < 1e-12)
    # causalidad de señales y régimen
    o, h, l, c, v = ctx["o"], ctx["h"], ctx["l"], ctx["c"], ctx["v"]
    t0 = 5000
    rng = np.random.default_rng(1)
    c2, o2, h2, l2, v2 = c.copy(), o.copy(), h.copy(), l.copy(), v.copy()
    mult = 1 + rng.normal(0, 0.1, size=c[:, t0 + 1:].shape)
    for arr in (c2, o2, h2, l2):
        arr[:, t0 + 1:] = arr[:, t0 + 1:] * mult
    s1 = A.signals("C", N_PAR, o, h, l, c, v, {}); s2 = A.signals("C", N_PAR, o2, h2, l2, c2, v2, {})
    chk("señales causales (alterar el futuro no cambia el pasado)", np.array_equal(s1[:, :t0 + 1], s2[:, :t0 + 1]))
    saved = dict(c=ctx["c"], o=ctx["o"], h=ctx["h"], l=ctx["l"], v=ctx["v"])
    # reconstruye régimen con datos alterados: se reutiliza build_ctx sobre arrays alterados vía parche temporal de e1.load_all
    orig = e1.load_all
    e1.load_all = lambda: (ctx["ts"], o2, h2, l2, c2, v2)
    try:
        ctx2 = build_ctx()
    finally:
        e1.load_all = orig
    chk("régimen causal (key9 y covariables hasta t0 sin cambio)", np.array_equal(ctx["key9"][:t0 + 1], ctx2["key9"][:t0 + 1])
        and all(np.allclose(ctx["cov"][k_][:t0 + 1], ctx2["cov"][k_][:t0 + 1], equal_nan=True) for k_ in ctx["cov"]))
    chk("régimen definido en todas las barras elegibles", (ctx["key9"][ctx["cal"].first:ctx["cal"].last + 1] >= 0).all())
    # reproducción de E1 (sin MC de referencia)
    rep, _ = repro(ctx, full=False)
    chk(f"reproducción E1 (n={len(ctx['ra'])})", rep["ok"])
    # parejas válidas
    ra, rt = ctx["ra"], ctx["rt"]
    key = ctx["key9"]
    pools = build_pools(ctx, ra, rt, key, 2)
    sel, lvl, start = draw_matches(pools, ra, 2, 40, SEED + 3, ctx["T"], ctx["Aa"])
    bad = dict(act=0, win=0, reg=0, raw=0, elig=0, cool=0, dup=0)
    for d in range(sel.shape[0]):
        seen = {}
        for i in np.flatnonzero(sel[d] >= 0):
            tt, a, t = int(sel[d, i]), int(ra[i]), int(rt[i])
            w = WINDOWS[int(lvl[d, i])]
            bad["win"] += abs(tt - t) > w
            bad["reg"] += key[tt] != key[t]
            bad["raw"] += bool(ctx["raw"][a, tt]) or tt == t
            bad["elig"] += not (ctx["cal"].first <= tt <= ctx["cal"].last)
            seen.setdefault(a, []).append(tt)
        for a, lst in seen.items():
            lst.sort()
            bad["dup"] += len(lst) != len(set(lst))
            bad["cool"] += int(any(y - x < e1.COOL for x, y in zip(lst, lst[1:])))
    chk(f"parejas válidas (ventana, régimen, sin señal bruta, elegible, cooldown, sin duplicados) {bad}", sum(bad.values()) == 0)
    chk("sorteos distintos", len({sel[d].tobytes() for d in range(sel.shape[0])}) > 30)
    # inyección de efecto conocido
    cr0 = core(ctx, ra, rt, sel)
    R2 = ctx["R"].copy()
    R2[ra, rt] = R2[ra, rt] + 0.01 / ctx["f"]
    ctxi = dict(ctx); ctxi["R"] = R2
    cr1 = core(ctxi, ra, rt, sel)
    dd = cr1["d"][cr1["incl"]].mean() - cr0["d"][cr0["incl"]].mean()
    chk(f"efecto sintético +1 pp recuperado (delta={dd*100:.4f} pp)", abs(dd - 0.01) < 1e-9)
    print("SELFTEST", "OK" if ok else "FALLÓ")
    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    {"selftest": selftest, "run": run, "placebo": placebo, "decide": decide}[sys.argv[1]]()
