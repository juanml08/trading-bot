"""Compuertas, clasificación y reporte de datos 4h. Uso: python report_4h.py gates | python report_4h.py final
`gates` solo lee resultados de desarrollo y escribe results/frozen_4h.json (congela la clasificación pre-TEST).
`final` lee además resultados_test_4h.json y genera REPORTE_DATOS_4H.md (incluye comparación 1h vs 4h)."""
import json, sys, pathlib
from audit_4h import GRIDS, PRIMARY, NAMES, HOR, PH, SYMS

D = pathlib.Path(__file__).parent / "results"
PHK = str(PH)
HL = {hz: f"{hz * 4}h" for hz in HOR}
CLASS_1H = {"A": "C", "B": "C", "C": "E", "D": "C", "E": "C"}  # REPORTE.md (1h)


def pct(x, d=2):
    return f"{x * 100:+.{d}f}%"


def gates_dev(fam, dev):
    p = str(PRIMARY[fam])
    c = dev["familias"][fam][p]
    tv, tr, va = c["TRAIN+VAL"], c["TRAIN"], c["VAL"]
    h8, d8 = tv["horizontes"][PHK], tv["detalle_8h"]
    g = {}
    pos_h = sum(1 for h in HOR if tv["horizontes"][str(h)]["exceso_C"] > 0)
    g["G1"] = (h8["exceso_C"] > 0 and h8["p_C"] < .10 and tr["horizontes"][PHK]["exceso_C"] > 0
               and va["horizontes"][PHK]["exceso_C"] > 0 and pos_h >= 4,
               f"exceso C 8h {pct(h8['exceso_C'], 3)} (p={h8['p_C']:.3f}); TRAIN {pct(tr['horizontes'][PHK]['exceso_C'], 3)}, "
               f"VAL {pct(va['horizontes'][PHK]['exceso_C'], 3)}; horizontes con exceso>0: {pos_h}/6")
    cells = [dev["familias"][fam][str(N)]["TRAIN+VAL"]["horizontes"][PHK] for N in GRIDS[fam]]
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
    atot = sum(v["suma_exceso"] for v in ac.values()); abest = max((v["suma_exceso"] for v in ac.values()), default=0)
    g["G5"] = (apos >= .6 and atot - abest > 0, f"activos+ {apos:.0%} ({len(ac)}), sin mejor activo {'>0' if atot - abest > 0 else '≤0'}")
    g["G6"] = (tv["por_dia_universo"] >= .5, f"{tv['por_dia_universo']:.2f} señales/día")
    g["G7"] = (gp >= 4, f"celdas con exceso bruto>0: {gp}/5")
    return g


def classify(g, tests=None):
    """tests = (TEST-final, TEST-PRE) del parámetro primario, o None antes de abrir el TEST."""
    if not g["G1"][0]:
        return "C"
    if not g["G3"][0]:
        return "E"
    if not (g["G4"][0] and g["G5"][0] and g["G7"][0]):
        return "D"
    if tests is None:
        return "pendiente-TEST"
    ts = [t["horizontes"][PHK] if t.get("n") else None for t in tests]
    if any(x is None or x["exceso_C"] <= 0 for x in ts):
        return "D"
    if not (g["G2"][0] and g["G6"][0] and all(x["neto_medio"] > 0 for x in ts)):
        return "B"
    return "A"


def table_h(c):
    rows = ["| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |",
            "|---|---|---|---|---|---|---|---|---|---|---|"]
    for h in HOR:
        x = c["horizontes"][str(h)]
        rows.append(f"| {HL[h]} | {x['n']} | {pct(x['bruto_medio'], 3)} | {pct(x['bruto_mediana'], 3)} | {pct(x['neto_medio'], 3)} | "
                    f"{pct(x['refA'], 3)} | {pct(x['refB'], 3)} | {pct(x['exceso_A'], 3)} | {pct(x['exceso_B'], 3)} | "
                    f"{pct(x['exceso_C'], 3)} | {x['p_C']:.3f} |")
    return "\n".join(rows)


def table_grp(d, title, minn=1, extra=False):
    hdr = f"| {title} | n | exceso C 8h | neto medio 8h | win neto |" + (" señales/mes |" if extra else "")
    rows = [hdr, "|---|---|---|---|---|" + ("---|" if extra else "")]
    for k, v in d.items():
        if v["n"] >= minn:
            rows.append(f"| {k} | {v['n']} | {pct(v['exceso_C'], 3)} | {pct(v['neto_medio'], 3)} | {v['win_neto']:.0%} |"
                        + (f" {v['senales_mes']:.2f} |" if extra else ""))
    return "\n".join(rows)


