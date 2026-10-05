"""Auditoría offline de familias de señales. Ver PROTOCOLO.md (pre-registrado).

Uso:
  python audit.py dev  <data_dir> <out_dir>   # TRAIN+VAL (no lee el TEST)
  python audit.py test <data_dir> <out_dir>   # una sola vez, con frozen.json
"""
import sys, json, pathlib, math
import numpy as np

SYMS = "BTC ETH BNB XRP SOL ADA DOGE TRX LINK LTC AVAX DOT ETC BCH ATOM NEAR XLM UNI AAVE IOTA".split()
HOR = [1, 2, 4, 8, 16, 24]
PH = 8
COST = 0.002
COOL = 24
WARM = 800
EMB = 24
GRIDS = {"A": [10, 12, 14, 16, 18], "B": [18, 21, 24, 27, 30], "C": [18, 21, 24, 27, 30],
         "D": [18, 21, 24, 27, 30], "E": [160, 180, 200, 220, 240]}
PRIMARY = {"A": 14, "B": 24, "C": 24, "D": 24, "E": 200}
NAMES = {"A": "Retroceso en tendencia", "B": "Ruptura", "C": "Expansión de volatilidad",
         "D": "Momentum cross-sectional", "E": "Régimen + entrada"}


# ---------------------------------------------------------------- datos
def load(d):
    arr = [np.load(pathlib.Path(d) / f"{s}.npy") for s in SYMS]
    T = min(len(a) for a in arr)
    ts = arr[0][:T, 0]
    for a in arr:
        assert np.array_equal(a[:T, 0], ts), "índices temporales distintos"
    o, h, l, c, v = (np.stack([a[:T, i] for a in arr]) for i in (1, 2, 3, 4, 5))
    return ts, o, h, l, c, v


def splits(T):
    lo, hi = WARM, T - 25
    M = hi - lo
    b1, b2 = lo + int(M * .70), lo + int(M * .85)
    return {"TRAIN": (lo, b1 - EMB), "VAL": (b1, b2 - EMB), "TEST": (b2, hi)}, (b1, b2)


def sma(x, n):
    cs = np.cumsum(np.insert(x, 0, 0.0, axis=-1), axis=-1)
    out = np.full(x.shape, np.nan)
    out[..., n - 1:] = (cs[..., n:] - cs[..., :-n]) / n
    return out


def rsi(c, n):
    out = np.full(c.shape, np.nan)
    for a in range(c.shape[0]):
        d = np.diff(c[a]); up = np.maximum(d, 0); dn = np.maximum(-d, 0)
        au, ad = up[:n].mean(), dn[:n].mean()
        r = np.full(len(c[a]), np.nan)
        for i in range(n, len(d)):
            au = (au * (n - 1) + up[i]) / n; ad = (ad * (n - 1) + dn[i]) / n
            r[i + 1] = 100.0 if ad == 0 else 100 - 100 / (1 + au / ad)
        out[a] = r
    return out


def roll_max_prev(x, n):
    """máximo de x[t-n..t-1]"""
    from numpy.lib.stride_tricks import sliding_window_view as sw
    out = np.full(x.shape, np.nan)
    w = sw(x, n, axis=-1).max(axis=-1)  # w[..., i] = max x[i..i+n-1]
    out[..., n:] = w[..., :-1]
    return out


def cooldown(sig):
    out = np.zeros_like(sig)
    for a in range(sig.shape[0]):
        last = -10 ** 9
        for t in np.flatnonzero(sig[a]):
            if t - last >= COOL:
                out[a, t] = True; last = t
    return out


