# Resultados (backtests propios, solo simulación)

## Configuración
- Freqtrade 2026.9, `config.json` sin cambios: spot, dry_run, `fee` 0.1% por lado (Freqtrade la aplica en entrada y salida → 0.2% ida y vuelta), `max_open_trades` 3, `stake_amount` 100, wallet 1000 USDT, StaticPairList BTC/ETH/BNB, 1h, sin slippage.
- Datos existentes (no se descargó nada): Binance 1h, 2024-01-01 → 2026-10-11 20:00, 24 357 velas por par.
- **Periodos fijados antes de ver resultados**: DEV 2024-01-01→2025-06-01; OOS 2025-06-01→2026-10-12; FULL completo. Las estrategias se usaron con los **parámetros originales del autor** (sin hyperopt ni ajuste); DEV/OOS es solo una partición cronológica para ver estabilidad. Cualquier "ajuste" de Supertrend lo hizo el autor con datos que no controlamos.
- Reproducir (desde `python-bot/`): `bash research/strategy-shortlist/run_all.sh` y `.venv/Scripts/python -I research/strategy-shortlist/analyze.py`. Salidas: `research/strategy-shortlist/out/*.txt`; exports de trades en `user_data/backtest_results/` (ignorado por git). Los análisis `lookahead-analysis`/`recursive-analysis` están en `out/<Estrategia>_<análisis>.txt` (periodo 20250601-20260301).

## Criterios de aceptación (definidos antes de ver resultados; provisionales)
Sobre OOS con stake 100 / 3 posiciones:
1. Expectativa neta por operación > 0 y profit factor ≥ 1.2.
2. Drawdown máx ≤ 10% de la cuenta.
3. ≥ 100 operaciones OOS.
4. Positiva en ≥ 2 de 3 pares.
5. Peor operación ≤ ~10% (sin depender de colas).
6. Sin lookahead/recursividad detectables.
Los umbrales son juicio nuestro a partir de la regla madre; no están calibrados.

## Benchmark
- El **77.7%** del README era el "Market change" de Freqtrade para ReferenceSmaCross (2024-01-03→2026-10-11): media aritmética del cambio de cierre de BTC/ETH/BNB. **Mismos pares/timeframe/periodo, así que el número es correcto**, pero: (a) varía con la fecha de inicio (77.7–88%); (b) es buy&hold 100% invertido, mientras las estrategias usan como máximo 300 de 1000 USDT (30%); (c) fue muy desigual: BTC +97%, ETH +10.6%, BNB +139.8% (FULL; media 82.5%).
- Cálculo propio (`analyze.py`): DEV +89.8%, **OOS −1.5%** (BTC −19.9%, ETH +0.5%, BNB +14.8%), FULL +82.5%. Drawdown de un buy&hold equiponderado ≈ −60% (OOS y FULL; cálculo aproximado, sin rebalanceo).
- En OOS el mercado fue plano/bajista: el +77.7% no aplica a ese periodo. El benchmark es 100% invertido y no es like-for-like con estrategias al 30% de exposición; se muestra como referencia, no como criterio duro.

