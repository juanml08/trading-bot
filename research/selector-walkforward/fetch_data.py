"""Descarga velas 30m (klines públicas, solo lectura) del universo real del bot -> data/<SYM>.npy
Columnas: open_time_ms, open, high, low, close, volume. Sin relleno ni invención de huecos."""
import json, sys, time, calendar, urllib.request, os
import numpy as np
BASE = "https://demo-api.binance.com"   # mismo host que BINANCE_BASE_URL del bot
SYMS = ("ATOMUSDT FETUSDT ONGUSDT ONTUSDT BNBUSDT IOTAUSDT LINKUSDT ETHUSDT NEOUSDT LTCUSDT DASHUSDT ZECUSDT "
        "TUSDUSDT USDCUSDT HOTUSDT ENJUSDT BATUSDT ZRXUSDT ETCUSDT XLMUSDT THETAUSDT QTUMUSDT BTCUSDT VETUSDT "
        "TRXUSDT CELRUSDT ADAUSDT XRPUSDT ZILUSDT IOSTUSDT").split()
START = calendar.timegm(time.strptime("2025-09-01", "%Y-%m-%d")) * 1000
END = calendar.timegm(time.strptime("2026-10-11", "%Y-%m-%d")) * 1000
os.makedirs("data", exist_ok=True)
for s in SYMS:
    rows, t = [], START
    while t < END:
        u = f"{BASE}/api/v3/klines?symbol={s}&interval=30m&startTime={t}&endTime={END}&limit=1000"
        for attempt in range(5):
            try:
                d = json.load(urllib.request.urlopen(u, timeout=30)); break
            except Exception as e:
                time.sleep(2 * (attempt + 1)); d = None
        if d is None: raise SystemExit(f"fallo {s}")
        if not d: break
        rows += d; t = d[-1][0] + 1800000
        if len(d) < 1000: break
    a = np.array([[r[0], float(r[1]), float(r[2]), float(r[3]), float(r[4]), float(r[5])] for r in rows])
    np.save(f"data/{s}.npy", a)
    print(s, len(a), time.strftime("%F %H:%M", time.gmtime(a[0, 0] / 1000)), time.strftime("%F %H:%M", time.gmtime(a[-1, 0] / 1000)), flush=True)
