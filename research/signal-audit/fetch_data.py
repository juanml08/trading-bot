"""Descarga velas 1h públicas de Binance (solo lectura) a data/*.npy. Sin autenticación."""
import numpy as np, requests, sys, pathlib, time
SYMS = "BTC ETH BNB XRP SOL ADA DOGE TRX LINK LTC AVAX DOT ETC BCH ATOM NEAR XLM UNI AAVE IOTA".split()
START = 1696118400000  # 2023-10-01 UTC
END = 1759276800000    # 2025-10-01? placeholder replaced below
END = 1790812800000    # 2026-10-01 00:00 UTC
out = pathlib.Path(sys.argv[1]); out.mkdir(parents=True, exist_ok=True)
for s in SYMS:
    rows, t = [], START
    while t < END:
        r = requests.get("https://data-api.binance.vision/api/v3/klines",
            params=dict(symbol=s+"USDT", interval="1h", startTime=t, endTime=END-1, limit=1000), timeout=20)
        r.raise_for_status(); k = r.json()
        if not k: break
        rows += [[x[0], x[1], x[2], x[3], x[4], x[5]] for x in k]
        t = k[-1][0] + 3600000
        time.sleep(0.05)
    a = np.array(rows, dtype=float); np.save(out / f"{s}.npy", a)
    print(s, len(a), a[0,0], a[-1,0], flush=True)
