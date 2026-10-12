"""Benchmark B&H y desglose por par (OOS/DEV) desde los export de trades. Uso: .venv/Scripts/python -I research/strategy-shortlist/analyze.py"""
import json, zipfile, glob, os
import pandas as pd
D="user_data/data/binance"
P=["BTC","ETH","BNB"]
data={p:pd.read_feather(f"{D}/{p}_USDT-1h.feather").set_index("date").close for p in P}
for name,(a,b) in {"DEV":("2024-01-01","2025-06-01"),"OOS":("2025-06-01","2026-10-12"),"FULL":("2024-01-01","2026-10-12")}.items():
    r={p:data[p][a:b].iloc[-1]/data[p][a:b].iloc[0]-1 for p in P}
    print("BENCH",name,{k:f"{v:.1%}" for k,v in r.items()},"equal-weight",f"{sum(r.values())/3:.1%}")
# max drawdown B&H equal weight (rebalance-free: media de precios normalizados)
for name,(a,b) in {"OOS":("2025-06-01","2026-10-12"),"FULL":("2024-01-01","2026-10-12")}.items():
    idx=sum(data[p][a:b]/data[p][a:b].iloc[0] for p in P)/3
    print("BH maxDD",name,f"{(idx/idx.cummax()-1).min():.1%}")
# trades por par
metas=sorted(glob.glob("user_data/backtest_results/*.zip"),key=os.path.getmtime)
for z in metas:
    zf=zipfile.ZipFile(z); js=[n for n in zf.namelist() if n.endswith(".json") and "_config" not in n and "meta" not in n]
    d=json.load(zf.open(js[0]))
    for s,v in d["strategy"].items():
        t=pd.DataFrame(v["trades"]); 
        if t.empty: continue
        if len(t)<100 and False: continue
        key=(s,v["backtest_start"][:10],v["backtest_end"][:10],v["max_open_trades"],round(v["stake_amount"] if "stake_amount" in v else 0,1))
        g=t.groupby("pair").agg(n=("profit_abs","size"),pnl=("profit_abs","sum"))
        gp=t.profit_abs[t.profit_abs>0].sum(); gl=-t.profit_abs[t.profit_abs<0].sum()
        top=t.profit_ratio.sort_values().iloc[:3].tolist()
        print("TRADES",key,"|",{k:f"{r.n}:{r.pnl:.1f}" for k,r in g.iterrows()},"| worst3",[f"{x:.1%}" for x in top],"| avgW",f"{t.profit_ratio[t.profit_ratio>0].mean():.2%}","avgL",f"{t.profit_ratio[t.profit_ratio<0].mean():.2%}")
