"""Núcleo numérico del experimento (réplica fiel del pipeline y del ciclo de trading del bot).

Réplica de:
  - StrategyCatalog::discoveryCandidates()  (SMA/EMA crossover, 10 cortos x 20 largos x 2 familias = 400)
  - StrategyEvaluator::simulate()           (cierre de vela, todo el efectivo, sin costes -> igual que producción)
  - StrategyDiscovery / StrategyPipeline::criteriaFailedAgainst (TRAIN y VALIDATION con umbrales independientes)
  - StrategySelector (dominancia de Pareto + desempate profit% desc, dd asc, WR desc, trades desc, nombre asc)
  - AutomaticTradingCycle::process()        (SELL con confirmación, stop-loss, salida por tiempo, BUY por estado)

Decisiones de fidelidad (ver REPORTE.md, sección 3):
  * SMA: sumas enteras exactas (precios x1e8) -> comparaciones S>L idénticas a bcmath (empates exactos incluidos).
  * EMA: float64 con tolerancia relativa 1e-13 para empates (plateaus de stablecoins), semilla = primer cierre de la ventana.
  * Operaciones "planas" (precio salida == precio entrada): en bcmath el cociente truncado hace que cash/p*p < cash
    salvo que p sea 2^a5^b-suave; se replica: plana+suave => pnl=0 (ni gana ni pierde), plana+no suave => pérdida mínima.
"""
import numpy as np
from numba import njit, prange

SHORTS = np.array([3, 4, 5, 6, 7, 8, 9, 10, 12, 15], dtype=np.int64)
LONGS = np.array([20, 25, 30, 35, 40, 45, 50, 55, 60, 70, 80, 90, 100, 110, 120, 130, 140, 150, 175, 200], dtype=np.int64)
PERIODS = np.array(sorted(set(SHORTS.tolist()) | set(LONGS.tolist())), dtype=np.int64)
NP_ = len(PERIODS)
NSTRAT = len(SHORTS) * len(LONGS) * 2   # 400

# índice de estrategia k = (si*NL + li)*2 + kind ; kind 0=SMA, 1=EMA  (mismo orden de inserción que PHP)
S_KIND = np.zeros(NSTRAT, np.int64)
S_SP = np.zeros(NSTRAT, np.int64)      # periodo corto
S_LP = np.zeros(NSTRAT, np.int64)      # periodo largo
S_SPI = np.zeros(NSTRAT, np.int64)     # índice del periodo corto en PERIODS
S_LPI = np.zeros(NSTRAT, np.int64)
NAMES = []
for si, s in enumerate(SHORTS):
    for li, l in enumerate(LONGS):
        for kind, fam in enumerate(("SMA", "EMA")):
            k = (si * len(LONGS) + li) * 2 + kind
            S_KIND[k], S_SP[k], S_LP[k] = kind, s, l
            S_SPI[k] = int(np.searchsorted(PERIODS, s))
            S_LPI[k] = int(np.searchsorted(PERIODS, l))
            NAMES.append(f"{fam} {s}/{l}")
# rango por strcmp ascendente del nombre (desempate final del selector)
_order = sorted(range(NSTRAT), key=lambda k: NAMES[k].encode())
NAME_RANK = np.zeros(NSTRAT, np.int64)
for r, k in enumerate(_order):
    NAME_RANK[k] = r

TOL = 1e-13
BAR_MS = 1_800_000
# config real (config/trading.php + .env): discovery / validation
CFG_DEFAULT = np.array([5, 30.0, 15.0, 0.0, 6, 60.0, 10.0, 0.0], dtype=np.float64)


@njit(cache=True)
def _smooth25(p):
    """True si p (entero) solo tiene factores 2 y 5."""
    if p <= 0:
        return False
    while p % 2 == 0:
        p //= 2
    while p % 5 == 0:
        p //= 5
    return p == 1


@njit(cache=True)
def _ma_tables(ci, cf, n, sums, emas):
    cs = np.zeros(n + 1, np.int64)
    for i in range(n):
        cs[i + 1] = cs[i] + ci[i]
    for pi in range(NP_):
        p = PERIODS[pi]
        for i in range(p - 1, n):
            sums[pi, i] = cs[i + 1] - cs[i + 1 - p]
        m = 2.0 / (p + 1)
        om = 1.0 - m
        emas[pi, 0] = cf[0]
        for i in range(1, n):
            emas[pi, i] = cf[i] * m + emas[pi, i - 1] * om


