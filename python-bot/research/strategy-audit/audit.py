"""Auditoria de trades (FULL, 100 USDT/pos, 3 pos). Uso desde python-bot/: .venv/Scripts/python -I research/strategy-audit/audit.py"""
import sys; sys.path.insert(0,"research/strategy-shortlist")
import numpy as np, pandas as pd
from load_trades import get
pd.set_option("display.width",200); pd.set_option("display.max_columns",30)
S={"ReferenceSmaCross":("2024-01-03","2026-10-11"),"Supertrend":("2024-01-09","2026-10-11"),"BbandRsi":("2024-01-01","2026-10-11"),"ADXMomentum":("2024-01-01","2026-10-11")}
px={p:pd.read_feather(f"user_data/data/binance/{p}_USDT-1h.feather").set_index("date") for p in ["BTC","ETH","BNB"]}
def feat(t):
    t=t.copy(); r=[]
    for _,x in t.iterrows():
        d=px[x.pair.split("/")[0]]; i=d.index.searchsorted(x.open_date)
        c=d.close.values; lo=d.low.values
        ret30=c[i-1]/c[i-1-720]-1 if i>720 else np.nan
        er=abs(c[i-1]-c[i-1-168])/np.abs(np.diff(c[i-1-168:i])).sum() if i>168 else np.nan
        runup24=c[i-1]/lo[i-24:i].min()-1 if i>24 else np.nan   # cuanto ya subio desde el minimo de 24h
        r.append((ret30,er,runup24))
    t[["ret30","er168","runup24"]]=r
    t["fees"]=t.stake_amount*(t.fee_open+t.fee_close); t["gross"]=t.profit_abs+t.fees
    t["mfe"]=t.max_rate/t.open_rate-1; t["mae"]=t.min_rate/t.open_rate-1
    t["regime"]=pd.cut(t.ret30,[-9,-.10,.10,9],labels=["bajista30d","lateral30d","alcista30d"])
    t["month"]=t.close_date.dt.strftime("%Y-%m"); return t
def pf(x): 
    g=x[x>0].sum(); l=-x[x<0].sum(); return g/l if l else np.inf
for s,(a,b) in S.items():
    t=feat(get(s,a,b)); n=len(t)
    print(f"\n================ {s}  n={n}  net={t.profit_abs.sum():.1f}  gross={t.gross.sum():.1f}  fees={t.fees.sum():.1f}")
    print("-- salidas"); print(t.groupby("exit_reason").agg(n=("profit_abs","size"),pnl=("profit_abs","sum"),avg_pct=("profit_ratio","mean"),dur_h=("trade_duration",lambda x:x.mean()/60)).round(3))
    print("-- por par"); print(t.groupby("pair").agg(n=("profit_abs","size"),net=("profit_abs","sum"),gross=("gross","sum"),pf=("profit_abs",pf),avg_pct=("profit_ratio","mean")).round(3))
    print("-- regimen (ret 30d del par antes de entrar)"); print(t.groupby("regime",observed=True).agg(n=("profit_abs","size"),net=("profit_abs","sum"),avg_pct=("profit_ratio","mean"),pf=("profit_abs",pf)).round(3))
    t["er_q"]=pd.qcut(t.er168,3,labels=["ruidoso(ER bajo)","medio","direccional(ER alto)"])
    print("-- eficiencia 7d"); print(t.groupby("er_q",observed=True).agg(n=("profit_abs","size"),net=("profit_abs","sum"),avg_pct=("profit_ratio","mean")).round(3))
    t["ru_q"]=pd.qcut(t.runup24,3,labels=["poco run-up24h","medio","mucho run-up24h"])
    print("-- tardanza de entrada (subida desde minimo 24h)"); print(t.groupby("ru_q",observed=True).agg(n=("profit_abs","size"),net=("profit_abs","sum"),avg_pct=("profit_ratio","mean"),runup=("runup24","mean")).round(4))
    w=t[t.profit_abs>0]; l=t[t.profit_abs<0]
    print(f"-- ganadoras n={len(w)} media={w.profit_ratio.mean():.2%} MFE medio={w.mfe.mean():.2%} | perdedoras n={len(l)} media={l.profit_ratio.mean():.2%} MFE medio={l.mfe.mean():.2%} MAE medio={l.mae.mean():.2%}")
    print(f"   perdedoras que antes llegaron a >=+1% a favor: {(l.mfe>=.01).mean():.1%}; a >=+2%: {(l.mfe>=.02).mean():.1%}; dur media perd {l.trade_duration.mean()/60:.1f}h vs gan {w.trade_duration.mean()/60:.1f}h")
    ts=t.sort_values("profit_abs"); k=max(1,int(n*0.05))
    print(f"-- 5% peores ({k} ops) suman {ts.profit_abs.iloc[:k].sum():.1f} USDT; el 95% restante {ts.profit_abs.iloc[k:].sum():.1f}")
    print(f"   comision/operacion media = {t.fees.mean():.3f} USDT = {(t.fees/t.stake_amount).mean():.2%} del stake; bruto medio/op={t.gross.mean():.3f}; neto={t.profit_abs.mean():.3f}")
    m=t.groupby("month").profit_abs.sum(); print(f"-- meses negativos {int((m<0).sum())}/{len(m)}; peor mes {m.idxmin()} {m.min():.1f}; mejor {m.idxmax()} {m.max():.1f}")
    print("   5 peores meses:",m.nsmallest(5).round(1).to_dict())
    t=t.sort_values("close_date"); neg=(t.profit_abs<0).astype(int); run=neg.groupby((neg!=neg.shift()).cumsum()).cumsum()*neg
    print(f"-- racha maxima de perdidas seguidas {run.max()}")
    # simultaneidad: correlacion entre pares
    print("-- 5 peores trades:"); print(ts.head(5)[["pair","open_date","exit_reason","profit_ratio","profit_abs","mfe","mae"]].round(3).to_string())