def risk_row(c):
    d = c["detalle_8h"]
    return (f"win rate neto {d['win_rate_neto']:.1%} (bruto {d['win_rate_bruto']:.1%}); PF neto {d['pf_neto']:.2f} (bruto {d['pf_bruto']:.2f}); "
            f"media neta {pct(d['media_neta'], 3)}, mediana neta {pct(d['mediana_neta'], 3)}; "
            f"pérdida media {pct(d['perd_media'])}, mediana {pct(d['perd_mediana'])}; p5/p10 {pct(d['p5_neto'])}/{pct(d['p10_neto'])}; "
            f"peor operación {pct(d['peor'])}; pérdidas <-2%: {d['frec_perdida_grande(<-2%)']:.1%}; ganancias netas (0,1%]: {d['frec_ganancia_pequena(0,1%]']:.1%}; "
            f"MAE medio/mediana/p5 {pct(d['MAE_medio'])}/{pct(d['MAE_mediana'])}/{pct(d['MAE_p5'])}; MFE medio/mediana {pct(d['MFE_medio'])}/{pct(d['MFE_mediana'])}; "
            f"DD slots 10% neto/bruto {pct(d['dd_max_diario'], 1)}/{pct(d['dd_max_diario_bruto'], 1)}; "
            f"días+ (con señal) {d['pct_dias_pos_con_senal']:.0%}, días+ (calendario) {d['pct_dias_pos_calendario']:.0%}; peor día {pct(d['peor_dia'])}; racha negativa máx. {d['racha_neg_max_dias']} días; "
            f"semanas+ (con señal) {d['pct_semanas_pos_con_senal']:.0%}, semanas+ (calendario) {d['pct_semanas_pos_calendario']:.0%}; peor semana {pct(d['peor_semana'])}; "
            f"racha perdedora máx. {d['racha_perdedora_ops']} operaciones seguidas")


def tramo_row(nm, c):
    if not c.get("n"):
        return f"| {nm} | 0 | — | — | — | — | — |"
    x = c["horizontes"][PHK]
    return f"| {nm} | {x['n']} | {pct(x['bruto_medio'], 3)} | {pct(x['neto_medio'], 3)} | {pct(x['exceso_C'], 3)} | {x['p_C']:.3f} | {c['detalle_8h']['pf_neto']:.2f} |"


