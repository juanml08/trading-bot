"""Valida core.cycle_sim contra una traducción literal de AutomaticTradingCycle::process() (estado entre ticks incluido),
usando ventanas vivas de 199 velas y señales del oráculo decimal."""
import os, sys, time
import numpy as np
from decimal import Decimal as D
import core, reference as ref

rng = np.random.default_rng(777)
syms = [s for s in ["BTCUSDT", "HOTUSDT", "USDCUSDT", "TUSDUSDT", "IOSTUSDT", "FETUSDT", "ETHUSDT", "CELRUSDT"] if os.path.exists(f"data/{s}.npy")]


def literal_cycle(t, ci, k0, kind, s, l):
    """Traducción literal: ticks cada vela; estado: open_trade, pending_sell(ts), cycle HOLD/POSITION_OPEN."""
    def window(i):
        j = i
        while j > 0 and t[j - 1] >= t[i] - 99 * 3600 * 1000:
            j -= 1
        return j
    def closes_upto(j, i):
        return [D(int(v)).scaleb(-8) for v in ci[j:i + 1]]
    open_trade = None   # (entry_idx, entry_tick)
    pending = None      # índice absoluto de la vela del cruce
    for n in range(0, 33):
        i = k0 + n
        if open_trade is None and n >= 16:
            return ("expired", None)
        j = window(i)
        cl = closes_upto(j, i)
        signal = ref.signal(kind, s, l, cl)
        price = D(int(ci[i])).scaleb(-8)
        if pending is not None:
            # resolvePendingSell
            if open_trade is None:
                pending = None
            else:
                cross_local = pending - j
                if cross_local < 0:
                    pending = None          # crossover_candle_not_available
                elif cross_local + 1 < len(cl):
                    conf = ref.signal(kind, s, l, cl[:cross_local + 2])
                    if conf == 1:
                        pending = None      # recovered
                    else:
                        return ("sell", (open_trade[0], i))
        if signal == -1:
            if open_trade is None:
                continue                    # handleSell ignorado, return
            if pending != i:
                pending = i
        if open_trade is not None:
            entry = D(int(ci[open_trade[0]])).scaleb(-8)
            loss = bcdiv_pct(entry, price)
            if loss >= D("3"):
                return ("stop", (open_trade[0], i))
            if n - open_trade[1] >= 16:
                return ("time", (open_trade[0], i))
        if signal == 1 and open_trade is None:
            open_trade = (i, n)
    return ("invalid", None)


def bcdiv_pct(entry, price):
    return ref.bcmul(ref.bcdiv(ref.bcsub(entry, price), entry), D(100))


bad = chk = 0
codes = {1: "sell", 2: "stop", 3: "time"}
t0 = time.time()
for sym in syms:
    a = np.load(f"data/{sym}.npy")
    t = a[:, 0].astype(np.int64); cf = a[:, 4].copy(); ci = core.to_int_prices(cf); cs = core.make_prefix(ci)
    for _ in range(40):
        k0 = int(rng.integers(300, len(t) - 40)); k = int(rng.integers(0, core.NSTRAT))
        kind = core.NAMES[k].split()[0]; s, l = map(int, core.NAMES[k].split()[1].split("/"))
        o = np.zeros(7); core.cycle_sim(t, cs, cf, k0, k, o)
        want = literal_cycle(t, ci, k0, kind, s, l)
        got = ("expired", None) if o[0] == 1 else (codes[int(o[3])], (int(o[1]), int(o[2])))
        chk += 1
        if got != want:
            bad += 1; print("MISMATCH", sym, k0, core.NAMES[k], got, want)
print(f"checked={chk} mismatches={bad} secs={time.time()-t0:.0f}")
sys.exit(1 if bad else 0)