@njit(cache=True)
def _sim_strategy(k, ci, cf, n, sums, emas, res):
    """StrategyEvaluator::simulate para la estrategia k sobre la ventana (ci, cf). res: 7 floats."""
    kind = S_KIND[k]
    sp = S_SP[k]
    lp = S_LP[k]
    spi = S_SPI[k]
    lpi = S_LPI[k]
    cash = 1.0
    units = 0.0
    inpos = False
    entry_i = 0
    entry_cash = 1.0
    peak = 1.0
    mdd = 0.0
    ntr = 0
    wins = 0
    tp = 0.0
    tl = 0.0
    for i in range(lp, n):
        if kind == 0:
            a = sums[spi, i] * lp
            b = sums[lpi, i] * sp
            above = a > b
            crossed = False
            if not above:
                a0 = sums[spi, i - 1] * lp
                b0 = sums[lpi, i - 1] * sp
                crossed = (a0 >= b0) and (a < b)
        else:
            s_ = emas[spi, i]
            l_ = emas[lpi, i]
            tol = TOL * abs(l_)
            above = (s_ - l_) > tol
            crossed = False
            if not above:
                s0 = emas[spi, i - 1]
                l0 = emas[lpi, i - 1]
                crossed = ((s0 - l0) >= -TOL * abs(l0)) and ((l_ - s_) > tol)
        if above and not inpos:
            units = cash / cf[i]
            entry_cash = cash
            cash = 0.0
            inpos = True
            entry_i = i
        elif (not above) and crossed and inpos:
            proceeds = units * cf[i]
            if ci[i] == ci[entry_i]:
                # operación plana: replica el truncado de bcmath
                if _smooth25(ci[i]):
                    proceeds = entry_cash
                else:
                    proceeds = entry_cash * (1.0 - 1e-15)
            pnl = proceeds - entry_cash
            ntr += 1
            if pnl > 0:
                wins += 1
                tp += pnl
            elif pnl < 0:
                tl -= pnl
            cash = proceeds
            units = 0.0
            inpos = False
        eq = cash + units * cf[i] if inpos else cash
        if eq >= peak:
            peak = eq
        else:
            dd = (peak - eq) / peak * 100.0
            if dd > mdd:
                mdd = dd
    final = cash + units * cf[n - 1] if inpos else cash
    res[0] = ntr
    res[1] = wins
    res[2] = (final - 1.0) * 100.0
    res[3] = mdd
    res[4] = 1.0 if inpos else 0.0
    res[5] = tp
    res[6] = tl


@njit(cache=True)
def eval_window(ci, cf, res):
    """res[NSTRAT,7] = ntr, wins, plp, dd, open, totalProfit, totalLoss  para las 400 estrategias."""
    n = len(cf)
    sums = np.zeros((NP_, n), np.int64)
    emas = np.zeros((NP_, n), np.float64)
    _ma_tables(ci, cf, n, sums, emas)
    for k in range(NSTRAT):
        _sim_strategy(k, ci, cf, n, sums, emas, res[k])


@njit(cache=True)
def _passes(r, cfg, off):
    ntr = r[0]
    wins = r[1]
    return (ntr >= cfg[off]) and (wins * 100.0 >= cfg[off + 1] * ntr) and (r[3] <= cfg[off + 2]) and (r[2] >= cfg[off + 3])