# ---------------------------------------------------------------- señales
def signals(fam, N, o, h, l, c, v, extra):
    A, T = c.shape
    if fam == "A":
        s168 = extra["s168"]
        up = (c > s168) & (s168 > np.roll(s168, 24, axis=1))
        r = rsi(c, N)
        sig = up & (r >= 35) & (np.roll(r, 1, axis=1) < 35)
    elif fam == "B":
        sig = (c > roll_max_prev(h, N)) & ~(np.roll(c, 1, axis=1) > np.roll(roll_max_prev(h, N), 1, axis=1))
    elif fam == "C":
        tr = np.maximum(h - l, np.maximum(abs(h - np.roll(c, 1, axis=1)), abs(l - np.roll(c, 1, axis=1))))
        base = np.roll(sma(tr, N), 1, axis=1)
        sig = (tr > 2 * base) & (c > o)
    elif fam == "D":
        roc = c / np.roll(c, N, axis=1) - 1
        rank = (-roc).argsort(axis=0).argsort(axis=0)  # 0 = mejor
        top = (rank < 3) & (roc > 0)
        sig = top & ~np.roll(top, 1, axis=1)
    elif fam == "E":
        reg = regime(N, c, extra)
        vol = (v > 1.5 * np.roll(sma(v, 24), 1, axis=1)) & (c > o)
        sig = reg[None, :] & vol
    sig = np.nan_to_num(sig, nan=0).astype(bool)
    sig[:, :WARM] = False
    return sig


def regime(N, c, extra):
    btc = c[0]
    ok = btc > sma(btc, N)
    ok &= extra["btcvol"] <= extra["btcvolmed"]
    return np.nan_to_num(ok, nan=0).astype(bool)


def prep(o, h, l, c, v):
    from numpy.lib.stride_tricks import sliding_window_view as sw
    lr = np.diff(np.log(c[0]), prepend=np.nan)
    vol = np.full(len(lr), np.nan)
    # std 24h de retornos 1h de BTC
    w = sw(lr[1:], 24).std(axis=-1)  # w[i] cubre lr[1+i..24+i]
    vol[24:] = w
    med = np.full(len(lr), np.nan)
    ww = sw(vol[24:], 720)
    med[24 + 719:] = np.median(ww, axis=-1)
    return {"s168": sma(c, 168), "btcvol": vol, "btcvolmed": med}


# ---------------------------------------------------------------- métricas
def fwd(o, h, l, c, hz):
    """retorno bruto de entrar en open[t+1] y salir en close[t+hz], matriz [A,T] (NaN fuera)."""
    A, T = c.shape
    r = np.full((A, T), np.nan)
    r[:, : T - hz - 0] = c[:, hz:] / np.roll(o, -1, axis=1)[:, : T - hz] - 1
    return r


def mae_mfe(o, h, l, hz, a, t):
    from numpy.lib.stride_tricks import sliding_window_view as sw
    entry = o[a, t + 1]
    mae = np.empty(len(t)); mfe = np.empty(len(t))
    for i, (aa, tt) in enumerate(zip(a, t)):
        lo = l[aa, tt + 1: tt + 1 + hz].min(); hi = h[aa, tt + 1: tt + 1 + hz].max()
        mae[i] = lo / entry[i] - 1; mfe[i] = hi / entry[i] - 1
    return mae, mfe


def pf(x):
    g = x[x > 0].sum(); ls = -x[x < 0].sum()
    return float("inf") if ls == 0 and g > 0 else (g / ls if ls > 0 else float("nan"))


