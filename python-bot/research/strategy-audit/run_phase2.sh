#!/usr/bin/env bash
# Desde python-bot/. Solo backtesting. Salidas en research/strategy-audit/out/
FT=.venv/Scripts/freqtrade; OUT=research/strategy-audit/out; mkdir -p $OUT
# A) Hipotesis: misma ventana para ambas (inicio 20240201 para cubrir el warm-up de 920 velas). DEV / OOS fijados antes.
for S in Supertrend SupertrendRegime; do
  for P in "DEV 20240201-20250601" "OOS 20250601-20261012"; do set -- $P
    $FT backtesting -c config.json --userdir user_data -s $S --timerange $2 --cache none --export trades > $OUT/${S}_$1_100usdt_3pos_wallet1000.txt 2>&1
  done
done
# B) Capital objetivo 100 USDT: wallet 100, 1/2/3 posiciones (stake = 99/N), periodo FULL 20240201-20261012
for S in ReferenceSmaCross Supertrend BbandRsi ADXMomentum SupertrendRegime; do
  for N in 1 2 3; do
    ST=$(python -c "print(round(99/$N,2))" 2>/dev/null || echo 99)
    $FT backtesting -c config.json --userdir user_data -s $S --timerange 20240201-20261012 --cache none \
      --dry-run-wallet 100 --stake-amount $ST --max-open-trades $N > $OUT/${S}_FULL_wallet100_${N}pos.txt 2>&1
  done
done
# C) Sesgos de la estrategia modificada
for c in lookahead-analysis recursive-analysis; do
  $FT $c -c config.json --userdir user_data -s SupertrendRegime --timerange 20250601-20260301 $( [ $c = lookahead-analysis ] && echo --minimum-trade-amount 10 --targeted-trade-amount 20 ) > $OUT/SupertrendRegime_$c.txt 2>&1
done
# D) Capital 100 USDT con exposicion maxima ~33% (stake*N = 33): 1x33, 2x16.5, 3x11
for S in ReferenceSmaCross Supertrend BbandRsi ADXMomentum SupertrendRegime; do
  for X in "1 33" "2 16.5" "3 11"; do set -- $X
    .venv/Scripts/freqtrade backtesting -c config.json --userdir user_data -s $S --timerange 20240201-20261012 --cache none \
      --dry-run-wallet 100 --stake-amount $2 --max-open-trades $1 > research/strategy-audit/out/${S}_FULL_wallet100_exp33_${1}pos.txt 2>&1
  done
done
