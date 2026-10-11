"""Oráculo lento: traducción literal del PHP con aritmética decimal truncada a 18 decimales (semántica bcmath).
Se usa SOLO para validar core.py (test_core.py). No se usa en el barrido."""
from decimal import Decimal as D, getcontext, ROUND_DOWN

getcontext().prec = 80
Q = D(1).scaleb(-18)


def trunc(x):
    return x.quantize(Q, rounding=ROUND_DOWN)


def bcmul(a, b):
    return trunc(a * b)


def bcdiv(a, b):
    return trunc(a / b)


def bcadd(a, b):
    return trunc(a + b)


def bcsub(a, b):
    return trunc(a - b)


def sma(closes, period, offset):
    end = len(closes) - offset
    w = closes[end - period:end]
    s = D(0)
    for c in w:
        s = bcadd(s, c)
    return bcdiv(s, D(period))


def emas(closes, period):
    m = bcdiv(D(2), D(period + 1))
    om = bcsub(D(1), m)
    out = [closes[0]]
    for i in range(1, len(closes)):
        out.append(bcadd(bcmul(closes[i], m), bcmul(out[i - 1], om)))
    return out


def signal(kind, s, l, closes):
    """Strategy::generate sobre el prefijo `closes`. 1 BUY, -1 SELL, 0 HOLD."""
    n = len(closes)
    if n < l + 1:
        return 0
    if kind == 'SMA':
        ps, pl = sma(closes, s, 1), sma(closes, l, 1)
        cs, cl = sma(closes, s, 0), sma(closes, l, 0)
    else:
        es, el = emas(closes, s), emas(closes, l)
        ps, pl, cs, cl = es[n - 2], el[n - 2], es[n - 1], el[n - 1]
    if cs > cl:
        return 1
    if ps >= pl and cs < cl:
        return -1
    return 0


def evaluate(kind, s, l, closes, capital):
    """StrategyEvaluator::evaluate (sin costes). closes: lista de Decimal. Devuelve dict de métricas."""
    cash = D(capital)
    init = cash
    pos = None
    pnls = []
    peak = init
    mdd = D(0)
    sigs = [signal(kind, s, l, closes[:i + 1]) for i in range(len(closes))]
    for i, c in enumerate(closes):
        sg = sigs[i]
        if sg == 1 and pos is None:
            q = bcdiv(bcmul(cash, bcsub(D(1), D(0))), c)
            pos = (q, c, cash)
            cash = D(0)
        elif sg == -1 and pos is not None:
            gross = bcmul(pos[0], c)
            net = bcsub(gross, bcmul(gross, D(0)))
            pnls.append(bcsub(net, pos[2]))
            cash = bcadd(cash, net)
            pos = None
        eq = cash if pos is None else bcadd(cash, bcmul(pos[0], c))
        if eq >= peak:
            peak = eq
            continue
        dd = bcmul(bcdiv(bcsub(peak, eq), peak), D(100))
        if dd > mdd:
            mdd = dd
    final = cash if pos is None else bcadd(cash, bcmul(pos[0], closes[-1]))
    n = len(pnls)
    wins = sum(1 for p in pnls if p > 0)
    pl = bcsub(final, init)
    return dict(ntr=n, wins=wins, plp=float(bcmul(bcdiv(pl, init), D(100))), dd=float(mdd), pl=pl,
                wr=(float(bcmul(bcdiv(D(wins), D(n)), D(100))) if n else 0.0))
