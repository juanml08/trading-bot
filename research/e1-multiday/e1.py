"""E1: ruptura (B) y expansión de volatilidad (C) con hold de 24/48/72 h. Ver PREREGISTRO.md (cerrado antes de ejecutar).

Uso (desde research/e1-multiday):
  python -I e1.py selftest   # validaciones de costos, stop, causalidad, referencia
  python -I e1.py run        # experimento completo -> results/
  python -I e1.py placebo    # calibración del bootstrap con placebos (desplazamiento circular)
Solo lectura sobre ../signal-audit (data4h y audit_4h.py). No usa red ni BD.
"""
import sys, os, json, hashlib, time, bisect
sys.dont_write_bytecode = True
import numpy as np

HERE = os.path.dirname(os.path.abspath(__file__))
SA = os.path.normpath(os.path.join(HERE, "..", "signal-audit"))
DATA = os.path.join(SA, "data4h")
OUT = os.path.join(HERE, "results")
sys.path.insert(0, SA)
import audit_4h as A  # noqa: E402  (solo se usan load, signals, cooldown, SYMS, WARM, COOL)

SEED = 20261011
FEE, SLIP = 0.001, 0.0005
N_PAR = 24
STOP = 0.08
WARM, COOL, MAXH = A.WARM, A.COOL, 18
HZ = {24: 6, 48: 12, 72: 18}
EXITS = ("R1", "R2")
VARIANTS = [(fam, h, ex) for fam in ("B", "C") for h in (24, 48, 72) for ex in EXITS]
PRIMARY = [("B", 48, "R1"), ("C", 48, "R1")]
BASE_COSTS = [0.0020, 0.0030, 0.0040, 0.0050]
BLOCKS = (14, 7, 28)
NBOOT, DRAWS, PORT_DRAWS, ALLOC = 10000, 2000, 100, 0.10
NEIGH = [18, 21, 27, 30]


def vname(v):
    return f"{v[0]}-{v[1]}h-{v[2]}"


# ------------------------------------------------------------------ costos
def cfac(c, s):
    """Factor neto de una ida y vuelta con precios de mercado iguales: (1-c)^2 (1-s)/(1+s)."""
    return (1 - c) ** 2 * (1 - s) / (1 + s)


def cost_pair(X):
    """(c, s) en proporción 2:1 (como 0,10 %/0,05 %) tal que 1 - cfac = X exacto (bisección)."""
    lo, hi = 0.0, 0.05
    for _ in range(200):
        k = (lo + hi) / 2
        if 1 - cfac(k, k / 2) < X:
            lo = k
        else:
            hi = k
    k = (lo + hi) / 2
    return k, k / 2


# ------------------------------------------------------------------ datos y retornos de ida/vuelta
def load_all():
    ts, o, h, l, c, v = A.load(DATA)
    return ts, o, h, l, c, v


def ratios(o, h, l, c, hz, exit_rule):
    """P_salida/P_entrada y barra de salida, matrices [A,T]; NaN fuera de t en [0, T-19]. Entrada: open[t+1]."""
    Aa, T = c.shape
    tn = T - MAXH  # t = 0..T-19
    R = np.full((Aa, T), np.nan)
    XB = np.full((Aa, T), -1, dtype=np.int64)
    pe = o[:, 1:1 + tn]
    exit_px = c[:, hz:hz + tn].copy()
    xbar = np.broadcast_to(np.arange(tn) + hz, (Aa, tn)).copy()
    if exit_rule == "R2":
        S = (1 - STOP) * pe
        stopped = np.zeros((Aa, tn), bool)
        for j in range(1, hz + 1):
            lo_j = l[:, j:j + tn]
            op_j = o[:, j:j + tn]
            hit = ~stopped & (lo_j <= S)
            px = np.where(op_j <= S, op_j, S) if j > 1 else S  # hueco: sale a la apertura si abre bajo S
            exit_px = np.where(hit, px, exit_px)
            xbar = np.where(hit, np.arange(tn) + j, xbar)
            stopped |= hit
    R[:, :tn] = exit_px / pe
    XB[:, :tn] = xbar
    return R, XB


def net_from_ratio(R, c_, s_):
    return cfac(c_, s_) * R - 1


# ------------------------------------------------------------------ métricas
def metrics(net, order=None):
    n = len(net)
    if n == 0:
        return {"n": 0}
    w, lz = net[net > 0], net[net < 0]
    pf = float(w.sum() / -lz.sum()) if lz.size else float("inf")
    seq = net if order is None else net[order]
    run = best = 0
    for x in seq:
        run = run + 1 if x < 0 else 0
        best = max(best, run)
    return {"n": int(n), "win_rate": float((net > 0).mean()), "avg_win": float(w.mean()) if w.size else 0.0,
            "avg_loss": float(lz.mean()) if lz.size else 0.0, "expectancy": float(net.mean()),
            "sum": float(net.sum()), "pf": pf, "worst": float(net.min()), "p5": float(np.percentile(net, 5)),
            "max_loss_streak": int(best)}


