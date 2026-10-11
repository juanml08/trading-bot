"""Valida core.py contra el oráculo decimal (reference.py) en ventanas reales (incluye stablecoins)."""
import sys, time
import numpy as np
from decimal import Decimal as D
import core, reference as ref

rng = np.random.default_rng(12345)
import os
syms = [s for s in ["BTCUSDT","HOTUSDT","USDCUSDT","TUSDUSDT","IOSTUSDT","FETUSDT","ONGUSDT","ONTUSDT"] if os.path.exists(f"data/{s}.npy")]
bad = 0
checked = 0
t0 = time.time()
for sym in syms:
    a = np.load(f"data/{sym}.npy")
    for _ in range(3):
        st = int(rng.integers(0, len(a) - 480))
        w = a[st:st + 480]                       # ventana de 480 velas (tamaño de TRAIN/VALIDATION reales)
        ci = core.to_int_prices(w[:, 4]); cf = w[:, 4].copy()
        res = np.zeros((core.NSTRAT, 7))
        core.eval_window(ci, cf, res)
        closes = [D(repr(float(x))) if False else D(int(v)).scaleb(-8) for v, x in zip(ci, cf)]
        ks = rng.choice(core.NSTRAT, 12, replace=False)
        for k in ks:
            kind, s, l = core.NAMES[k].split()[0], int(core.NAMES[k].split()[1].split('/')[0]), int(core.NAMES[k].split()[1].split('/')[1])
            r = ref.evaluate(kind, s, l, closes, "500.00000000")
            m = res[k]
            ok = (int(m[0]) == r['ntr'] and int(m[1]) == r['wins'] and abs(m[2] - r['plp']) < 1e-6 and abs(m[3] - r['dd']) < 1e-6)
            checked += 1
            if not ok:
                bad += 1
                print("MISMATCH", sym, core.NAMES[k], (m[0], m[1], m[2], m[3]), r)
print(f"checked={checked} mismatches={bad} secs={time.time()-t0:.1f}")
sys.exit(1 if bad else 0)
