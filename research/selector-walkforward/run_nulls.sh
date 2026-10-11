#!/usr/bin/env bash
# Universos nulos: log-retornos permutados por símbolo (conserva volatilidad y deriva total; destruye la estructura serial)
export PYTHONIOENCODING=utf-8; PY=./.venv/Scripts/python.exe
for s in 1 2 3 4; do
  $PY run_sweep.py null$s --null $s > results/sweep_null$s.log 2>&1
  $PY l1.py null$s --null $s > results/l1_null$s.log 2>&1
  $PY analyze.py null$s --null $s > results/analyze_null$s.log 2>&1
  $PY uncond.py null$s --null $s > results/uncond_null$s.log 2>&1
  echo "null$s listo" >> results/nulls_progress.log
done
