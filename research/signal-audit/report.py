"""Compuertas, clasificación y reporte. Uso: python report.py gates | python report.py final"""
import json, sys, pathlib
from audit import GRIDS, PRIMARY, NAMES, HOR, PH, SYMS

D = pathlib.Path(__file__).parent / "results"


def pct(x, d=2):
    return f"{x * 100:+.{d}f}%"


def gates_dev(fam, dev):
    p = str(PRIMARY[fam])
    c = dev["familias"][fam][p]
    tv, tr, va = c["TRAIN+VAL"], c["TRAIN"], c["VAL"]
    h8, d8 = tv["horizontes"]["8"], tv["detalle_8h"]
    g = {}
    pos_h = sum(1 for h in HOR if tv["horizontes"][str(h)]["exceso_C"] > 0)
    g["G1"] = (h8["exceso_C"] > 0 and h8["p_C"] < .10 and tr["horizontes"]["8"]["exceso_C"] > 0
               and va["horizontes"]["8"]["exceso_C"] > 0 and pos_h >= 4,
               f"exceso C 8h {pct(h8['exceso_C'], 3)} (p={h8['p_C']:.3f}); TRAIN {pct(tr['horizontes']['8']['exceso_C'], 3)}, "
               f"VAL {pct(va['horizontes']['8']['exceso_C'], 3)}; horizontes con exceso>0: {pos_h}/6")
    cells = [dev["familias"][fam][str(N)]["TRAIN+VAL"]["horizontes"]["8"] for N in GRIDS[fam]]
    gp = sum(1 for x in cells if x["exceso_C"] > 0); npos = sum(1 for x in cells if x["neto_medio"] > 0)
    g["G2"] = (h8["neto_medio"] > 0 and h8["exceso_A"] > 0 and d8["pf_neto"] >= 1.10 and npos >= 3,
               f"neto medio {pct(h8['neto_medio'], 3)}, exceso A {pct(h8['exceso_A'], 3)}, PF neto {d8['pf_neto']:.2f}; celdas con neto>0: {npos}/5")
    g["G3"] = (d8["p5_neto"] >= -.04 and d8["peor"] >= -.15 and d8["dd_max_diario_bruto"] >= -.20,
               f"p5 neto {pct(d8['p5_neto'])}, peor {pct(d8['peor'])}, DD bruto (slots 10%) {pct(d8['dd_max_diario_bruto'], 1)}")
    q = {k: v for k, v in tv["por_trimestre"].items() if v["n"] >= 10}
    qpos = sum(1 for v in q.values() if v["exceso_C"] > 0) / max(len(q), 1)
    tot = sum(v["suma_exceso"] for v in q.values())
    best = max((v["suma_exceso"] for v in q.values()), default=0)
    rg = {k: v for k, v in tv["por_regimen"].items() if v["n"] >= 20}
    rpos = sum(1 for v in rg.values() if v["exceso_C"] > 0)
    rbad = any(v["n"] >= 30 and v["exceso_C"] < -.003 for v in rg.values())
    g["G4"] = (qpos >= .6 and tot - best > 0 and rpos >= 2 and not rbad,
               f"trimestres+ {qpos:.0%} ({len(q)}), sin mejor trim. {'>0' if tot - best > 0 else '≤0'}; regímenes+ {rpos}/{len(rg)}"
               + (", régimen con exceso<-0,3%" if rbad else ""))
    ac = {k: v for k, v in tv["por_activo"].items() if v["n"] >= 10}
    apos = sum(1 for v in ac.values() if v["exceso_C"] > 0) / max(len(ac), 1)
    atot = sum(v["suma_exceso"] for v in ac.values()); abest = max(v["suma_exceso"] for v in ac.values())
    g["G5"] = (apos >= .6 and atot - abest > 0, f"activos+ {apos:.0%} ({len(ac)}), sin mejor activo {'>0' if atot - abest > 0 else '≤0'}")
    g["G6"] = (tv["por_dia_universo"] >= .5, f"{tv['por_dia_universo']:.2f} señales/día")
    g["G7"] = (gp >= 4, f"celdas con exceso bruto>0: {gp}/5")
    return g


