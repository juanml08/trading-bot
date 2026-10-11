#!/usr/bin/env bash
# Reproduce el experimento completo (solo lectura sobre la BD; no toca código productivo). Ejecutar desde research/selector-walkforward.
set -e
python -m venv .venv && ./.venv/Scripts/python.exe -m pip install -q numpy numba pandas   # Windows; en Linux: ./.venv/bin/python
PY=./.venv/Scripts/python.exe
$PY fetch_data.py                 # velas 30m -> data/*.npy (klines públicas)
$PY quality.py                    # calidad de datos -> results/calidad_datos.json
$PY test_core.py                  # núcleo vs oráculo decimal (bcmath)
$PY test_cycle.py                 # ciclo vs traducción literal de AutomaticTradingCycle
$PY validate_db.py 150 7          # pipeline vs evaluaciones guardadas en la BD
$PY validate_cycles.py            # ciclos simulados vs ciclos reales de la BD
$PY run_sweep.py real             # Discovery/Validation/Pareto en cada cierre de vela 30m
$PY l1.py real                    # capa 1: decisiones de A y resultados de todas las estrategias
$PY analyze.py real               # A vs B con IC por bloques  -> results/summary_real.json
$PY l2.py real --reps 200         # capa 2: réplica secuencial (20 slots) A vs B
$PY run_sweep.py null1 --null 1 && $PY l1.py null1 --null 1 && $PY analyze.py null1 --null 1   # universo nulo (retornos permutados); repetir con 2..5