@njit(cache=True)
def _pareto_select(surv, nsurv, vres, front):
    """Devuelve índice elegido; rellena front[k]=1 para los no dominados."""
    for a in range(nsurv):
        ka = surv[a]
        dominated = False
        for b in range(nsurv):
            if a == b:
                continue
            kb = surv[b]
            # ¿kb domina a ka?
            pc = vres[kb, 2] - vres[ka, 2]
            dc = vres[kb, 3] - vres[ka, 3]
            wr = vres[kb, 1] * vres[ka, 0] - vres[ka, 1] * vres[kb, 0]   # wrB>=wrA <=> wb*na - wa*nb
            tc = vres[kb, 0] - vres[ka, 0]
            if pc >= 0 and dc <= 0 and wr >= 0 and tc >= 0:
                if pc > 0 or dc < 0 or wr > 0 or tc > 0:
                    dominated = True
                    break
        if not dominated:
            front[ka] = 1
    best = -1
    for a in range(nsurv):
        ka = surv[a]
        if front[ka] == 0:
            continue
        if best < 0:
            best = ka
            continue
        # ¿ka precede a best en el desempate?
        better = False
        d = vres[ka, 2] - vres[best, 2]
        if d != 0:
            better = d > 0
        else:
            d = vres[ka, 3] - vres[best, 3]
            if d != 0:
                better = d < 0
            else:
                d = vres[ka, 1] * vres[best, 0] - vres[best, 1] * vres[ka, 0]
                if d != 0:
                    better = d > 0
                else:
                    d = vres[ka, 0] - vres[best, 0]
                    if d != 0:
                        better = d > 0
                    else:
                        better = NAME_RANK[ka] < NAME_RANK[best]
        if better:
            best = ka
    return best