def classify(g, test=None):
    if not g["G1"][0]:
        return "C"
    if not g["G3"][0]:
        return "E"
    if not (g["G4"][0] and g["G5"][0] and g["G7"][0]):
        return "D"
    if test is None:
        return "pendiente-TEST"
    t8 = test["horizontes"]["8"]
    if t8["exceso_C"] <= 0:
        return "D"
    if not (g["G2"][0] and g["G6"][0] and t8["neto_medio"] > 0):
        return "B"
    return "A"


def table_h(c):
    rows = ["| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |",
            "|---|---|---|---|---|---|---|---|---|---|---|"]
    for h in HOR:
        x = c["horizontes"][str(h)]
        rows.append(f"| {h}h | {x['n']} | {pct(x['bruto_medio'], 3)} | {pct(x['bruto_mediana'], 3)} | {pct(x['neto_medio'], 3)} | "
                    f"{pct(x['refA'], 3)} | {pct(x['refB'], 3)} | {pct(x['exceso_A'], 3)} | {pct(x['exceso_B'], 3)} | "
                    f"{pct(x['exceso_C'], 3)} | {x['p_C']:.3f} |")
    return "\n".join(rows)


def table_grp(d, title, minn=1):
    rows = [f"| {title} | n | exceso C 8h | neto medio 8h | win neto |", "|---|---|---|---|---|"]
    for k, v in d.items():
        if v["n"] >= minn:
            rows.append(f"| {k} | {v['n']} | {pct(v['exceso_C'], 3)} | {pct(v['neto_medio'], 3)} | {v['win_neto']:.0%} |")
    return "\n".join(rows)


def risk_row(c):
    d = c["detalle_8h"]
    return (f"win rate neto {d['win_rate_neto']:.1%} (bruto {d['win_rate_bruto']:.1%}); PF neto {d['pf_neto']:.2f} (bruto {d['pf_bruto']:.2f}); "
            f"media neta {pct(d['media_neta'], 3)}, mediana neta {pct(d['mediana_neta'], 3)}; "
            f"pérdida media {pct(d['perd_media'])}, mediana {pct(d['perd_mediana'])}; p5/p10 {pct(d['p5_neto'])}/{pct(d['p10_neto'])}; "
            f"peor {pct(d['peor'])}; pérdidas <-2%: {d['frec_perdida_grande(<-2%)']:.1%}; ganancias netas (0,1%]: {d['frec_ganancia_pequena(0,1%]']:.1%}; "
            f"MAE medio/mediana/p5 {pct(d['MAE_medio'])}/{pct(d['MAE_mediana'])}/{pct(d['MAE_p5'])}; MFE medio/mediana {pct(d['MFE_medio'])}/{pct(d['MFE_mediana'])}; "
            f"DD slots 10% neto/bruto {pct(d['dd_max_diario'], 1)}/{pct(d['dd_max_diario_bruto'], 1)}; días+ (con señal) {d['pct_dias_pos_con_senal']:.0%}, "
            f"días+ (calendario) {d['pct_dias_pos_calendario']:.0%}; peor día {pct(d['peor_dia'])}; racha negativa máx. {d['racha_neg_max_dias']} días")


