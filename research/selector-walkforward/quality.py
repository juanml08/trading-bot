"""Calidad de datos de velas 30m: cobertura, huecos, duplicados, orden, OHLC, volumen cero, saltos extremos."""
import os, json, time, glob
import numpy as np
out = {}
for f in sorted(glob.glob("data/*.npy")):
    s = os.path.basename(f)[:-4]
    a = np.load(f); t = a[:, 0].astype(np.int64); d = np.diff(t)
    o, h, l, c, v = a[:, 1], a[:, 2], a[:, 3], a[:, 4], a[:, 5]
    gaps = d[d > 1800000]
    r = np.abs(np.diff(np.log(c)))
    out[s] = dict(
        n=len(a), first=time.strftime("%F %H:%M", time.gmtime(t[0] / 1000)), last=time.strftime("%F %H:%M", time.gmtime(t[-1] / 1000)),
        expected=int((t[-1] - t[0]) // 1800000 + 1), gaps=int(len(gaps)), missing_bars=int((gaps // 1800000 - 1).sum()),
        max_gap_bars=int(gaps.max() // 1800000 - 1) if len(gaps) else 0,
        dups=int((d == 0).sum()), non_monotonic=int((d < 0).sum()), off_grid=int((t % 1800000 != 0).sum()),
        ohlc_bad=int(((h < np.maximum(o, c) - 1e-12) | (l > np.minimum(o, c) + 1e-12)).sum()), nonpos_price=int((c <= 0).sum()),
        zero_vol=int((v == 0).sum()), flat_bars=int(((o == h) & (h == l) & (l == c)).sum()),
        ret_gt10pct=int((r > 0.10).sum()), max_abs_ret=float(r.max()))
json.dump(out, open("results/calidad_datos.json", "w"), indent=1)
tot = lambda k: sum(x[k] for x in out.values())
print(f"símbolos={len(out)} velas={tot('n')} huecos={tot('gaps')} velas_faltantes={tot('missing_bars')} dups={tot('dups')} "
      f"fuera_de_grilla={tot('off_grid')} ohlc_malas={tot('ohlc_bad')} vol_cero={tot('zero_vol')} planas={tot('flat_bars')} saltos>10%={tot('ret_gt10pct')}")
for s, x in out.items():
    if x['gaps'] or x['dups'] or x['ohlc_bad'] or x['non_monotonic'] or x['n'] != x['expected']:
        print(" ", s, {k: x[k] for k in ('n', 'expected', 'gaps', 'missing_bars', 'max_gap_bars', 'dups', 'ohlc_bad')})
