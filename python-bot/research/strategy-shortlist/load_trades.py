"""Carga trades exportados por Freqtrade (user_data/backtest_results/*.zip). Selecciona por (estrategia, inicio, fin, max_open, stake)."""
import json, zipfile, glob, os
import pandas as pd
def load_all():
    out=[]
    for z in sorted(glob.glob("user_data/backtest_results/*.zip"),key=os.path.getmtime):
        zf=zipfile.ZipFile(z); js=[n for n in zf.namelist() if n.endswith(".json") and "_config" not in n and "meta" not in n]
        d=json.load(zf.open(js[0]))
        for s,v in d["strategy"].items():
            t=pd.DataFrame(v["trades"])
            if t.empty: continue
            t["open_date"]=pd.to_datetime(t.open_date); t["close_date"]=pd.to_datetime(t.close_date)
            out.append(dict(strategy=s,start=v["backtest_start"][:10],end=v["backtest_end"][:10],max_open=v["max_open_trades"],stake=round(t.stake_amount.iloc[0],1),trades=t))
    return out
def get(strategy,start,end,max_open=3,stake=100.0):
    r=[x for x in load_all() if x["strategy"]==strategy and x["start"]==start and x["end"]==end and x["max_open"]==max_open and abs(x["stake"]-stake)<1]
    return r[-1]["trades"]