def main(mode):
    dev = json.loads((D / "resultados_dev_4h.json").read_text(encoding="utf8"))
    test = json.loads((D / "resultados_test_4h.json").read_text(encoding="utf8")) if mode == "final" else None
    G = {f: gates_dev(f, dev) for f in GRIDS}
    pre = {f: classify(G[f]) for f in GRIDS}
    if mode == "gates":
        (D / "frozen_4h.json").write_text(json.dumps({"clasificacion_pre_test": pre,
                                                      "gates": {f: {k: [bool(v[0]), v[1]] for k, v in G[f].items()} for f in G},
                                                      "primario": PRIMARY, "horizonte_velas": PH, "horizonte_h": PH * 4},
                                                     indent=1, ensure_ascii=False), encoding="utf8")
        for f in GRIDS:
            print(f, pre[f], {k: v[0] for k, v in G[f].items()})
            for k, v in G[f].items():
                print("   ", k, "OK " if v[0] else "NO ", v[1])
        return
    fz = json.loads((D / "frozen_4h.json").read_text(encoding="utf8"))
    assert fz["clasificacion_pre_test"] == pre, "la clasificación pre-TEST cambió respecto de la congelada"
    prim = lambda f, seg: test["familias"][f][str(PRIMARY[f])][seg]
    final = {f: classify(G[f], (prim(f, "TEST"), prim(f, "TESTPRE"))) for f in GRIDS}
    m = dev["meta"]; tm = test["meta"]["tramos"]
    L = []
    w = L.append
    w("# Apéndice de datos — experimento 4h (auditoría de familias de señales)\n")
    w("Estudio offline, read-only. No se modificó código del bot ni BD. Protocolo pre-registrado en [PROTOCOLO_4H.md](PROTOCOLO_4H.md) (reutiliza [PROTOCOLO.md](PROTOCOLO.md)). Informe: [REPORTE_4H.md](REPORTE_4H.md).\n")
    w("> **Regla madre:** El Trading Bot busca obtener pequeñas ganancias de manera frecuente y consistente, priorizando la preservación del capital y un riesgo bajo sobre la maximización del rendimiento.\n")
    w("## 1. Resumen\n")
    w("| Familia | Parámetro primario | Clase 1h | Clase 4h (pre-TEST) | Clase 4h final |\n|---|---|---|---|---|")
    for f in GRIDS:
        w(f"| {f} — {NAMES[f]} | {PRIMARY[f]} | {CLASS_1H[f]} | {pre[f]} | **{final[f]}** |")
    w("")
    w("## 2. Datos, período y separación\n")
    w(f"- Velas **4h** spot de Binance (`data-api.binance.vision`, API pública sin autenticación), solo velas cerradas. 20 pares USDT: {', '.join(SYMS)}. Sesgo de supervivencia reconocido (pares vigentes hoy), igual que en 1h.")
    w("- Descarga: 2022-01-01 → 2026-10-01 00:00 UTC: **10.404 velas por activo (208.080 en total)**, sin huecos, sin volumen cero, sin velas OHLC incoherentes (ver `results/calidad_datos_4h.json`).")
    w("- Tramo principal (mismo calendario que 1h; calentamiento desde 2023-04-01): primera barra elegible 2023-11-03 08:00 UTC. Velas por activo en el tramo principal cargado: 7.674.")
    for k, v in m["tramos"].items():
        w(f"- {k}: {v[0]} → {v[1]}")
    w(f"- TEST-final: {tm['TEST'][0]} → {tm['TEST'][1]} (**contaminado**: coincide casi por completo con el TEST de 1h, 2026-04-23 → 2026-09-29, ya consumido).")
    w(f"- TEST-PRE (virgen para estas hipótesis, nunca usado en 1h): {tm['TESTPRE'][0]} → {tm['TESTPRE'][1]}.")
    w("- Embargo de 18 velas (72h) entre tramos. TEST-final y TEST-PRE se abrieron una sola vez, juntos, con `frozen_4h.json` ya escrito.")
    w("- Capital de referencia (20 USDT) **no interviene**: todo es retorno porcentual por operación. Capital y tamaño de posición se diseñan después.")
    w("- Comisión: bruto = 0; neto = −0,20% round trip (0,1% por lado). **El resultado neto utiliza comisión, pero no modela slippage.** Entrada en open[t+1] tras la señal en el cierre de t; salida en close[t+h]. Sin stops, SELL, trailing ni time exit. Cooldown 18 velas por activo.\n")
    w("### Referencia A (cualquier barra elegible), horizonte 8h\n")
    w("| Tramo | n | bruto medio | neto medio | win neto | PF neto | p5 neto |\n|---|---|---|---|---|---|---|")
    for k, v in list(dev["referencia_A"].items()) + [("TEST", test["referencia_A"]["TEST"]), ("TEST-PRE", test["referencia_A"]["TESTPRE"])]:
        w(f"| {k} | {v['n']} | {pct(v['bruto_medio'], 3)} | {pct(v['neto_medio'], 3)} | {v['win_neto']:.1%} | {v['pf_neto']:.2f} | {pct(v['p5_neto'])} |")
    w("")
    w("## 3. Definición exacta de las señales (parámetros en velas de 4h)\n")
    w("- **A Retroceso en tendencia**: close>SMA168 y SMA168[t]>SMA168[t−24] y RSI Wilder(N) cruza al alza el nivel 35. N∈{10,12,14,16,18}.")
    w("- **B Ruptura**: primer cierre > máximo de los N máximos previos. N∈{18,21,24,27,30}.")
    w("- **C Expansión de volatilidad**: rango verdadero > 2× media de las N velas previas y vela alcista (close>open). N∈{18,21,24,27,30}.")
    w("- **D Momentum cross-sectional**: el activo entra al top-3 del universo por ROC(N) con ROC>0 (no estaba en t−1). N∈{18,21,24,27,30}.")
    w("- **E Régimen + entrada**: BTC>SMA(N) y vol de BTC (24 velas) ≤ mediana de 720 velas (régimen); entrada = vela alcista con volumen>1,5× media de 24 velas. N∈{160,…,240}.")
    w("- Régimen de mercado para desgloses: retorno de BTC a 30 días (180 velas); >+10% alcista, <−10% bajista.")
    w("- Cooldown de 18 velas por activo en todas. Los parámetros conservan su número de velas (ver PROTOCOLO_4H.md §2).\n")
    w("## 4. Compuertas (desarrollo = TRAIN+VAL, 8h) y resultado fuera de muestra\n")
    for f in GRIDS:
        w(f"### {f} — {NAMES[f]}\n")
        w("| Compuerta | ¿Pasa? | Detalle |\n|---|---|---|")
        names = {"G1": "G1 edge bruto vs aleatoria", "G2": "G2 neto/PF", "G3": "G3 riesgo", "G4": "G4 temporal/régimen",
                 "G5": "G5 activos", "G6": "G6 frecuencia", "G7": "G7 robustez de parámetros"}
        for k, v in G[f].items():
            w(f"| {names[k]} | {'sí' if v[0] else '**no**'} | {v[1]} |")
        for seg, lab in (("TEST", "G8a TEST-final (contaminado)"), ("TESTPRE", "G8b TEST-PRE (virgen)")):
            t = prim(f, seg)
            if t["n"]:
                t8 = t["horizontes"][PHK]
                w(f"| {lab} | {'sí' if (t8['exceso_C'] > 0 and t8['neto_medio'] > 0) else '**no**'} | n={t['n']}, exceso C 8h {pct(t8['exceso_C'], 3)} (p={t8['p_C']:.3f}), neto medio {pct(t8['neto_medio'], 3)} |")
            else:
                w(f"| {lab} | **no** | sin señales |")
        w("")
    w("## 5. Resultados por familia (parámetro primario)\n")
    for f in GRIDS:
        p = str(PRIMARY[f]); c = dev["familias"][f][p]["TRAIN+VAL"]; t = prim(f, "TEST"); tp = prim(f, "TESTPRE")
        w(f"### {f} — {NAMES[f]} (N={p}) → clase final **{final[f]}**\n")
        w(f"**Oportunidades** (TRAIN+VAL): {c['n']} señales — {c['por_dia_universo']:.2f}/día en el universo, {c['por_activo_dia']:.3f} por activo-día, "
          f"{c['por_semana']:.1f}/semana, {c['por_mes']:.0f}/mes. TEST-final: {t['n']} ({t.get('por_dia_universo', 0):.2f}/día). TEST-PRE: {tp['n']} ({tp.get('por_dia_universo', 0):.2f}/día).\n")
        w("**Consistencia entre tramos (8h)**\n")
        w("| Tramo | n | bruto medio | neto medio | exceso C | p (C) | PF neto |\n|---|---|---|---|---|---|---|")
        w(tramo_row("TRAIN", dev["familias"][f][p]["TRAIN"])); w(tramo_row("VAL", dev["familias"][f][p]["VAL"]))
        w(tramo_row("TEST-final", t)); w(tramo_row("TEST-PRE", tp)); w("")
        w("**Retornos por horizonte — TRAIN+VAL**\n"); w(table_h(c)); w("")
        for seg, lab in ((t, "TEST-final (contaminado)"), (tp, "TEST-PRE (virgen)")):
            w(f"**Retornos por horizonte — {lab}**\n"); w(table_h(seg) if seg["n"] else "_sin señales_"); w("")
        w(f"**Riesgo a 8h — TRAIN+VAL**: {risk_row(c)}\n")
        for seg, lab in ((t, "TEST-final"), (tp, "TEST-PRE")):
            if seg["n"]:
                w(f"**Riesgo a 8h — {lab}**: {risk_row(seg)}\n")
        w("**Por activo (TRAIN+VAL)**\n"); w(table_grp(c["por_activo"], "Activo", 1, extra=True)); w("")
        w("**Por trimestre (TRAIN+VAL)**\n"); w(table_grp(c["por_trimestre"], "Trimestre")); w("")
        w("**Por régimen de mercado (TRAIN+VAL)**\n"); w(table_grp(c["por_regimen"], "Régimen")); w("")
        for seg, lab in ((t, "TEST-final"), (tp, "TEST-PRE")):
            if seg["n"]:
                w(f"**Por régimen ({lab})**\n"); w(table_grp(seg["por_regimen"], "Régimen")); w("")
        w("**Robustez de parámetros (8h)**\n")
        w("| N | n (dev) | exceso C | p | bruto medio | neto medio | TEST-final exceso C | TEST-final neto | TEST-PRE exceso C | TEST-PRE neto |\n|---|---|---|---|---|---|---|---|---|---|")
        for N in GRIDS[f]:
            x = dev["familias"][f][str(N)]["TRAIN+VAL"]["horizontes"][PHK]
            y = test["familias"][f][str(N)]["TEST"].get("horizontes", {}).get(PHK)
            z = test["familias"][f][str(N)]["TESTPRE"].get("horizontes", {}).get(PHK)
            w(f"| {N}{' (primario)' if N == PRIMARY[f] else ''} | {x['n']} | {pct(x['exceso_C'], 3)} | {x['p_C']:.3f} | {pct(x['bruto_medio'], 3)} | {pct(x['neto_medio'], 3)} | "
              f"{pct(y['exceso_C'], 3) if y else '—'} | {pct(y['neto_medio'], 3) if y else '—'} | {pct(z['exceso_C'], 3) if z else '—'} | {pct(z['neto_medio'], 3) if z else '—'} |")
        w("")
    rs = dev["E_regimen_solo"]["TRAIN+VAL"]["horizontes"][PHK]
    rt = test["E_regimen_solo"]["TEST"]["horizontes"][PHK]; rp = test["E_regimen_solo"]["TESTPRE"]["horizontes"][PHK]
    w("### Referencia adicional: familia E con solo el régimen (sin entrada), 8h\n")
    w(f"TRAIN+VAL: bruto {pct(rs['bruto_medio'], 3)}, exceso C {pct(rs['exceso_C'], 3)} (p={rs['p_C']:.3f}). "
      f"TEST-final: bruto {pct(rt['bruto_medio'], 3)}, exceso C {pct(rt['exceso_C'], 3)}. TEST-PRE: bruto {pct(rp['bruto_medio'], 3)}, exceso C {pct(rp['exceso_C'], 3)}.\n")
    # ---- comparación 1h vs 4h
    d1 = json.loads((D / "resultados_dev.json").read_text(encoding="utf8"))
    t1 = json.loads((D / "resultados_test.json").read_text(encoding="utf8"))
    w("## 6. Comparación 1h vs 4h (parámetro primario, horizonte 8h = misma duración)\n")
    w("Desarrollo = TRAIN+VAL. Las filas de 1h salen de `resultados_dev.json` y `resultados_test.json` (experimento previo, sin tocar).\n")
    w("| Familia | TF | n dev | señales/día | bruto medio | exceso C | neto medio | win neto | PF neto | p5 neto | peor op. | DD bruto | trim.+ (G4) | TEST exceso C | TEST neto | clase |")
    w("|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|")
    for f in GRIDS:
        p = str(PRIMARY[f])
        for tf, dv, ts_, cl, k8 in (("1h", d1, t1, CLASS_1H[f], "8"), ("4h", dev, test, final[f], PHK)):
            c = dv["familias"][f][p]["TRAIN+VAL"]; h8 = c["horizontes"][k8]; dd = c["detalle_8h"]
            q = {k: v for k, v in c["por_trimestre"].items() if v["n"] >= 10}
            qp = sum(1 for v in q.values() if v["exceso_C"] > 0) / max(len(q), 1)
            te = ts_["familias"][f][p]["TEST"]["horizontes"][k8]
            w(f"| {f} | {tf} | {c['n']} | {c['por_dia_universo']:.2f} | {pct(h8['bruto_medio'], 3)} | {pct(h8['exceso_C'], 3)} | {pct(h8['neto_medio'], 3)} | "
              f"{dd['win_rate_neto']:.1%} | {dd['pf_neto']:.2f} | {pct(dd['p5_neto'])} | {pct(dd['peor'])} | {pct(dd['dd_max_diario_bruto'], 1)} | {qp:.0%} ({len(q)}) | "
              f"{pct(te['exceso_C'], 3)} | {pct(te['neto_medio'], 3)} | {cl} |")
    w("")
    w("### Exceso C vs aleatoria por horizonte (TRAIN+VAL), 1h vs 4h\n")
    w("| Familia | TF | " + " | ".join(f"{h}h" for h in (4, 8, 16, 24)) + " | 48h | 72h |\n|---|---|---|---|---|---|---|---|")
    for f in GRIDS:
        p = str(PRIMARY[f])
        r1 = d1["familias"][f][p]["TRAIN+VAL"]["horizontes"]; r4 = dev["familias"][f][p]["TRAIN+VAL"]["horizontes"]
        w(f"| {f} | 1h | " + " | ".join(pct(r1[str(h)]["exceso_C"], 3) for h in (4, 8, 16, 24)) + " | — | — |")
        w(f"| {f} | 4h | " + " | ".join(pct(r4[str(h // 4)]["exceso_C"], 3) for h in (4, 8, 16, 24, 48, 72)) + " |")
    w("")
    (D.parent / "REPORTE_DATOS_4H.md").write_text("\n".join(L), encoding="utf8")
    print("\n".join(f"{f} pre={pre[f]} final={final[f]}" for f in GRIDS))


if __name__ == "__main__":
    main(sys.argv[1])