def portfolio(ta, tt, xb, ratio, o, c, f):
    """Cartera con capital compartido: 10 % del patrimonio al cierre de t, omite si falta efectivo.
    ta, tt: activo y barra de señal; xb: barra de salida; ratio: P_sal/P_ent. Devuelve retorno, DD, exposición, omitidas."""
    n = len(ta)
    if n == 0:
        return {"ret": 0.0, "dd": 0.0, "exposure": 0.0, "skipped": 0}
    order = np.lexsort((ta, tt))
    ta, tt, xb, ratio = ta[order], tt[order], xb[order], ratio[order]
    entry = tt + 1
    cash, eprev, ptr, skipped = 1.0, 1.0, 0, 0
    opened = []  # (alloc, activo, P_entrada de mercado, barra de salida, f*ratio)
    peak, mdd, expo_sum, nbars = 1.0, 0.0, 0.0, 0
    first, last = int(entry[0]), int(xb.max())
    for j in range(first, last + 1):
        while ptr < n and entry[ptr] == j:
            alloc = ALLOC * eprev
            if cash >= alloc - 1e-12:
                cash -= alloc
                opened.append((alloc, int(ta[ptr]), float(o[ta[ptr], j]), int(xb[ptr]), f * float(ratio[ptr])))
            else:
                skipped += 1
            ptr += 1
        still, val = [], 0.0
        for p in opened:
            if p[3] == j:
                cash += p[0] * p[4]
            else:
                still.append(p)
                val += p[0] * f * c[p[1], j] / p[2]
        opened = still
        eprev = cash + val
        expo_sum += val / eprev if eprev > 0 else 0.0
        nbars += 1
        peak = max(peak, eprev)
        mdd = max(mdd, (peak - eprev) / peak)
    return {"ret": float(eprev - 1.0), "dd": float(mdd), "exposure": float(expo_sum / max(nbars, 1)), "skipped": int(skipped)}