@njit(cache=True)
def pipeline_one(ci, cf, cfg, flags, selm):
    """Una ejecución completa del pipeline sobre la ventana (ci, cf).
    flags[400]: bit0 pasa Discovery, bit1 pasa Validation, bit2 en frente de Pareto.
    selm[9]: sel_idx, train(ntr, wr, plp, dd), val(ntr, wr, plp, dd). Devuelve idx o -1."""
    n = len(cf)
    cut = max(1, min(n * 50 // 100, n - 1))
    tres = np.zeros((NSTRAT, 7))
    vres = np.zeros((NSTRAT, 7))
    eval_window(ci[:cut], cf[:cut], tres)
    disc = np.zeros(NSTRAT, np.int64)
    nd = 0
    for k in range(NSTRAT):
        if _passes(tres[k], cfg, 0):
            disc[nd] = k
            nd += 1
            flags[k] |= 1
    surv = np.zeros(NSTRAT, np.int64)
    nsurv = 0
    if nd > 0:
        eval_window(ci[cut:], cf[cut:], vres)
        for j in range(nd):
            k = disc[j]
            if _passes(vres[k], cfg, 4):
                surv[nsurv] = k
                nsurv += 1
                flags[k] |= 2
    front = np.zeros(NSTRAT, np.int64)
    sel = -1
    if nsurv > 0:
        sel = _pareto_select(surv, nsurv, vres, front)
        for j in range(nsurv):
            if front[surv[j]]:
                flags[surv[j]] |= 4
        selm[0] = sel
        selm[1] = tres[sel, 0]
        selm[2] = 100.0 * tres[sel, 1] / tres[sel, 0]
        selm[3] = tres[sel, 2]
        selm[4] = tres[sel, 3]
        selm[5] = vres[sel, 0]
        selm[6] = 100.0 * vres[sel, 1] / vres[sel, 0]
        selm[7] = vres[sel, 2]
        selm[8] = vres[sel, 3]
    return sel


@njit(parallel=True, cache=True)
def sweep(ci, cf, lo, hi, cfg, flags, selm):
    """Pipeline para cada ventana [lo[e], hi[e]) de la serie global. flags[E,400] uint8; selm[E,9] float64."""
    E = len(lo)
    for e in prange(E):
        if lo[e] < 0:
            selm[e, 0] = -2.0     # sin ventana válida
            continue
        f = np.zeros(NSTRAT, np.uint8)
        m = np.zeros(9)
        s = pipeline_one(ci[lo[e]:hi[e]], cf[lo[e]:hi[e]], cfg, f, m)
        flags[e, :] = f
        selm[e, :] = m
        selm[e, 0] = s


# --------------------------------------------------------------------------------------------
# Ciclo de trading (AutomaticTradingCycle) a partir de un instante de búsqueda
# --------------------------------------------------------------------------------------------
HOLD_TICKS = 16        # hold_timeout_hours=8 -> el ciclo expira en el tick 16 (sin BUY posible)
MAX_HOLD_TICKS = 16    # risk_exit.max_holding_hours=8 -> salida por tiempo en el 16º tick tras la entrada
LIVE_WINDOW_MS = 99 * 3600 * 1000   # 200 velas pedidas (now-200*30m) -> 199 cerradas con open>=from
STOP_PCT = 3.0

R_SELL, R_STOP, R_TIME = 1, 2, 3


@njit(cache=True)
def _live_signal(t, cs, cf, i, kind, sp, lp):
    """Señal de Strategy::generate() sobre la ventana viva de 199 velas que termina en i. 1 BUY, -1 SELL, 0 HOLD."""
    j = i
    tmin = t[i] - LIVE_WINDOW_MS
    while j > 0 and t[j - 1] >= tmin:
        j -= 1
    m = i - j + 1
    if m < lp + 1:
        return 0
    if kind == 0:
        a = (cs[i + 1] - cs[i + 1 - sp]) * lp
        b = (cs[i + 1] - cs[i + 1 - lp]) * sp
        if a > b:
            return 1
        a0 = (cs[i] - cs[i - sp]) * lp
        b0 = (cs[i] - cs[i - lp]) * sp
        if a0 >= b0 and a < b:
            return -1
        return 0
    ms = 2.0 / (sp + 1)
    ml = 2.0 / (lp + 1)
    es = cf[j]
    el = cf[j]
    es0 = es
    el0 = el
    for x in range(j + 1, i + 1):
        es0 = es
        el0 = el
        es = cf[x] * ms + es * (1.0 - ms)
        el = cf[x] * ml + el * (1.0 - ml)
    tol = TOL * abs(el)
    if (es - el) > tol:
        return 1
    if ((es0 - el0) >= -TOL * abs(el0)) and ((el - es) > tol):
        return -1
    return 0


@njit(cache=True)
def make_prefix(ci):
    cs = np.zeros(len(ci) + 1, np.int64)
    for i in range(len(ci)):
        cs[i + 1] = cs[i] + ci[i]
    return cs


@njit(cache=True)
def cycle_sim(t, cs, cf, k0, k, out):
    """Simula un ciclo activado en el instante de búsqueda cuya última vela cerrada es k0, con la estrategia k.
    out: [status, entry_idx, exit_idx, reason, entry_price, exit_price, end_idx]
      status: 0 INVÁLIDO (datos insuficientes/huecos), 1 expiró sin entrar, 2 operación cerrada."""
    kind = S_KIND[k]
    sp = S_SP[k]
    lp = S_LP[k]
    n_data = len(cf)
    out[:] = 0.0
    if k0 < 0 or k0 + 2 * HOLD_TICKS >= n_data:
        return
    inpos = False
    pending = False
    entry_idx = -1
    entry_n = -1
    for n in range(0, 2 * HOLD_TICKS + 1):
        i = k0 + n
        if t[i] != t[k0] + n * BAR_MS:
            out[0] = 0.0     # hueco de datos en el horizonte
            return
        if not inpos and n >= HOLD_TICKS:
            out[0] = 1.0
            out[6] = i
            return
        sig = _live_signal(t, cs, cf, i, kind, sp, lp)
        price = cf[i]
        if pending:
            if sig != 1:
                out[0] = 2.0
                out[1] = entry_idx
                out[2] = i
                out[3] = R_SELL
                out[4] = cf[entry_idx]
                out[5] = price
                out[6] = i
                return
            pending = False
        if sig == -1 and inpos:
            pending = True
        if inpos:
            loss = (cf[entry_idx] - price) / cf[entry_idx] * 100.0
            if loss >= STOP_PCT:
                out[0] = 2.0
                out[1] = entry_idx
                out[2] = i
                out[3] = R_STOP
                out[4] = cf[entry_idx]
                out[5] = price
                out[6] = i
                return
            if n - entry_n >= MAX_HOLD_TICKS:
                out[0] = 2.0
                out[1] = entry_idx
                out[2] = i
                out[3] = R_TIME
                out[4] = cf[entry_idx]
                out[5] = price
                out[6] = i
                return
        if sig == 1 and not inpos:
            inpos = True
            entry_idx = i
            entry_n = n
    return


def to_int_prices(c):
    return np.rint(np.asarray(c, dtype=np.float64) * 1e8).astype(np.int64)


def net_return(entry, exit_, fee, slip):
    """Retorno neto (fracción) sobre el capital del slot: compra a entry*(1+slip), venta a exit*(1-slip), comisión en ambos lados."""
    return (1 - fee) ** 2 * (exit_ * (1 - slip)) / (entry * (1 + slip)) - 1.0
