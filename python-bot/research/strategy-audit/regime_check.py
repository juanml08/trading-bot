"""Consistencia DEV vs OOS del efecto 'ret 30d del par < -10% al entrar' (sin lookahead: usa cierre previo). Uso: .venv/Scripts/python -I research/strategy-audit/regime_check.py"""
import sys; sys.path.insert(0,"research/strategy-shortlist")
import numpy as np, pandas as pd
from load_trades import get
px={p:pd.read_feather(f"user_data/data/binance/{p}_USDT-1h.feather").set_index("date").close for p in ["BTC","ETH","BNB"]}
P={"DEV":{"ReferenceSmaCross":("2024-01-03","2025-06-01"),"Supertrend":("2024-01-09","2025-06-01"),"BbandRsi":("2024-01-01","2025-06-01"),"ADXMomentum":("2024-01-01","2025-06-01")},
   "OOS":{k:("2025-06-01","2026-10-11") for k in ["ReferenceSmaCross","Supertrend","BbandRsi","ADXMomentum"]}}
def pf(x):
    g=x[x>0].sum(); l=-x[x<0].sum(); return round(g/l,2) if l else np.inf
for per,d in P.items():
    for s,(a,b) in d.items():
        t=get(s,a,b); r=[]
        for _,x in t.iterrows():
            c=px[x.pair.split("/")[0]]; i=c.index.searchsorted(x.open_date); r.append(c.iloc[i-1]/c.iloc[i-1-720]-1 if i>720 else np.nan)
        t["ret30"]=r; t=t.dropna(subset=["ret30"]); bad=t.ret30<-.10
        print(f"{per} {s:18s} todas n={len(t):4d} net={t.profit_abs.sum():7.1f} | ret30<-10%: n={bad.sum():3d} net={t[bad].profit_abs.sum():7.1f} pf={pf(t[bad].profit_abs)} | resto: n={(~bad).sum():4d} net={t[~bad].profit_abs.sum():7.1f} pf={pf(t[~bad].profit_abs)}")
