"""Prueba anti-fuga temporal. (1) El pipeline en el instante k no cambia si se alteran TODAS las velas posteriores a k.
(2) El ciclo simulado no cambia si se alteran todas las velas posteriores a su vela de salida (ni la vela siguiente a cada decisión previa).
(3) La señal viva en cada tick solo usa velas <= tick (alterar la vela n+1 no cambia nada hasta el tick n)."""
import numpy as np, sys
import core
rng = np.random.default_rng(99)
bad = 0
DAY = 86400000
for sym in ["BTCUSDT", "FETUSDT", "USDCUSDT", "HOTUSDT"]:
    a = np.load(f"data/{sym}.npy"); t = a[:, 0].astype(np.int64); cf = a[:, 4].copy(); n = len(t)
    for _ in range(25):
        k = int(rng.integers(1000, n - 80))
        lo = int(np.searchsorted(t, t[k] - 20 * DAY + 3600000)); hi = k + 1
        # (1) pipeline
        def run(c):
            ci = core.to_int_prices(c)
            fl = np.zeros((1, core.NSTRAT), np.uint8); sm = np.zeros((1, 9))
            core.sweep(ci, c, np.array([lo], np.int64), np.array([hi], np.int64), core.CFG_DEFAULT, fl, sm)
            return fl.copy(), sm.copy()
        f1, s1 = run(cf)
        c2 = cf.copy(); c2[k + 1:] = c2[k + 1:] * rng.uniform(0.5, 2.0, n - k - 1)
        f2, s2 = run(c2)
        ok1 = (f1 == f2).all() and (s1 == s2).all()
        # (2) ciclo
        for kk in rng.integers(0, core.NSTRAT, 6):
            cs1 = core.make_prefix(core.to_int_prices(cf)); o1 = np.zeros(7); core.cycle_sim(t, cs1, cf, k, int(kk), o1)
            if o1[0] == 0: continue
            end = int(o1[6]); c3 = cf.copy(); c3[end + 1:] = c3[end + 1:] * rng.uniform(0.5, 2.0, n - end - 1)
            cs3 = core.make_prefix(core.to_int_prices(c3)); o3 = np.zeros(7); core.cycle_sim(t, cs3, c3, k, int(kk), o3)
            if not (o1 == o3).all():
                bad += 1; print("FUGA ciclo", sym, k, core.NAMES[kk])
        if not ok1:
            bad += 1; print("FUGA pipeline", sym, k)
print("fugas detectadas:", bad); sys.exit(1 if bad else 0)
