#!/usr/bin/env bash
# Ejecutar desde python-bot/. Solo backtesting (sin trade, sin claves). Salidas en research/strategy-shortlist/out/
# Periodos fijados ANTES de ver resultados: DEV 20240101-20250601, OOS 20250601-20261012, FULL 20240101-20261012
set -u
FT=.venv/Scripts/freqtrade
OUT=research/strategy-shortlist/out
declare -A TR=( [DEV]=20240101-20250601 [OOS]=20250601-20261012 [FULL]=20240101-20261012 )
for S in ReferenceSmaCross Supertrend BbandRsi ADXMomentum; do
  for P in DEV OOS FULL; do
    $FT backtesting -c config.json --userdir user_data -s $S --timerange ${TR[$P]} --cache none \
      --export trades --breakdown month > $OUT/${S}_${P}_100usdt.txt 2>&1
  done
  # Capital 20 USDT: 1 posicion de 19.8 USDT; y 3 posiciones de 6.6 USDT
  $FT backtesting -c config.json --userdir user_data -s $S --timerange ${TR[OOS]} --cache none \
    --dry-run-wallet 20 --stake-amount 19.8 --max-open-trades 1 > $OUT/${S}_OOS_20usdt_1pos.txt 2>&1
  $FT backtesting -c config.json --userdir user_data -s $S --timerange ${TR[OOS]} --cache none \
    --dry-run-wallet 20 --stake-amount 6.6 --max-open-trades 3 > $OUT/${S}_OOS_20usdt_3pos.txt 2>&1
done