def main(mode):
    dev = json.loads((D / "resultados_dev.json").read_text(encoding="utf8"))
    test = json.loads((D / "resultados_test.json").read_text(encoding="utf8")) if mode == "final" else None
    G = {f: gates_dev(f, dev) for f in GRIDS}
    pre = {f: classify(G[f]) for f in GRIDS}
    if mode == "gates":
        (D / "frozen.json").write_text(json.dumps({"clasificacion_pre_test": pre,
                                                   "gates": {f: {k: [bool(v[0]), v[1]] for k, v in G[f].items()} for f in G},
                                                   "primario": PRIMARY, "horizonte": PH}, indent=1, ensure_ascii=False), encoding="utf8")
        for f in GRIDS:
            print(f, pre[f], {k: v[0] for k, v in G[f].items()})
            for k, v in G[f].items():
                print("   ", k, "OK " if v[0] else "NO ", v[1])
        return
    final = {f: classify(G[f], test["familias"][f][str(PRIMARY[f])]["TEST"]) for f in GRIDS}
    m = dev["meta"]; tm = test["meta"]["tramos"]
    L = []
    w = L.append
    w("# Apéndice de datos — auditoría de familias de señales\n")
    w("Estudio offline, read-only. No se modificó código del bot ni BD. Protocolo pre-registrado en [PROTOCOLO.md](PROTOCOLO.md) (con tres enmiendas metodológicas declaradas antes de abrir el TEST).\n")
    w("> **Regla madre:** El Trading Bot busca obtener pequeñas ganancias de manera frecuente y consistente, priorizando la preservación del capital y un riesgo bajo sobre la maximización del rendimiento.\n")
    w("## 1. Resumen ejecutivo\n")
    w("| Familia | Parámetro primario | Clase (pre-TEST) | Clase final |\n|---|---|---|---|")
    for f in GRIDS:
        w(f"| {f} — {NAMES[f]} | {PRIMARY[f]} | {pre[f]} | **{final[f]}** |")
    w("")
    w("## 2. Datos, período y separación\n")
    w(f"- Velas 1h spot de Binance (API pública), 20 pares USDT líquidos: {', '.join(SYMS)}. Sesgo de supervivencia reconocido (pares vigentes hoy).")
    w("- Período descargado: 2023-10-01 → 2026-10-01 UTC (26.304 velas por activo). Barras elegibles: ≥800 velas de historia y ≥24 futuras.")
    for k, v in m["tramos"].items():
        w(f"- {k}: {v[0]} → {v[1]}")
    w(f"- TEST: {tm['TEST'][0]} → {tm['TEST'][1]} (leído una sola vez, con parámetros congelados). Embargo de 24 velas entre tramos.")
    w("- Capital de referencia 20 USDT **no interviene** en la medición de la señal: todo está en retorno porcentual por operación (1 unidad nocional). Capital y riesgo se diseñan después.")
    w("- Comisión: bruto = 0; neto = −0,20% round trip. Entrada en open[t+1] tras la señal en el cierre de t; salida en close[t+h]. Sin stops, SELL, trailing ni time exit.\n")
    w("### Referencia A (cualquier barra elegible), horizonte 8h\n")
    w("| Tramo | n | bruto medio | neto medio | win neto | PF neto | p5 neto |\n|---|---|---|---|---|---|---|")
    for k, v in list(dev["referencia_A"].items()) + [("TEST", test["referencia_A"]["TEST"])]:
        w(f"| {k} | {v['n']} | {pct(v['bruto_medio'], 3)} | {pct(v['neto_medio'], 3)} | {v['win_neto']:.1%} | {v['pf_neto']:.2f} | {pct(v['p5_neto'])} |")
    w("\nEntrar en cualquier vela cuesta, en promedio, ~0,17–0,28 pp por operación después de comisión: **la vara base es negativa**.\n")
    w("## 3. Definición exacta de las señales\n")
    w("- **A Retroceso en tendencia**: close>SMA168 y SMA168[t]>SMA168[t−24] y RSI Wilder(N) cruza al alza el nivel 35. N∈{10,12,14,16,18}.")
    w("- **B Ruptura**: primer cierre > máximo de los N máximos previos. N∈{18,21,24,27,30}.")
    w("- **C Expansión de volatilidad**: rango verdadero > 2× media de las N velas previas y vela alcista (close>open). N∈{18,21,24,27,30}.")
    w("- **D Momentum cross-sectional**: el activo entra al top-3 del universo por ROC(N) con ROC>0 (no estaba en t−1). N∈{18,21,24,27,30}.")
    w("- **E Régimen + entrada**: BTC>SMA(N) y vol 24h de BTC ≤ mediana de 720h (régimen); entrada = vela alcista con volumen>1,5× media 24h. N∈{160,…,240}.")
    w("- Cooldown de 24 velas por activo en todas.\n")
    w("## 4. Compuertas (desarrollo = TRAIN+VAL, 8h) y resultado fuera de muestra\n")
    for f in GRIDS:
        w(f"### {f} — {NAMES[f]}\n")
        w("| Compuerta | ¿Pasa? | Detalle |\n|---|---|---|")
        names = {"G1": "G1 edge bruto vs aleatoria", "G2": "G2 neto/PF", "G3": "G3 riesgo", "G4": "G4 temporal/régimen",
                 "G5": "G5 activos", "G6": "G6 frecuencia", "G7": "G7 robustez de parámetros"}
        for k, v in G[f].items():
            w(f"| {names[k]} | {'sí' if v[0] else '**no**'} | {v[1]} |")
        t = test["familias"][f][str(PRIMARY[f])]["TEST"]
        if t["n"]:
            t8 = t["horizontes"]["8"]
            w(f"| G8 TEST | {'sí' if (t8['exceso_C'] > 0 and t8['neto_medio'] > 0) else '**no**'} | n={t['n']}, exceso C 8h {pct(t8['exceso_C'], 3)} (p={t8['p_C']:.3f}), neto medio {pct(t8['neto_medio'], 3)} |")
        w("")
    w("## 5. Resultados por familia (parámetro primario)\n")
    for f in GRIDS:
        p = str(PRIMARY[f]); c = dev["familias"][f][p]["TRAIN+VAL"]; t = test["familias"][f][p]["TEST"]
        w(f"### {f} — {NAMES[f]} (N={p}) → clase final **{final[f]}**\n")
        w(f"**Oportunidades** (TRAIN+VAL): {c['n']} — {c['por_dia_universo']:.2f}/día en el universo, {c['por_activo_dia']:.3f} por activo-día, "
          f"{c['por_semana']:.1f}/semana, {c['por_mes']:.0f}/mes. TEST: {t['n']} ({t.get('por_dia_universo', 0):.2f}/día).\n")
        w("**Retornos por horizonte — TRAIN+VAL**\n"); w(table_h(c)); w("")
        w("**Retornos por horizonte — TEST (fuera de muestra)**\n"); w(table_h(t) if t["n"] else "_sin señales_"); w("")
        w(f"**Riesgo a 8h — TRAIN+VAL**: {risk_row(c)}\n")
        if t["n"]:
            w(f"**Riesgo a 8h — TEST**: {risk_row(t)}\n")
        w("**Por activo (TRAIN+VAL)**\n"); w(table_grp(c["por_activo"], "Activo", 1)); w("")
        w("**Por trimestre (TRAIN+VAL)**\n"); w(table_grp(c["por_trimestre"], "Trimestre")); w("")
        w("**Por régimen de mercado (TRAIN+VAL)**\n"); w(table_grp(c["por_regimen"], "Régimen")); w("")
        if t["n"]:
            w("**Por régimen (TEST)**\n"); w(table_grp(t["por_regimen"], "Régimen")); w("")
        w("**Robustez de parámetros (TRAIN+VAL, 8h)**\n")
        w("| N | n | exceso C | p | bruto medio | neto medio | TEST exceso C | TEST neto |\n|---|---|---|---|---|---|---|---|")
        for N in GRIDS[f]:
            x = dev["familias"][f][str(N)]["TRAIN+VAL"]["horizontes"]["8"]
            y = test["familias"][f][str(N)]["TEST"].get("horizontes", {}).get("8")
            w(f"| {N}{' (primario)' if N == PRIMARY[f] else ''} | {x['n']} | {pct(x['exceso_C'], 3)} | {x['p_C']:.3f} | {pct(x['bruto_medio'], 3)} | {pct(x['neto_medio'], 3)} | "
              f"{pct(y['exceso_C'], 3) if y else '—'} | {pct(y['neto_medio'], 3) if y else '—'} |")
        w("")
    rs = dev["E_regimen_solo"]["TRAIN+VAL"]["horizontes"]["8"]; rt = test["E_regimen_solo"]["TEST"]["horizontes"]["8"]
    w("### Referencia adicional: familia E con solo el régimen (sin entrada), 8h\n")
    w(f"TRAIN+VAL: bruto {pct(rs['bruto_medio'], 3)}, exceso C {pct(rs['exceso_C'], 3)} (p={rs['p_C']:.3f}). TEST: bruto {pct(rt['bruto_medio'], 3)}, exceso C {pct(rt['exceso_C'], 3)}.\n")
    (D.parent / "REPORTE_DATOS.md").write_text("\n".join(L), encoding="utf8")
    print("\n".join(f"{f} pre={pre[f]} final={final[f]}" for f in GRIDS))


if __name__ == "__main__":
    main(sys.argv[1])