class Ctx:
    def __init__(self, ts, o, h, l, c, v, spl):
        self.ts, self.o, self.h, self.l, self.c, self.v = ts, o, h, l, c, v
        self.spl = spl
        A, T = c.shape
        self.R = {hz: fwd(o, h, l, c, hz) for hz in HOR}
        self.mkt = {hz: np.nanmean(self.R[hz], axis=0) for hz in HOR}
        # grupos (activo, mes, tramo) para referencia C
        dt = ts.astype("datetime64[ms]")
        month = dt.astype("datetime64[M]").astype(int)
        self.month = month
        self.elig = np.zeros((A, T), bool)
        self.split_of = np.full(T, "", dtype=object)
        for k, (s, e) in spl.items():
            self.elig[:, s:e] = True
            self.split_of[s:e] = k
        self.gid = np.full((A, T), -1)
        key = np.zeros(T, dtype=np.int64)
        for j, k in enumerate(spl):
            s, e = spl[k]
            key[s:e] = j * 100000 + (month[s:e] - month.min())
        self.key = key
        uk = {u: i for i, u in enumerate(np.unique(key[self.elig[0]]))}
        for a in range(A):
            for t in np.flatnonzero(self.elig[0]):
                self.gid[a, t] = a * len(uk) + uk[key[t]]
        self.ng = A * len(uk)
        ge = self.gid[self.elig]
        self.N_g = np.bincount(ge, minlength=self.ng).astype(float)
        self.mu, self.var = {}, {}
        for hz in HOR:
            x = self.R[hz][self.elig]
            m = ~np.isnan(x)
            n = np.bincount(ge[m], minlength=self.ng).astype(float)
            s1 = np.bincount(ge[m], weights=x[m], minlength=self.ng)
            s2 = np.bincount(ge[m], weights=x[m] ** 2, minlength=self.ng)
            mu = np.divide(s1, n, out=np.zeros_like(s1), where=n > 0)
            self.mu[hz] = mu
            self.var[hz] = np.maximum(np.divide(s2, n, out=np.zeros_like(s2), where=n > 0) - mu ** 2, 0)
        self.regime_lbl = self._regimes()

    def _regimes(self):
        btc = self.c[0]
        ret = np.full(len(btc), np.nan)
        ret[720:] = btc[720:] / btc[:-720] - 1
        lab = np.where(ret > .10, "alcista", np.where(ret < -.10, "bajista", "lateral"))
        lab = np.where(np.isnan(ret), "lateral", lab)
        return lab

    def quarter(self, t):
        d = self.ts[t].astype("datetime64[ms]")
        y = d.astype("datetime64[Y]").astype(int) + 1970
        m = d.astype("datetime64[M]").astype(int) % 12
        return [f"{a}Q{b // 3 + 1}" for a, b in zip(y, m)]


