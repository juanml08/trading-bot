"""Inventario SOLO LECTURA de user_data/backtest_results/*.zip (Fase 0).
Uso (desde python-bot/): .venv/Scripts/python -I research/inventory-backtests/build_inventory.py
No ejecuta backtests, no modifica ni copia los zip. Escribe inventario.csv, strategy_hashes.csv y data_hashes.csv aqui."""
import csv, glob, hashlib, io, json, os, subprocess, zipfile
from collections import defaultdict
from datetime import datetime, timezone

RES = "user_data/backtest_results"
OUT = os.path.dirname(os.path.abspath(__file__))
sha = lambda b: hashlib.sha256(b).hexdigest()
iso = lambda ts: datetime.fromtimestamp(ts, timezone.utc).strftime("%Y-%m-%d %H:%M:%S")

head = subprocess.run(["git", "rev-parse", "HEAD"], capture_output=True, text=True).stdout.strip()
strat_cur = {os.path.basename(p)[:-3]: sha(open(p, "rb").read()) for p in sorted(glob.glob("user_data/strategies/*.py"))}
data_cur = {os.path.basename(p): (sha(open(p, "rb").read()), os.path.getsize(p)) for p in sorted(glob.glob("user_data/data/binance/*.feather"))}

rows = []
for z in sorted(glob.glob(f"{RES}/*.zip")):
    base = os.path.basename(z)[:-4]
    r = {"archivo": os.path.basename(z), "ruta": z.replace("\\", "/"), "zip_sha256": sha(open(z, "rb").read()),
         "mtime_utc": iso(os.path.getmtime(z)), "interpretable": "NO", "notas": ""}
    try:
        zf = zipfile.ZipFile(z); names = zf.namelist()
        j = json.load(zf.open(f"{base}.json"))
        meta = json.load(open(f"{RES}/{base}.meta.json")) if os.path.exists(f"{RES}/{base}.meta.json") else {}
        cfg = json.load(zf.open(f"{base}_config.json")) if f"{base}_config.json" in names else {}
        (name, v), = j["strategy"].items()
        r.update(estrategia=name, inicio=v["backtest_start"][:19], fin=v["backtest_end"][:19], dias=v["backtest_days"],
                 timeframe=v["timeframe"], pares=" ".join(v["pairlist"]), stake=v["stake_amount"], capital_inicial=v["starting_balance"],
                 max_ops=v["max_open_trades"], fee_config=cfg.get("fee", "DESCONOCIDO"), stoploss=v["stoploss"],
                 trailing=v["trailing_stop"], protecciones=v["enable_protections"], operaciones=v["total_trades"],
                 beneficio_abs=round(v["profit_total_abs"], 4), rentabilidad_pct=round(v["profit_total"] * 100, 3),
                 profit_factor=round(v["profit_factor"], 3), drawdown_cuenta_pct=round(v["max_drawdown_account"] * 100, 2),
                 senales_rechazadas=v["rejected_signals"], mercado_pct=round(v["market_change"] * 100, 2),
                 run_id=meta.get(name, {}).get("run_id", ""), ejecutado_utc=iso(meta[name]["backtest_start_time"]) if name in meta else "",
                 config_en_zip="SI" if cfg else "NO", dry_run_cfg=cfg.get("dry_run", ""), version_freqtrade="NO REGISTRADA en el zip")
        trades_sha = sha(json.dumps(v["trades"], sort_keys=True).encode())
        r["trades_sha256"] = trades_sha
        snap = [n for n in names if n.endswith(f"_{name}.py")]
        if snap:
            s = sha(zf.read(snap[0])); r["estrategia_snapshot_sha256"] = s
            r["estrategia_actual_sha256"] = strat_cur.get(name, "")
            r["estrategia_snapshot_vs_actual"] = "IGUAL" if s == strat_cur.get(name) else "DISTINTA"
        else:
            r["estrategia_snapshot_vs_actual"] = "DESCONOCIDO"
        if name in ("SupertrendRegime",):
            r["notas"] += "hereda de Supertrend (el zip solo guarda el archivo de la clase hija); "
        r["interpretable"] = "SI"
    except Exception as e:
        r["notas"] += f"ERROR al interpretar: {e!r}"
    rows.append(r)

# Lote / origen (inferido por orden temporal y configuracion; NO consta en los archivos)
rows.sort(key=lambda r: r["ejecutado_utc"] or r["mtime_utc"])
for i, r in enumerate(rows, 1): r["n"] = i
def origen(r):
    if r["interpretable"] != "SI": return "desconocido"
    if r["ejecutado_utc"] >= "2026-10-13": return "posterior (prueba suelta 2026-10-13)"
    if r["n"] <= 21: return "original (shortlist: run_all.sh)"
    return "auditoria (strategy-audit: exposicion/capital/posiciones/Regime)"
for r in rows: r["origen_inferido"] = origen(r)

# Duplicados: misma estrategia + mismo hash de trades + mismos parametros
grp = defaultdict(list)
for r in rows:
    if r["interpretable"] == "SI": grp[(r["estrategia"], r["trades_sha256"], r["stake"], r["capital_inicial"], r["max_ops"])].append(r)
for r in rows: r["duplicado_de"] = ""
for g in grp.values():
    for d in g[1:]: d["duplicado_de"] = f"#{g[0]['n']}"
# Comparabilidad: clave de configuracion de ejecucion
for r in rows:
    if r["interpretable"] == "SI":
        r["grupo_config"] = f"cap{r['capital_inicial']}|stake{r['stake']}|pos{r['max_ops']}"
        r["ventana"] = f"{r['inicio'][:10]}→{r['fin'][:10]}"

cols = ["n","archivo","origen_inferido","estrategia","inicio","fin","dias","timeframe","pares","stake","capital_inicial","max_ops","fee_config",
        "stoploss","trailing","protecciones","operaciones","beneficio_abs","rentabilidad_pct","profit_factor","drawdown_cuenta_pct","senales_rechazadas",
        "mercado_pct","ejecutado_utc","mtime_utc","version_freqtrade","config_en_zip","dry_run_cfg","estrategia_snapshot_vs_actual","duplicado_de",
        "grupo_config","run_id","trades_sha256","estrategia_snapshot_sha256","estrategia_actual_sha256","zip_sha256","ruta","interpretable","notas"]
with open(f"{OUT}/inventario.csv", "w", newline="", encoding="utf-8") as f:
    w = csv.DictWriter(f, cols, extrasaction="ignore"); w.writeheader(); w.writerows(rows)
with open(f"{OUT}/strategy_hashes.csv", "w", newline="") as f:
    w = csv.writer(f); w.writerow(["estrategia", "sha256_actual", "git_head"]); [w.writerow([k, h, head]) for k, h in strat_cur.items()]
with open(f"{OUT}/data_hashes.csv", "w", newline="") as f:
    w = csv.writer(f); w.writerow(["archivo", "sha256_actual", "bytes"]); [w.writerow([k, *h]) for k, h in data_cur.items()]
print("git_head", head, "| resultados", len(rows), "| interpretables", sum(r["interpretable"] == "SI" for r in rows))
