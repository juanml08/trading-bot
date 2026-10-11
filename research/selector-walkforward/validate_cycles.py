"""Fidelidad del ciclo: compara los 25 ciclos reales de la BD (lectura) con core.cycle_sim sobre las velas descargadas.
Compara: expiró/entró, precio de entrada (cierre de la vela vista por el tick), precio de salida, motivo de salida y duración en velas."""
import os, subprocess, calendar, time
import numpy as np
import core
HERE = os.path.dirname(os.path.abspath(__file__))
def q(sql):
    out = subprocess.run(["php", os.path.join(HERE, "dbq.php"), sql], capture_output=True, text=True, check=True).stdout.strip("\n").split("\n")
    cols = out[0].split("\t"); return [dict(zip(cols, l.split("\t"))) for l in out[1:]]
ms = lambda s: calendar.timegm(time.strptime(s, "%Y-%m-%d %H:%M:%S")) * 1000
cyc = q("select c.id cyc, a.symbol, s.name, c.state, c.started_at, timestampdiff(hour, c.started_at, c.expires_at) hold_h, t.opened_at, t.closed_at, t.entry_price, t.exit_price from active_trading_cycles c "
        "join assets a on a.id=c.asset_id join strategies s on s.id=c.strategy_id left join trades t on t.active_strategy_id=c.active_strategy_id order by c.id")
ev = q("select active_trading_cycle_id cyc, event_type from bot_events where event_type in ('risk_stop_loss','risk_time_exit','signal_sell_confirmed')")
why = {}
for e in ev:
    why.setdefault(e["cyc"], []).append(e["event_type"])
RN = {1: "signal_sell_confirmed", 2: "risk_stop_loss", 3: "risk_time_exit"}
tot = ok = 0
for c in cyc:
    if c["state"] in ("HOLD", "POSITION_OPEN"):
        continue
    a = np.load(os.path.join(HERE, "data", c["symbol"] + ".npy")); t = a[:, 0].astype(np.int64); cf = a[:, 4].copy()
    cs = core.make_prefix(core.to_int_prices(cf))
    k = core.NAMES.index(c["name"])
    st = ms(c["started_at"])
    k0 = int(np.searchsorted(t, st - 1800000, side="right") - 1)
    o = np.zeros(7); core.cycle_sim(t, cs, cf, k0, k, o)
    real_exp = c["state"] == "EXPIRED"
    tot += 1
    if real_exp:
        # los ciclos 1-13 se crearon con hold_timeout=4h (config previa); con 8h un BUY posterior a las 4h es coherente con EXPIRED real
        good = (o[0] == 1) or (c["hold_h"] == "4" and o[0] == 2 and (int(o[1]) - k0) >= 8)
        print(f"cyc{c['cyc']:>3} {c['symbol']:<9}{c['name']:<11} real=EXPIRED sim={'EXPIRED' if o[0]==1 else ('TRADE' if o[0]==2 else 'INVALID')}  {'OK' if good else 'DIFF'}")
    else:
        ent_bar = int((ms(c["opened_at"]) - st) // 1800000)    # tick real aproximado
        ex_bar = int((ms(c["closed_at"]) - st) // 1800000)
        if o[0] != 2:
            good = False; print(f"cyc{c['cyc']:>3} {c['symbol']:<9}{c['name']:<11} real=TRADE sim={o[0]}  DIFF"); tot += 0; ok += 0; continue
        ep, xp = cf[int(o[1])], cf[int(o[2])]
        rep, rxp = float(c["entry_price"]), float(c["exit_price"])
        reason_ok = RN[int(o[3])] in why.get(c["cyc"], [])
        good = abs(ep - rep) < 1e-12 and abs(xp - rxp) < 1e-12 and reason_ok
        print(f"cyc{c['cyc']:>3} {c['symbol']:<9}{c['name']:<11} real entry={rep} exit={rxp} reason={why.get(c['cyc'])} | sim entry={ep} exit={xp} reason={RN[int(o[3])]} "
              f"bars(entry,exit) sim=({int(o[1])-k0},{int(o[2])-k0}) real~({ent_bar},{ex_bar}) {'OK' if good else 'DIFF'}")
    ok += good
print(f"ciclos cerrados/expirados comparados={tot} coinciden={ok}")