def analyze(ctx, sig, which, label=None):
    """which: lista de tramos a incluir (p.ej. ['TRAIN','VAL'])."""
    msk = np.zeros_like(sig)
    for k in which:
        s, e = ctx.spl[k]
        msk[:, s:e] = True
    sg = sig & msk & ctx.elig
    a, t = np.nonzero(sg)
    out = {"n": int(len(a))}
    if len(a) == 0:
        return out
    gi = ctx.gid[a, t]
    eligm = np.zeros_like(sig)
    for k in which:
        s, e = ctx.spl[k]
        eligm[:, s:e] = True
    eligm &= ctx.elig
    days = (np.nonzero(eligm[0])[0])
    ndays = len(days) / 24
    out["por_dia_universo"] = len(a) / ndays
    out["por_activo_dia"] = len(a) / ndays / len(SYMS)
    out["por_semana"] = out["por_dia_universo"] * 7
    out["por_mes"] = out["por_dia_universo"] * 30.4375
    hz_res = {}
    for hz in HOR:
        r = ctx.R[hz][a, t]
        ok = ~np.isnan(r)
        r = r[ok]; gii = gi[ok]
        refA = np.nanmean(ctx.R[hz][eligm])
        refAmed = np.nanmedian(ctx.R[hz][eligm])
        refB = float(np.mean(ctx.mkt[hz][t][ok]))
        exp_i = ctx.mu[hz][gii]
        excC = float(np.mean(r - exp_i))
        # varianza exacta de la media bajo muestreo aleatorio emparejado
        k = np.bincount(gii, minlength=ctx.ng).astype(float)
        N = np.maximum(ctx.N_g, 2)
        v_sum = k * ctx.var[hz] * np.maximum(N - k, 0) / (N - 1)
        K = k.sum()
        se = math.sqrt(v_sum.sum()) / K
        z = excC / se if se > 0 else 0.0
        p = 0.5 * math.erfc(z / math.sqrt(2))
        hz_res[hz] = {"n": int(len(r)), "bruto_medio": float(r.mean()), "bruto_mediana": float(np.median(r)),
                      "neto_medio": float(r.mean() - COST), "refA": float(refA), "refB": refB,
                      "ref_C_esperado": float(exp_i.mean()), "exceso_A": float(r.mean() - refA),
                      "exceso_B": float(r.mean() - refB), "exceso_C": excC, "z_C": float(z), "p_C": float(p)}
    out["horizontes"] = hz_res
    # --- detalle en horizonte primario
    r = ctx.R[PH][a, t]; ok = ~np.isnan(r)
    a2, t2, r = a[ok], t[ok], r[ok]
    net = r - COST
    exc = r - ctx.mu[PH][ctx.gid[a2, t2]]
    mae, mfe = mae_mfe(ctx.o, ctx.h, ctx.l, PH, a2, t2)
    d = {}
    d["win_rate_neto"] = float((net > 0).mean()); d["win_rate_bruto"] = float((r > 0).mean())
    d["pf_neto"] = pf(net); d["pf_bruto"] = pf(r)
    d["media_neta"] = float(net.mean()); d["mediana_neta"] = float(np.median(net))
    d["p5_neto"] = float(np.percentile(net, 5)); d["p10_neto"] = float(np.percentile(net, 10))
    d["p90_neto"] = float(np.percentile(net, 90))
    d["peor"] = float(net.min()); d["mejor"] = float(net.max())
    d["perd_media"] = float(net[net < 0].mean()) if (net < 0).any() else 0.0
    d["perd_mediana"] = float(np.median(net[net < 0])) if (net < 0).any() else 0.0
    d["frec_perdida_grande(<-2%)"] = float((net < -.02).mean())
    d["frec_ganancia_pequena(0,1%]"] = float(((net > 0) & (net <= .01)).mean())
    d["MAE_medio"] = float(mae.mean()); d["MAE_mediana"] = float(np.median(mae)); d["MAE_p5"] = float(np.percentile(mae, 5))
    d["MFE_medio"] = float(mfe.mean()); d["MFE_mediana"] = float(np.median(mfe))
    # series diarias (día de entrada, UTC)
    day = ((ctx.ts[t2 + 1] // 86400000)).astype(int)
    ud, inv = np.unique(day, return_inverse=True)
    dsum = np.bincount(inv, weights=net); dcnt = np.bincount(inv)
    dret = dsum / dcnt
    # equity: cada señal usa 1/max(10, señales del día) del capital (convención Enmienda 3)
    def ddmax(sum_by_day):
        eq = np.cumprod(1 + sum_by_day / np.maximum(10, dcnt)); peak = np.maximum.accumulate(np.insert(eq, 0, 1.0))[1:]
        return float(((eq - peak) / peak).min()), float(eq[-1] - 1)
    d["dd_max_diario"], d["retorno_total_neto_slots"] = ddmax(dsum)
    d["dd_max_diario_bruto"], d["retorno_total_bruto_slots"] = ddmax(np.bincount(inv, weights=r))
    d["pct_dias_pos_con_senal"] = float((dret > 0).mean())
    d["dias_con_senal"] = int(len(ud))
    all_days = len(np.unique(ctx.ts[np.nonzero(eligm[0])[0]] // 86400000))
    d["pct_dias_pos_calendario"] = float((dret > 0).sum() / all_days)
    d["peor_dia"] = float(dret.min()); d["mejor_dia"] = float(dret.max())
    # racha de días negativos consecutivos (días con señal; un día calendario sin señal rompe la racha)
    best = cur = 0; prev = None
    for dd, x in zip(ud, dret):
        if x < 0:
            cur = cur + 1 if (prev is not None and dd - prev == 1 and cur > 0) else 1
        else:
            cur = 0
        best = max(best, cur); prev = dd
    d["racha_neg_max_dias"] = int(best)
    out["detalle_8h"] = d
    # --- desgloses por exceso vs C (bruto) y neto medio
    def grp(keys):
        res = {}
        for kk in sorted(set(keys)):
            m = np.array([x == kk for x in keys])
            res[kk] = {"n": int(m.sum()), "exceso_C": float(exc[m].mean()), "neto_medio": float(net[m].mean()),
                       "win_neto": float((net[m] > 0).mean()), "suma_exceso": float(exc[m].sum())}
        return res
    out["por_activo"] = grp([SYMS[x] for x in a2])
    out["por_trimestre"] = grp(ctx.quarter(t2))
    out["por_regimen"] = grp(list(ctx.regime_lbl[t2]))
    out["por_tramo"] = grp(list(ctx.split_of[t2]))
    return out


def reference_stats(ctx, which):
    eligm = np.zeros_like(ctx.elig)
    for k in which:
        s, e = ctx.spl[k]; eligm[:, s:e] = True
    eligm &= ctx.elig
    x = ctx.R[PH][eligm]; x = x[~np.isnan(x)]
    net = x - COST
    return {"n": int(len(x)), "bruto_medio": float(x.mean()), "neto_medio": float(net.mean()),
            "win_neto": float((net > 0).mean()), "pf_neto": pf(net), "p5_neto": float(np.percentile(net, 5)),
            "mediana_bruta": float(np.median(x))}


def run(stage, data_dir, out_dir):
    ts, o, h, l, c, v = load(data_dir)
    T_full = len(ts)
    spl_full, (b1, b2) = splits(T_full)
    if stage == "dev":
        # el TEST no se carga: se trunca al fin de VALIDATION
        T = b2
        ts, o, h, l, c, v = ts[:T], o[:, :T], h[:, :T], l[:, :T], c[:, :T], v[:, :T]
        spl = {k: spl_full[k] for k in ("TRAIN", "VAL")}
        which_all = ["TRAIN", "VAL"]
    else:
        T = T_full
        spl = spl_full
        which_all = ["TEST"]
    extra = prep(o, h, l, c, v)
    ctx = Ctx(ts, o, h, l, c, v, spl)
    res = {"meta": {"stage": stage, "T": int(T), "inicio": str(ts[0].astype("datetime64[ms]")),
                    "fin": str(ts[-1].astype("datetime64[ms]")),
                    "tramos": {k: [str(ts[s].astype("datetime64[ms]")), str(ts[min(e, T) - 1].astype("datetime64[ms]"))]
                               for k, (s, e) in spl.items()}}}
    res["referencia_A"] = {k: reference_stats(ctx, [k]) for k in spl}
    res["referencia_A"]["+".join(which_all)] = reference_stats(ctx, which_all)
    res["familias"] = {}
    for fam, grid in GRIDS.items():
        fr = {}
        for N in grid:
            sig = cooldown(signals(fam, N, o, h, l, c, v, extra))
            cell = {}
            if stage == "dev":
                cell["TRAIN+VAL"] = analyze(ctx, sig, ["TRAIN", "VAL"])
                cell["TRAIN"] = analyze(ctx, sig, ["TRAIN"])
                cell["VAL"] = analyze(ctx, sig, ["VAL"])
            else:
                cell["TEST"] = analyze(ctx, sig, ["TEST"])
            fr[str(N)] = cell
            print(fam, N, {k: v_["n"] for k, v_ in cell.items()}, flush=True)
        res["familias"][fam] = fr
    if True:  # régimen solo (referencia de E)
        N = PRIMARY["E"]
        reg = regime(N, c, extra)
        sig = np.repeat(reg[None, :], len(SYMS), axis=0); sig[:, :WARM] = False
        rs = {}
        for nm, wh in ([("TRAIN+VAL", ["TRAIN", "VAL"])] if stage == "dev" else [("TEST", ["TEST"])]):
            a_ = analyze(ctx, cooldown(sig), wh)
            rs[nm] = {"n": a_["n"], "horizontes": a_.get("horizontes")}
        res["E_regimen_solo"] = rs
    od = pathlib.Path(out_dir); od.mkdir(parents=True, exist_ok=True)
    (od / f"resultados_{stage}.json").write_text(json.dumps(res, indent=1, default=float))
    print("OK", stage)


if __name__ == "__main__":
    run(*sys.argv[1:4])