# ------------------------------------------------------------------ bloques y bootstrap
def block_ids(ts, rt, t_first, t_last, days):
    L = days * 86400000
    nb = int((ts[t_last] - ts[t_first]) // L) + 1
    return ((ts[rt] - ts[t_first]) // L).astype(np.int64), nb


def boot_indices(nb, B, seed):
    return np.random.default_rng(seed).integers(0, nb, size=(B, nb))


def boot_stat(idx, sums, ns):
    return sums[idx].sum(1) / np.maximum(ns[idx].sum(1), 1)


def block_sums(bid, nb, x):
    return np.bincount(bid, weights=x, minlength=nb), np.bincount(bid, minlength=nb).astype(float)


def holm(ps, alpha=0.05):
    m = len(ps)
    order = np.argsort(ps)
    adj = np.empty(m)
    run = 0.0
    for r, i in enumerate(order):
        run = max(run, (m - r) * ps[i])
        adj[i] = min(run, 1.0)
    return adj.tolist()


# ------------------------------------------------------------------ elegibilidad, grupos, referencia
class Cal:
    def __init__(self, ts):
        self.ts = ts
        T = len(ts)
        self.T = T
        self.first, self.last = WARM, T - MAXH - 1  # t elegibles: [800, T-19]
        months = ts.astype("datetime64[ms]").astype("datetime64[M]").astype(np.int64)
        q = months // 3
        self.qid = q - q[self.first]
        qs = self.qid[self.first:self.last + 1]
        self.NQ = int(qs.max()) + 1
        self.ranges = []
        for k in range(self.NQ):
            idx = np.flatnonzero((self.qid == k) & (np.arange(T) >= self.first) & (np.arange(T) <= self.last))
            self.ranges.append((int(idx[0]), int(idx[-1])))

    def gid(self, a, t):
        return a * self.NQ + self.qid[t]


def elig_mask(Aa, cal):
    m = np.zeros((Aa, cal.T), bool)
    m[:, cal.first:cal.last + 1] = True
    return m


def real_signals(fam, N, o, h, l, c, v, cal):
    sig = A.cooldown(A.signals(fam, N, o, h, l, c, v, {}))
    sig &= elig_mask(c.shape[0], cal)
    ra, rt = np.nonzero(sig)
    order = np.lexsort((ra, rt))
    return ra[order], rt[order]


def analytic_refexp(NR, cal, Aa):
    """media de NR sobre todas las barras elegibles de cada (activo, trimestre) (sin espaciado)."""
    out = np.full(Aa * cal.NQ, np.nan)
    for a in range(Aa):
        for k, (lo, hi) in enumerate(cal.ranges):
            out[a * cal.NQ + k] = NR[a, lo:hi + 1].mean()
    return out


def sample_cell(rng, n, k):
    """k posiciones en [0,n) con separación >= COOL, por permutación y aceptación secuencial."""
    best = []
    for _ in range(8):
        got = []
        for x in rng.permutation(n).tolist():
            i = bisect.bisect_left(got, x)
            if (i > 0 and x - got[i - 1] < COOL) or (i < len(got) and got[i] - x < COOL):
                continue
            got.insert(i, x)
            if len(got) == k:
                break
        if len(got) > len(best):
            best = got
        if len(best) == k:
            break
    return best


def reference_mc(ra, rt, cal, NRst, draws, seed, keep):
    """Sorteos de la referencia pareada por (activo, trimestre). NRst: [V,A,T] retornos netos por variante.
    Devuelve esperanza por celda [V,G], medias por sorteo [V,D], déficit y los primeros `keep` sorteos."""
    V, Aa, T = NRst.shape
    G = Aa * cal.NQ
    gids = cal.gid(ra, rt)
    cells, cnt = np.unique(gids, return_counts=True)
    cell_a, cell_q = cells // cal.NQ, cells % cal.NQ
    rng = np.random.default_rng(seed)
    msum = np.zeros((V, G)); dcnt = np.zeros(G)
    means = np.zeros((V, draws)); short = 0; kept = []
    for d in range(draws):
        SA_, ST_ = [], []
        for a, q, k in zip(cell_a.tolist(), cell_q.tolist(), cnt.tolist()):
            lo, hi = cal.ranges[q]
            got = sample_cell(rng, hi - lo + 1, k)
            short += k - len(got)
            SA_.extend([a] * len(got)); ST_.extend((lo + np.array(got, dtype=np.int64)).tolist())
        sa, st = np.array(SA_, dtype=np.int64), np.array(ST_, dtype=np.int64)
        g = cal.gid(sa, st)
        vals = NRst[:, sa, st]
        cc = np.bincount(g, minlength=G).astype(float)
        ok = cc > 0
        for v in range(V):
            s = np.bincount(g, weights=vals[v], minlength=G)
            msum[v, ok] += s[ok] / cc[ok]
            means[v, d] = vals[v].mean()
        dcnt[ok] += 1
        if d < keep:
            kept.append((sa, st))
    refexp = np.full((V, G), np.nan)
    ok = dcnt > 0
    refexp[:, ok] = msum[:, ok] / dcnt[ok]
    return refexp, means, short, kept


# ------------------------------------------------------------------ análisis de una variante
def analyze_variant(name, ra, rt, R, XB, refexp_v, ref_means_v, cal, o, c, boots, ts, cache, regimes):
    base_c, base_s = FEE, SLIP
    f = cfac(base_c, base_s)
    ratio = R[ra, rt]
    xb = XB[ra, rt]
    net = f * ratio - 1
    gid = cal.gid(ra, rt)
    ex = net - refexp_v[gid]
    nan_ref = int(np.isnan(ex).sum())
    order_exit = np.lexsort((ra, xb))
    res = {"variant": name, "metrics": metrics(net, order_exit), "ref_cells_missing": nan_ref}
    res["reference_mean_net"] = float(np.nansum(refexp_v[gid]) / len(gid))
    res["excess_mean"] = float(np.nanmean(ex))
    # bootstrap por bloques
    for days in BLOCKS:
        bid, nb = block_ids(ts, rt, cal.first, cal.last, days)
        idx = boots[days]
        sn, nn = block_sums(bid, nb, net)
        se, _ = block_sums(bid, nb, np.nan_to_num(ex))
        sw, _ = block_sums(bid, nb, (net > 0).astype(float))
        b_net, b_ex, b_wr = boot_stat(idx, sn, nn), boot_stat(idx, se, nn), boot_stat(idx, sw, nn)
        res[f"boot{days}d"] = {
            "n_blocks": nb, "n_blocks_nonempty": int((nn > 0).sum()),
            "net_ci95": [float(np.percentile(b_net, 2.5)), float(np.percentile(b_net, 97.5))],
            "excess_ci95": [float(np.percentile(b_ex, 2.5)), float(np.percentile(b_ex, 97.5))],
            "win_rate_ci95": [float(np.percentile(b_wr, 2.5)), float(np.percentile(b_wr, 97.5))],
            "p_excess_gt0": float((1 + (b_ex <= 0).sum()) / (len(b_ex) + 1)),
            "p_net_gt0": float((1 + (b_net <= 0).sum()) / (len(b_net) + 1))}
    res["p_mc_reference"] = float((1 + (ref_means_v >= net.mean()).sum()) / (len(ref_means_v) + 1))
    res["reference_draw_mean_range"] = [float(np.percentile(ref_means_v, 2.5)), float(np.percentile(ref_means_v, 97.5))]
    # sensibilidad a costos
    sens = {}
    for X in BASE_COSTS:
        k, k2 = cost_pair(X)
        nx = cfac(k, k2) * ratio - 1
        m = metrics(nx, order_exit)
        sens[f"{X*100:.2f}%"] = {"expectancy": m["expectancy"], "pf": m["pf"], "win_rate": m["win_rate"], "sum": m["sum"]}
    res["cost_sensitivity"] = sens
    res["breakeven_round_trip_cost"] = float(1 - 1 / ratio.mean())
    # por activo, año, régimen, mitades
    per_asset = {}
    for a in range(len(A.SYMS)):
        s = ra == a
        if s.any():
            per_asset[A.SYMS[a]] = {"n": int(s.sum()), "expectancy": float(net[s].mean()), "sum": float(net[s].sum()),
                                    "win_rate": float((net[s] > 0).mean())}
    res["per_asset"] = per_asset
    years = ts[rt].astype("datetime64[ms]").astype("datetime64[Y]").astype(int) + 1970
    res["per_year"] = {int(y): {"n": int((years == y).sum()), "expectancy": float(net[years == y].mean()),
                                "sum": float(net[years == y].sum()), "excess": float(np.nanmean(ex[years == y]))}
                       for y in np.unique(years)}
    rg = regimes[rt]
    res["per_regime"] = {r: {"n": int((rg == r).sum()), "expectancy": float(net[rg == r].mean()),
                             "excess": float(np.nanmean(ex[rg == r]))} for r in ("alcista", "lateral", "bajista") if (rg == r).any()}
    mid = (ts[cal.first] + ts[cal.last]) // 2
    h1 = ts[rt] < mid
    res["halves"] = {"first": {"n": int(h1.sum()), "expectancy": float(net[h1].mean()) if h1.any() else None,
                               "excess": float(np.nanmean(ex[h1])) if h1.any() else None},
                     "second": {"n": int((~h1).sum()), "expectancy": float(net[~h1].mean()) if (~h1).any() else None,
                                "excess": float(np.nanmean(ex[~h1])) if (~h1).any() else None}}
    # concentración por activo
    tot = net.sum()
    sums = {a: v["sum"] for a, v in per_asset.items()}
    best = max(sums, key=sums.get)
    bi = A.SYMS.index(best)
    res["concentration"] = {"best_asset": best, "best_share_of_total": float(sums[best] / tot) if tot != 0 else None,
                            "expectancy_without_best": float(net[ra != bi].mean())}
    # cartera
    res["portfolio"] = portfolio(ra, rt, xb, ratio, o, c, f)
    res["_net"] = net
    return res


def regime_array(c, T):
    r = np.full(T, "lateral", dtype=object)
    ret = np.full(T, np.nan)
    ret[180:] = c[0, 180:] / c[0, :-180] - 1
    r[ret > 0.10] = "alcista"
    r[ret < -0.10] = "bajista"
    return r


def decide(res, p_adj):
    """Aplica literalmente la sección 9 del preregistro. res: análisis de una primaria."""
    m, b = res["metrics"], res["boot14d"]
    yrs = [y for y, d in res["per_year"].items() if d["n"] >= 30]
    pos_years = [y for y in yrs if res["per_year"][y]["expectancy"] > 0]
    c = {
        "1_net_ci_lower_gt0": m["expectancy"] > 0 and b["net_ci95"][0] > 0,
        "2_excess_holm_p_lt_0.05": res["excess_mean"] > 0 and p_adj < 0.05,
        "3_net_gt0_at_0.40": res["cost_sensitivity"]["0.40%"]["expectancy"] > 0,
        "4_halves_and_years": (res["halves"]["first"]["expectancy"] or -1) > 0 and (res["halves"]["second"]["expectancy"] or -1) > 0
                              and len(yrs) > 0 and len(pos_years) >= 0.6 * len(yrs),
        "5_concentration": (res["concentration"]["best_share_of_total"] is not None and res["concentration"]["best_share_of_total"] <= 0.30
                            and res["concentration"]["expectancy_without_best"] > 0),
        "6_pf_ge_1.15": m["pf"] >= 1.15,
        "7_dd_le_15": res["portfolio"]["dd"] <= 0.15,
        "8_n_ge_150_blocks_ge_30": m["n"] >= 150 and b["n_blocks_nonempty"] >= 30,
    }
    discard = m["expectancy"] <= 0 or b["excess_ci95"][1] < 0
    verdict = "descartar" if discard else ("candidata_no_confirmada" if all(c.values()) else "investigar_mas")
    return {"criteria": c, "discard_condition": bool(discard), "verdict": verdict,
            "years_used": [int(y) for y in yrs], "years_positive": [int(y) for y in pos_years]}


# ------------------------------------------------------------------ run
def jsonable(x):
    if isinstance(x, dict):
        return {str(k): jsonable(v) for k, v in x.items() if not str(k).startswith("_")}
    if isinstance(x, (list, tuple)):
        return [jsonable(v) for v in x]
    if isinstance(x, (np.floating, float)):
        return None if (x != x) else (float(x) if abs(x) != float("inf") else "inf")
    if isinstance(x, (np.integer,)):
        return int(x)
    return x


def run():
    t0 = time.time()
    os.makedirs(OUT, exist_ok=True)
    pre = os.path.join(HERE, "PREREGISTRO.md")
    meta = {"preregistro_sha256": hashlib.sha256(open(pre, "rb").read()).hexdigest(), "seed": SEED, "numpy": np.__version__,
            "python": sys.version.split()[0], "started": time.strftime("%Y-%m-%d %H:%M:%S")}
    ts, o, h, l, c, v = load_all()
    Aa, T = c.shape
    cal = Cal(ts)
    meta.update({"assets": Aa, "bars": T, "first_bar": str(np.datetime64(int(ts[0]), "ms")), "last_bar": str(np.datetime64(int(ts[-1]), "ms")),
                 "eligible_first": str(np.datetime64(int(ts[cal.first]), "ms")), "eligible_last": str(np.datetime64(int(ts[cal.last]), "ms"))})
    f = cfac(FEE, SLIP)
    meta["round_trip_cost_base"] = 1 - f
    RAT, XBM = {}, {}
    for hh in HZ:
        for ex in EXITS:
            RAT[(hh, ex)], XBM[(hh, ex)] = ratios(o, h, l, c, HZ[hh], ex)
    regimes = regime_array(c, T)
    boots = {}
    for days in BLOCKS:
        _, nb = block_ids(ts, np.array([cal.first]), cal.first, cal.last, days)
        boots[days] = boot_indices(nb, NBOOT, SEED + days)
    results, tables_ref, decisions = {}, {}, {}
    all_res = {}
    for fam in ("B", "C"):
        ra, rt = real_signals(fam, N_PAR, o, h, l, c, v, cal)
        vlist = [x for x in VARIANTS if x[0] == fam]
        NRst = np.stack([f * RAT[(x[1], x[2])] - 1 for x in vlist])
        print(f"[{fam}] señales {len(ra)}; sorteando referencia ({DRAWS})...", flush=True)
        refexp, means, short, kept = reference_mc(ra, rt, cal, NRst, DRAWS, SEED + (1 if fam == "B" else 2), PORT_DRAWS)
        meta[f"ref_shortfall_{fam}"] = int(short)
        for vi, x in enumerate(vlist):
            r = analyze_variant(vname(x), ra, rt, RAT[(x[1], x[2])], XBM[(x[1], x[2])], refexp[vi], means[vi], cal, o, c,
                                boots, ts, None, regimes)
            # referencia en cartera
            pr = [portfolio(sa, st, XBM[(x[1], x[2])][sa, st], RAT[(x[1], x[2])][sa, st], o, c, f) for sa, st in kept]
            r["reference_portfolio"] = {"ret_median": float(np.median([p["ret"] for p in pr])),
                                        "ret_range": [float(min(p["ret"] for p in pr)), float(max(p["ret"] for p in pr))],
                                        "dd_median": float(np.median([p["dd"] for p in pr])),
                                        "dd_range": [float(min(p["dd"] for p in pr)), float(max(p["dd"] for p in pr))]}
            all_res[x] = r
        # vecindario N descriptivo (48 h R1, referencia analítica)
        neigh = {}
        NR48 = f * RAT[(48, "R1")] - 1
        ae = analytic_refexp(NR48, cal, Aa)
        for N in [18, 21, 24, 27, 30]:
            na, nt = real_signals(fam, N, o, h, l, c, v, cal)
            net = NR48[na, nt]
            neigh[N] = {"n": int(len(na)), "expectancy": float(net.mean()), "excess_vs_analytic_ref": float((net - ae[cal.gid(na, nt)]).mean()),
                        "win_rate": float((net > 0).mean())}
        results[f"neighborhood_{fam}_48h_R1"] = neigh
        print(f"[{fam}] listo ({time.time()-t0:.0f}s)", flush=True)
    # Holm F1 (primarias) y F2 (resto)
    p1 = [all_res[x]["boot14d"]["p_excess_gt0"] for x in PRIMARY]
    adj1 = holm(p1)
    others = [x for x in VARIANTS if x not in PRIMARY]
    p2 = [all_res[x]["boot14d"]["p_excess_gt0"] for x in others]
    adj2 = holm(p2)
    for x, pa in zip(PRIMARY, adj1):
        all_res[x]["p_excess_holm_F1"] = pa
        decisions[vname(x)] = decide(all_res[x], pa)
    for x, pa in zip(others, adj2):
        all_res[x]["p_excess_holm_F2_exploratory"] = pa
    meta["finished"] = time.strftime("%Y-%m-%d %H:%M:%S")
    meta["elapsed_s"] = round(time.time() - t0)
    for x in VARIANTS:
        results[vname(x)] = all_res[x]
    json.dump(jsonable(meta), open(os.path.join(OUT, "run_meta.json"), "w"), indent=1)
    json.dump(jsonable(results), open(os.path.join(OUT, "variants.json"), "w"), indent=1)
    json.dump(jsonable(decisions), open(os.path.join(OUT, "decisions_primary.json"), "w"), indent=1)
    write_tables(all_res, decisions, results)
    print(json.dumps(jsonable(decisions), indent=1))


def write_tables(all_res, decisions, results):
    L = ["# Tablas E1 (generado por e1.py)\n"]
    L.append("| variante | tipo | n | win% | gan.media | pérd.media | expectancy neta | IC95% 14d | PF | exceso vs ref | p exceso (14d) | p Holm | DD cartera | ret cartera | racha pérd. |")
    L.append("|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|")
    for x in VARIANTS:
        r = all_res[x]; m = r["metrics"]; b = r["boot14d"]
        ph = r.get("p_excess_holm_F1", r.get("p_excess_holm_F2_exploratory"))
        L.append(f"| {vname(x)} | {'PRIMARIA' if x in PRIMARY else 'explor.'} | {m['n']} | {m['win_rate']*100:.1f} | {m['avg_win']*100:+.2f}% | {m['avg_loss']*100:+.2f}% | "
                 f"{m['expectancy']*100:+.3f}% | [{b['net_ci95'][0]*100:+.3f}; {b['net_ci95'][1]*100:+.3f}] | {m['pf']:.2f} | {r['excess_mean']*100:+.3f} pp | "
                 f"{b['p_excess_gt0']:.4f} | {ph:.4f} | {r['portfolio']['dd']*100:.1f}% | {r['portfolio']['ret']*100:+.1f}% | {m['max_loss_streak']} |")
    L.append("\n## Sensibilidad a costos (expectancy neta por operación)\n")
    L.append("| variante | 0,20% | 0,30% | 0,40% | 0,50% | costo de equilibrio |")
    L.append("|---|---|---|---|---|---|")
    for x in VARIANTS:
        s = all_res[x]["cost_sensitivity"]
        L.append(f"| {vname(x)} | " + " | ".join(f"{s[k]['expectancy']*100:+.3f}%" for k in ("0.20%", "0.30%", "0.40%", "0.50%")) +
                 f" | {all_res[x]['breakeven_round_trip_cost']*100:.3f}% |")
    L.append("\n## Primarias: por año, régimen, mitades, concentración, cartera\n")
    for x in PRIMARY:
        r = all_res[x]
        L.append(f"### {vname(x)}\n")
        L.append("año: " + "; ".join(f"{y}: n={d['n']}, exp={d['expectancy']*100:+.2f}%, exc={d['excess']*100:+.2f}pp" for y, d in r["per_year"].items()))
        L.append("\nrégimen: " + "; ".join(f"{k}: n={d['n']}, exp={d['expectancy']*100:+.2f}%, exc={d['excess']*100:+.2f}pp" for k, d in r["per_regime"].items()))
        L.append(f"\nmitades: {json.dumps(jsonable(r['halves']))}")
        L.append(f"\nconcentración: {json.dumps(jsonable(r['concentration']))}")
        L.append(f"\ncartera real: {json.dumps(jsonable(r['portfolio']))}; referencia (100 sorteos): {json.dumps(jsonable(r['reference_portfolio']))}")
        L.append("\nactivos: " + "; ".join(f"{k}: n={d['n']}, sum={d['sum']*100:+.1f}%" for k, d in r["per_asset"].items()))
        L.append(f"\nbootstrap 7d/28d: {json.dumps(jsonable({k: r[k] for k in ('boot7d','boot28d')}))}\n")
    L.append("\n## Vecindario de N (descriptivo, 48 h R1, referencia analítica)\n")
    for k, v in results.items():
        if k.startswith("neighborhood"):
            L.append(f"{k}: " + "; ".join(f"N={n}: n={d['n']}, exp={d['expectancy']*100:+.3f}%, exc={d['excess_vs_analytic_ref']*100:+.3f}pp" for n, d in v.items()) + "\n")
    L.append("\n## Decisiones (sección 9 del preregistro)\n")
    L.append("```\n" + json.dumps(jsonable(decisions), indent=1) + "\n```")
    open(os.path.join(OUT, "tables.md"), "w", encoding="utf-8").write("\n".join(L))


# ------------------------------------------------------------------ placebo
def placebo(n_pl=200, B=2000):
    ts, o, h, l, c, v = load_all()
    Aa, T = c.shape
    cal = Cal(ts)
    f = cfac(FEE, SLIP)
    R, XB = ratios(o, h, l, c, HZ[48], "R1")
    NR = f * R - 1
    ae = analytic_refexp(NR, cal, Aa)
    rng = np.random.default_rng(SEED + 99)
    out = {}
    elig = elig_mask(Aa, cal)
    for fam in ("B", "C"):
        sig = A.cooldown(A.signals(fam, N_PAR, o, h, l, c, v, {}))
        sig &= elig
        rej = cover = 0
        stats = []
        for p in range(n_pl):
            sh = int(rng.integers(60 * 6, T - 60 * 6))
            sp = np.roll(sig, sh, axis=1) & elig
            pa, pt = np.nonzero(sp)
            net = NR[pa, pt]
            ex = net - ae[cal.gid(pa, pt)]
            bid, nb = block_ids(ts, pt, cal.first, cal.last, 14)
            idx = boot_indices(nb, B, SEED + 1000 + p)
            se, nn = block_sums(bid, nb, ex)
            bs = boot_stat(idx, se, nn)
            pv = (1 + (bs <= 0).sum()) / (B + 1)
            rej += pv < 0.05
            lo, hi = np.percentile(bs, [2.5, 97.5])
            cover += (lo <= 0 <= hi)
            stats.append(float(ex.mean()))
        out[fam] = {"placebos": n_pl, "rate_p_lt_0.05": rej / n_pl, "ci95_covers_0": cover / n_pl,
                    "mean_placebo_excess": float(np.mean(stats)), "sd_placebo_excess": float(np.std(stats))}
        print(fam, out[fam], flush=True)
    os.makedirs(OUT, exist_ok=True)
    json.dump(out, open(os.path.join(OUT, "placebo.json"), "w"), indent=1)


# ------------------------------------------------------------------ autopruebas
def selftest():
    ok = True
    def chk(name, cond, info=""):
        nonlocal ok
        ok &= bool(cond)
        print(("OK   " if cond else "FALLA"), name, info, flush=True)
    # costos
    f = cfac(FEE, SLIP)
    chk("costo ida y vuelta base ~0,2998%", abs((1 - f) - 0.0029985) < 1e-5, f"{(1-f)*100:.4f}%")
    chk("sin costos neto = ratio-1", abs(cfac(0, 0) * 1.05 - 1 - 0.05) < 1e-15)
    # reconstrucción explícita (compra con comisión y deslizamiento, venta con ambas)
    cash, pe, pxs = 1000.0, 100.0, 105.0
    qty = cash * (1 - FEE) / (pe * (1 + SLIP))
    proceeds = qty * pxs * (1 - SLIP) * (1 - FEE)
    chk("neto explícito = fórmula", abs((proceeds / cash - 1) - (f * pxs / pe - 1)) < 1e-12)
    for X in BASE_COSTS:
        k, k2 = cost_pair(X)
        chk(f"escala de costo {X*100:.2f}%", abs(1 - cfac(k, k2) - X) < 1e-9, f"c={k:.6f} s={k2:.6f}")
    # stop: casos sintéticos [A=1, T=30]
    T = 30
    base = np.full((1, T), 100.0)
    def mk(lows=None, opens=None, closes=None):
        oo, hh, ll, cc = base.copy(), base.copy() * 1.001, base.copy() * 0.999, base.copy()
        if opens: [oo.__setitem__((0, i), x) for i, x in opens.items()]
        if lows: [ll.__setitem__((0, i), x) for i, x in lows.items()]
        if closes: [cc.__setitem__((0, i), x) for i, x in closes.items()]
        return oo, hh, ll, cc
    o_, h_, l_, c_ = mk()
    R, XB = ratios(o_, h_, l_, c_, 6, "R2")
    chk("stop: sin toque -> cierre del horizonte", abs(R[0, 0] - 1.0) < 1e-12 and XB[0, 0] == 6)
    o_, h_, l_, c_ = mk(lows={3: 90.0})
    R, XB = ratios(o_, h_, l_, c_, 6, "R2")
    chk("stop: toque intravela sale en S=92", abs(R[0, 0] - 0.92) < 1e-12 and XB[0, 0] == 3, f"{R[0,0]}")
    o_, h_, l_, c_ = mk(opens={3: 85.0}, lows={3: 84.0})
    R, XB = ratios(o_, h_, l_, c_, 6, "R2")
    chk("stop: hueco abre bajo S sale a la apertura", abs(R[0, 0] - 0.85) < 1e-12 and XB[0, 0] == 3, f"{R[0,0]}")
    o_, h_, l_, c_ = mk(lows={1: 90.0})
    R, XB = ratios(o_, h_, l_, c_, 6, "R2")
    chk("stop: toque en la vela de entrada cuenta", abs(R[0, 0] - 0.92) < 1e-12 and XB[0, 0] == 1)
    o_, h_, l_, c_ = mk(lows={6: 90.0}, closes={6: 95.0})
    R, XB = ratios(o_, h_, l_, c_, 6, "R2")
    chk("stop y salida por tiempo en la misma vela: gana el stop", abs(R[0, 0] - 0.92) < 1e-12 and XB[0, 0] == 6)
    # datos y señales
    ts, o, h, l, c, v = load_all()
    Aa, T = c.shape
    cal = Cal(ts)
    chk("20 activos, mismas marcas de tiempo", Aa == 20 and T == 10404, f"T={T}")
    chk("elegibles", cal.first == 800 and cal.last == T - 19, f"{np.datetime64(int(ts[cal.first]),'ms')} -> {np.datetime64(int(ts[cal.last]),'ms')}")
    # causalidad: alterar velas posteriores a t no cambia las señales hasta t
    cut = 6000
    o2, h2, l2, c2, v2 = (x.copy() for x in (o, h, l, c, v))
    rng = np.random.default_rng(1)
    for x in (o2, h2, l2, c2, v2):
        x[:, cut + 1:] = x[:, cut + 1:] * rng.uniform(0.5, 1.5, x[:, cut + 1:].shape)
    for fam in ("B", "C"):
        s1 = A.cooldown(A.signals(fam, N_PAR, o, h, l, c, v, {}))
        s2 = A.cooldown(A.signals(fam, N_PAR, o2, h2, l2, c2, v2, {}))
        chk(f"señales {fam} causales (alterar el futuro no cambia el pasado)", np.array_equal(s1[:, :cut + 1], s2[:, :cut + 1]))
        sg = real_signals(fam, N_PAR, o, h, l, c, v, cal)
        gap = min(np.diff(np.sort(sg[1][sg[0] == a])).min() for a in range(Aa) if (sg[0] == a).sum() > 1)
        chk(f"señales {fam}: cooldown >= {COOL} velas", gap >= COOL, f"mín {gap}; {len(sg[0])} señales ({len(sg[0])/((cal.last-cal.first)/6):.2f}/día)")
    # referencia: espaciado, k exacto, unicidad, esperanza vs analítica
    f = cfac(FEE, SLIP)
    R1, X1 = ratios(o, h, l, c, 12, "R1")
    NR = (f * R1 - 1)[None]
    ra, rt = real_signals("B", N_PAR, o, h, l, c, v, cal)
    refexp, means, short, kept = reference_mc(ra, rt, cal, NR, 200, 5, 5)
    sa, st = kept[0]
    okspace = True
    for a in np.unique(sa):
        for q in range(cal.NQ):
            m = (sa == a) & (cal.qid[st] == q)
            tt = np.sort(st[m])
            if len(tt) > 1 and np.diff(tt).min() < COOL:
                okspace = False
            if len(tt) != len(np.unique(tt)):
                okspace = False
    chk("referencia: sin duplicados y con espaciado", okspace)
    chk("referencia: k por celda igual a señales reales", len(sa) == len(ra) - short, f"déficit {short}")
    chk("referencia: barras elegibles", st.min() >= cal.first and st.max() <= cal.last)
    ae = analytic_refexp(NR[0], cal, Aa)
    cells = np.unique(cal.gid(ra, rt))
    d = np.abs(refexp[0, cells] - ae[cells])
    chk("esperanza con espaciado ~ media analítica (diferencia media)", d.mean() < 0.003, f"dif media {d.mean()*100:.3f} pp, máx {d.max()*100:.3f} pp")
    # cartera: caso trivial
    pr = portfolio(np.array([0]), np.array([900]), np.array([912]), R1[0, [900]], o, c, f)
    chk("cartera: 1 operación = 10 % del retorno neto", abs(pr["ret"] - 0.10 * (f * R1[0, 900] - 1)) < 1e-9, f"{pr['ret']:.6f}")
    print("TODO OK" if ok else "HAY FALLAS")
    return ok


if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else ""
    if cmd == "selftest":
        sys.exit(0 if selftest() else 1)
    elif cmd == "run":
        run()
    elif cmd == "placebo":
        placebo()
    else:
        print(__doc__)
