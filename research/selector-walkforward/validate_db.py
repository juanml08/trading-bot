"""Fidelidad contra producción: reconstruye con las velas descargadas las evaluaciones que el bot guardó en la BD
(automatic_search_cycle_assets/_strategies, LECTURA) y compara las 400 estrategias de cada activo:
trades, ganadoras, WR, P/L%, drawdown (TRAIN y VALIDATION) y los flags Discovery/Validation.
Uso: python validate_db.py [n_activos=60] [semilla=7]"""
import sys, subprocess, io, json, calendar, time, os
import numpy as np
import core

N = int(sys.argv[1]) if len(sys.argv) > 1 else 60
SEED = int(sys.argv[2]) if len(sys.argv) > 2 else 7
HERE = os.path.dirname(os.path.abspath(__file__))


def q(sql):
    out = subprocess.run(["php", os.path.join(HERE, "dbq.php"), sql], capture_output=True, text=True, check=True).stdout
    lines = out.strip("\n").split("\n")
    if lines[0] == "(0 rows)":
        return []
    cols = lines[0].split("\t")
    return [dict(zip(cols, l.split("\t"))) for l in lines[1:]]


rng = np.random.default_rng(SEED)
assets = q("select id, symbol, evaluated_at from automatic_search_cycle_assets where status <> 'no_opportunity' or 1=1 order by id")
assets = [a for a in assets if os.path.exists(os.path.join(HERE, "data", a["symbol"] + ".npy"))]
pick = rng.choice(len(assets), min(N, len(assets)), replace=False)
cache = {}
tot = dict(strat=0, train_ok=0, val_ok=0, val_n=0, flag_ok=0, assets=0, assets_all_ok=0, train_mism=[], val_mism=[], flag_mism=[])
# lista de umbrales reales
cfg = core.CFG_DEFAULT.copy()
for idx in pick:
    a = assets[int(idx)]
    sym = a["symbol"]
    if sym not in cache:
        cache[sym] = np.load(os.path.join(HERE, "data", sym + ".npy"))
    d = cache[sym]
    E = calendar.timegm(time.strptime(a["evaluated_at"], "%Y-%m-%d %H:%M:%S")) * 1000
    t = d[:, 0].astype(np.int64)
    lo = int(np.searchsorted(t, E - 20 * 86400000, side="left"))
    hi = int(np.searchsorted(t + core.BAR_MS, E, side="right"))   # cierre (open+30m) <= E
    c = d[lo:hi, 4]
    n = len(c)
    cut = max(1, min(n * 50 // 100, n - 1))
    ci = core.to_int_prices(c); cf = c.copy()
    tres = np.zeros((core.NSTRAT, 7)); vres = np.zeros((core.NSTRAT, 7))
    core.eval_window(ci[:cut], cf[:cut], tres)
    core.eval_window(ci[cut:], cf[cut:], vres)
    rows = q("select strategy_name nm, discovery_passed dp, validation_passed vp, train_total_trades tn, train_winning_trades tw, "
             "train_profit_loss_percentage tpl, train_max_drawdown_percentage tdd, validation_total_trades vn, validation_winning_trades vw, "
             "validation_profit_loss_percentage vpl, validation_max_drawdown_percentage vdd "
             f"from automatic_search_cycle_strategies where automatic_search_cycle_asset_id = {a['id']}")
    name_idx = {nm: i for i, nm in enumerate(core.NAMES)}
    all_ok = True
    for r in rows:
        k = name_idx[r["nm"]]
        tot["strat"] += 1
        tr = tres[k]
        ok_t = (int(r["tn"]) == int(tr[0]) and int(r["tw"]) == int(tr[1]) and abs(float(r["tpl"]) - tr[2]) < 1e-4 and abs(float(r["tdd"]) - tr[3]) < 1e-4)
        tot["train_ok"] += ok_t
        if not ok_t:
            all_ok = False
            if len(tot["train_mism"]) < 8:
                tot["train_mism"].append((a["symbol"], a["evaluated_at"], r["nm"], r["tn"], r["tw"], r["tpl"], r["tdd"], tr[:4].tolist(), n, cut))
        dp_mine = bool(core._passes(tr, cfg, 0))
        vp_mine = False
        if r["vn"] != "NULL":
            vr = vres[k]
            tot["val_n"] += 1
            ok_v = (int(r["vn"]) == int(vr[0]) and int(r["vw"]) == int(vr[1]) and abs(float(r["vpl"]) - vr[2]) < 1e-4 and abs(float(r["vdd"]) - vr[3]) < 1e-4)
            tot["val_ok"] += ok_v
            if not ok_v:
                all_ok = False
                if len(tot["val_mism"]) < 8:
                    tot["val_mism"].append((a["symbol"], a["evaluated_at"], r["nm"], r["vn"], r["vw"], r["vpl"], r["vdd"], vr[:4].tolist()))
            vp_mine = dp_mine and bool(core._passes(vr, cfg, 4))
        okf = (dp_mine == (r["dp"] == "1")) and (vp_mine == (r["vp"] == "1"))
        tot["flag_ok"] += okf
        if not okf:
            all_ok = False
            if len(tot["flag_mism"]) < 8:
                tot["flag_mism"].append((a["symbol"], a["evaluated_at"], r["nm"], r["dp"], r["vp"], dp_mine, vp_mine))
    tot["assets"] += 1
    tot["assets_all_ok"] += all_ok
print(json.dumps({k: v for k, v in tot.items()}, indent=1, default=str))
print(f"activos={tot['assets']} (100% coincidentes: {tot['assets_all_ok']}) estrategias={tot['strat']} "
      f"TRAIN_ok={tot['train_ok']} VAL_ok={tot['val_ok']}/{tot['val_n']} FLAGS_ok={tot['flag_ok']}")