## Tabla comparativa (OOS, 100 USDT/posición, 3 posiciones, wallet 1000; 16.4 meses)
| | ReferenceSmaCross | Supertrend | BbandRsi | ADXMomentum |
|---|---|---|---|---|
| Rentab. neta OOS | **+0.88%** (+8.8 USDT) | −10.36% | −3.60% | −8.75% |
| Rentab. neta DEV | −5.59% | +2.83% | +3.67% | −8.97% |
| Rentab. neta FULL | −4.71% | −7.53% | +0.57% | −17.80% |
| Drawdown máx OOS | 9.94% | 17.14% | 13.38% | 14.80% |
| Drawdown máx FULL | 16.84% | 19.87% | 12.97% | 23.34% |
| Operaciones OOS | 390 | 464 | 140 | 685 |
| Ops/mes aprox. | 24 | 28 | 8.5 | 42 |
| % ganadoras | 35.1% | 53.9% | 63.6% | 76.5% |
| Ganancia media / pérdida media | +3.26% / −1.73% | +1.98% / −2.80% | +3.05% / −6.04% | +1.00% / −3.80% |
| Expectativa neta/op. | +0.02% | −0.22% | −0.26% | −0.13% |
| Profit factor | 1.02 | 0.83 | 0.88 | 0.86 |
| Duración media | 1d 23h | 1d 11h | 5d 8h | 20h |
| Peor trade OOS | −5.2% | −9.5% | −25.1% | −12.6% |
| PnL por par OOS (BTC/ETH/BNB, USDT) | −17.1 / −9.5 / +35.4 | −53.1 / −7.9 / −42.6 | −16.4 / −8.1 / −11.6 | −39.2 / −18.6 / −29.7 |
| Pares positivos OOS | 1 de 3 | 0 de 3 | 0 de 3 | 0 de 3 |
| vs benchmark OOS (−1.5%, 100% invertido) | por encima | por debajo | por debajo | por debajo |
| Lookahead | — | sin sesgo | sin sesgo | sin sesgo |
| Recursivo | — | sin varianza detectada | **no ejecutable** (startup_candle_count=0) | sin varianza relevante |

El % ganador no es criterio aislado: ADXMomentum gana 3 de cada 4 operaciones y pierde dinero (ganancia fija ~1% vs pérdida media 3.8%). Con 30% de exposición máxima, un drawdown de 10–17% de la cuenta es alto.

## Capital de 20 USDT (OOS)
Binance spot exige notional mínimo ≈ 5 USDT por orden. Probado: (a) 1 posición de 19.8 USDT; (b) 3 posiciones de 6.6 USDT (apenas sobre el mínimo). El backtester **no modela** redondeo de lotes/polvo ni rechazos reales de la bolsa, ni slippage ni profundidad de libro.

| Estrategia | 1×19.8: final / trades | 3×6.6: final / trades |
|---|---|---|
| ReferenceSmaCross | 19.94 / 2 | 20.80 / 361 |
| Supertrend | 20.00 / 1 (23 señales rechazadas) | 15.20 / 401 (DD 39%) |
| BbandRsi | 18.65 / 10 | 14.50 / 100 (DD 43%) |
| ADXMomentum | 28.45 / 360 (2915 señales rechazadas) | 16.98 / 643 (DD 33%) |

Estos resultados **no son evidencia**: con 1 posición cambia qué señales se ejecutan (ADXMomentum +42% con 1 hueco vs −15% con 3, mismas señales) → ruido de trayectoria. Un solo hueco pierde la mayoría de las señales; con 3 huecos de 6.6 USDT queda poco margen sobre el mínimo y una pérdida de cola (−25%) cuesta ~1.7 USDT por operación.

## Decisión
Contra los criterios previos, **ninguna de las tres estrategias pasa**: las tres tienen expectativa neta negativa OOS (PF 0.83–0.88), ningún par positivo y drawdown >13%. BbandRsi hizo +0.57% en todo el periodo con 282 operaciones y colas de −25%: indistinguible de cero. ReferenceSmaCross (solo infraestructura) fue lo único ligeramente positivo OOS (+0.88%, PF 1.02), dentro del ruido y −5.6% en DEV; tampoco pasa. Mejor relación rentabilidad/riesgo entre las tres nuevas: BbandRsi (menor pérdida, pocas operaciones), pero no es candidata.

## Cambios y limitaciones
- Añadidos (nada existente modificado): `user_data/strategies/{Supertrend,BbandRsi,ADXMomentum}.py` (copias literales), `research/strategy-shortlist/` (informes, `run_all.sh`, `analyze.py`, `out/`).
- Sin commits; sin tocar `api/`, BD, config, dependencias ni `ReferenceSmaCross`. `dry_run: true` e `initial_state: stopped` intactos; no se inició ningún bot.
- Limitaciones: sin slippage ni profundidad de libro; sin hyperopt (por diseño); 3 pares, un solo periodo OOS plano (un solo régimen); `lookahead-analysis` cubre solo una ventana (2025-06→2026-03) y 20 señales; el benchmark OOS casi nulo hace ruidosa cualquier comparación relativa.
