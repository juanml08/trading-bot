# Auditoría de pérdidas y capital objetivo de 100 USDT

Reutiliza `research/strategy-shortlist/` (RESULTS.md, exports de trades). Reproducir (desde `python-bot/`): `.venv/Scripts/python -I research/strategy-audit/audit.py` (salida guardada en `audit_output.txt`), `regime_check.py`, `bash research/strategy-audit/run_phase2.sh`. Salidas de Freqtrade en `out/`. Todo es backtest, `dry_run: true`, sin claves.
Datos: 2024-01 → 2026-10, BTC/ETH/BNB 1h, comisión 0,1 % por lado. Trades analizados: periodo completo, 100 USDT/posición, 3 posiciones (wallet 1000).

## 1. Por qué pierden (causas)

**C1 — DEMOSTRADA: la ventaja bruta es ≈ el coste de comisiones.** Las cuatro estrategias son positivas *antes* de comisiones y negativas después:

| | Ops | Bruto (USDT) | Comisiones | Neto | Bruto/op | Comisión/op |
|---|---|---|---|---|---|---|
| ReferenceSmaCross | 830 | +118 | 166 | −47 | 0,14 % | 0,20 % |
| Supertrend | 921 | +108 | 184 | −75 | 0,12 % | 0,20 % |
| BbandRsi | 282 | +62 | 56 | +6 | 0,22 % | 0,20 % |
| ADXMomentum | 1437 | +109 | 287 | −178 | 0,08 % | 0,20 % |

Cada operación paga 0,2 % ida y vuelta; el movimiento bruto medio capturado es 0,08–0,22 %. Con esta frecuencia (0,3–1,4 ops/día) la ventaja bruta es demasiado pequeña. Esto no se arregla con "más operaciones": más operaciones con la misma ventaja bruta/op empeoran el resultado.

**C2 — DEMOSTRADA (Supertrend, ADXMomentum): asimetría entre ROI y salida por señal.** Las ganancias se cortan por ROI (+1,7 % y +1,0 %) y las pérdidas las cierra la señal de salida, que llega tarde (−3,9 % y −4,0 % de media; 297 y 323 operaciones). Salidas por ROI: +1.086 y +1.111 USDT; por señal: −1.161 y −1.289 USDT. La señal exige que *todas* las condiciones bajistas se cumplan, es decir, cuando el precio ya cayó. Las peores operaciones (−10 a −14 %) son todas salidas por señal con MFE ≈ 0–4 %.

**C3 — DEMOSTRADA (BbandRsi): colas de −25 %.** 8 stoploss (−25 %) suman −201 USDT frente a +206 del resto (ROI +120, señal +89): el 3 % de las operaciones anula toda la ventaja. Son compras de "cuchillo cayendo" (MFE ≈ 0 %, MAE −25 a −33 %). Tampoco se arregla con stop más ajustado (ver §4: los datos lo contradicen).

**C4 — DEMOSTRADA (ReferenceSmaCross): stoploss −5 %.** 64 stops = −332 USDT; las 763 salidas por señal dan +282.

**C5 — DEMOSTRADA, en 3 estrategias de tendencia: entrar en largo con el par en caída de 30 días.** Retorno 30d del par < −10 % antes de entrar (dato pasado):

| Periodo | Estrategia | Ops con ret30<−10 % | Neto | PF | Resto: neto / PF |
|---|---|---|---|---|---|
| DEV | ReferenceSma | 79 | −62,2 | 0,55 | +24,6 / 1,05 |
| OOS | ReferenceSma | 75 | −54,1 | 0,57 | +62,9 / 1,20 |
| DEV | Supertrend | 41 | −37,3 | 0,54 | +81,9 / 1,18 |
| OOS | Supertrend | 58 | −36,2 | 0,61 | −67,4 / 0,87 |
| DEV | ADXMomentum | 68 | −49,7 | 0,50 | −34,7 / 0,94 |
| OOS | ADXMomentum | 96 | +0,9 | 1,01 | −88,4 / 0,83 |

Consistente en DEV y OOS para ReferenceSma y Supertrend (PF 0,54–0,61 en el régimen), no para ADXMomentum. Mecanismo plausible: largos de seguimiento de tendencia dentro de caídas = rebotes que fallan. Esto es consistente, pero **no es causa única**: aun filtrando, Supertrend y ADXMomentum siguen perdiendo en OOS.

**Hipótesis plausibles (no confirmadas):**
- H1: Supertrend/ADX ganan más en semanas direccionales (eficiencia 7d alta: +57 y +59 USDT) y pierden en ruido (−48/−127). Patrón claro en ambas, pero no probado fuera de muestra; es un segundo filtro que no se testeó (solo una hipótesis permitida).
- H2: BbandRsi gana más en tendencia alcista 30d (PF 2,7) y pierde en lateral (PF 0,6), pero en bajista PF 1,24: no monótono, n pequeño (62–144) → probablemente ruido.
- H3: la diferencia entre pares (BNB mejor en ReferenceSma y BbandRsi; ETH mejor en Supertrend/ADX; BTC peor en casi todas) refleja que BNB subió +140 % y BTC/ETH se movieron menos; el periodo no permite separar "par" de "periodo". No concluyente.

**Concentración temporal.** 19–26 de 34 meses son negativos en las estrategias de tendencia/momentum; los peores meses se repiten (2025-02/03, 2024-04, 2024-08, 2026-06): caídas rápidas que activan entradas y stops en varios pares a la vez. Correlación BTC–ETH 0,82, BTC–BNB 0,68–0,71, ETH–BNB 0,68–0,73 → los 3 pares **no** diversifican de verdad en una caída.

**Metodología del backtester (a vigilar, no demostrado como error):** (a) 1 posición con stake 99 de wallet 100 se bloquea tras la primera pérdida (saldo < stake): artefacto de dimensionamiento, no de la estrategia; (b) Supertrend/Regime necesitan warm-up de 199/920 velas, así que su fecha de inicio difiere; (c) stops intravela se rellenan al precio del stop (sin slippage ni huecos): optimista para colas; (d) no hay slippage ni profundidad de libro; (e) las señales de ROI/stop en la misma vela dependen del modelo de orden de Freqtrade.

## 2. Capital de 100 USDT en Binance Spot

- Mínimos (ccxt público, sin claves): notional mínimo **5 USDT** en BTC/ETH/BNB; pasos de cantidad 0,00001 BTC, 0,0001 ETH, 0,001 BNB (≈0,75 USDT al precio actual del BNB: el redondeo es pequeño). Comisión taker=maker 0,1 % (Freqtrade la aplica en entrada y en salida; un BNB para comisiones daría un descuento que **no** asumo).
- Con 100 USDT, `tradable_balance_ratio` 0,99 y stake del 99 %, el saldo cae por debajo del stake tras cualquier pérdida o comisión → no puede reentrar (artefacto (a)). El capital disponible ≠ el que conviene exponer.
- Resultados con wallet 100, periodo 20240201→20261012 (comparativa **de exposición**, no de estrategia):

| Exposición máx. | Posiciones×stake | Supertrend | SupertrendRegime | ReferenceSma | BbandRsi | ADXMomentum |
|---|---|---|---|---|---|---|
| ~99 % | 2×49,5 | +1,8 / DD 40 % | +23,0 / DD 30 % | −27,8 / DD 54 % | −28,3 / DD 50 % | −4,4 / DD 39 % |
| ~99 % | 3×33 | −9,9 / DD 42 % | +1,2 / DD 35 % | −7,7 / DD 39 % | −23,0 / DD 46 % | −29,8 / DD 46 % |
| ~33 % | 1×33 | +15,4 / DD 17 % | +18,6 / DD 14 % | −3,7 / DD 28 % | −2,9 / DD 22 % | +3,1 / DD 25 % |
| ~33 % | 2×16,5 | +1,6 / DD 18 % | +7,8 / DD 13 % | −7,1 / DD 22 % | −6,7 / DD 22 % | −9,5 / DD 20 % |
| ~33 % | 3×11 | −5,7 / DD 21 % | +0,3 / DD 15 % | −3,2 / DD 18 % | −0,1 / DD 14 % | −17,9 / DD 25 % |
(Beneficio en USDT sobre 100; DD en % de cuenta.) Con 1 posición se pierden miles de señales (p. ej. 7.060 rechazadas en SupertrendRegime): el resultado depende de qué señal cae en el hueco, es ruido de trayectoria, no ventaja. La fila "1×33 positiva" **no** debe leerse como mejora. 3×11 equivale a los backtests de wallet 1000 escalados.
- Escenarios de pérdida sobre 100 USDT (exposición total E, caída simultánea de todas las posiciones, correlación 0,7–0,8): pérdida = E × caída. Con E=33 %: −5 % → −1,7 USDT; −10 % → −3,3; −15 % → −5,0; stop −25 % (BbandRsi) → −8,3. Con E=99 %: −5 % → −5; −10 % → −10; −15 % → −15; −25 % → −25. Rachas observadas: ReferenceSma 21 pérdidas seguidas, Supertrend 11, ADX 6, BbandRsi 6; con pérdida media 2–3 % y E=33 %, 11 pérdidas ≈ −10 % de la cuenta; 21 × −1,98 % ≈ −14 %.
- Comisiones por ronda: 0,2 USDT con stake 100; 0,022 USDT con stake 11. El porcentaje es el mismo (0,2 %), por lo que el tamaño no mitiga C1. Con stake 11, el mínimo de 5 USDT deja margen (y 16,5 / 33 más).
- **Configuración de riesgo con sentido (provisional, para dry-run, no para dinero real):** exposición máxima ≈ 33 % (33 USDT), resto en reserva (cubre comisiones y evita el bloqueo); 1–3 posiciones; no tratar 3 pares como diversificación (correlación ≥0,7); sin apalancamiento, sin shorts; stop duro de pérdida diaria/total a definir antes de dinero real (p. ej. pausar tras −10 %: umbral provisional, no validado). Con las estrategias actuales, ninguna justifica operar con dinero real.

## 3. Hipótesis correctiva probada (una sola)

- **Estrategia de referencia:** Supertrend. Criterio decidido antes de probar, usando solo DEV: mayor PF tras aplicar el filtro de C5 (DEV: Supertrend 1,18 > ReferenceSma 1,05 > ADX 0,94), con suficientes operaciones (>400) y siendo estrategia externa. **Transparencia:** vi las cifras OOS del filtro (tabla C5) al decidir si merecía probarse, por lo que el OOS **no es virgen** para esta hipótesis; el umbral −10 % es un número redondo fijado a priori (no ajustado), y no hice ninguna iteración posterior.
- **Problema observado:** entradas de largo con el par en caída de 30 días (PF ≈ 0,55 en dos periodos).
- **Cambio:** `SupertrendRegime` (hereda de `Supertrend`, que queda intacta): no entrar si `close.pct_change(720) < −10 %`. Únicamente el filtro de entrada; mismas salidas, ROI, stop y parámetros. `startup_candle_count` pasa a 920 (warm-up del filtro).
- **Comparación** (misma ventana desde 2024-02-01, wallet 1000, stake 100, 3 posiciones, comisión 0,1 %):

| | Ops | Neto | DD | PF | Exp./op | Ops/mes |
|---|---|---|---|---|---|---|
| Supertrend DEV (2024-02→2025-06) | 436 | +4,82 % | 11,2 % | 1,09 | +0,11 % | ≈28 |
| SupertrendRegime DEV | 399 | +6,83 % | 8,2 % | 1,15 | +0,17 % | ≈26 |
| Supertrend OOS (2025-06→2026-10) | 464 | −10,36 % | 17,1 % | 0,83 | −0,22 % | ≈28 |
| **SupertrendRegime OOS** | 414 | **−6,75 %** | 13,7 % | **0,87** | **−0,16 %** | ≈25 |

- **Comprobaciones:** `lookahead-analysis` sin sesgo (`out/SupertrendRegime_lookahead-analysis.txt`); `recursive-analysis` sin sesgo de indicadores.
- **Lectura:** el filtro reduce la pérdida OOS (−10,4 % → −6,8 %) y el drawdown, y mejora DEV, de forma consistente con la causa C5; pero **no produce expectativa positiva fuera de muestra** (−0,16 %/op, PF 0,87). Mejora, no es una ventaja. La mejora OOS (~+3,6 pp) es del mismo orden que la dispersión entre configuraciones, con OOS no virgen: no concluyente.
- Descartada con datos: **stop más ajustado en BbandRsi**. En DEV, 47 operaciones tocan −8 % de MAE y acaban en −272 USDT; cortarlas en −8 % daría ≈ −385: peor, porque muchas se recuperan parcialmente por la salida RSI>70. No se probó.

## 4. Decisión y limitaciones

- **Ninguna estrategia es apta para dinero real.** Criterios (expectativa neta > 0 OOS, DD aceptable, costes realistas, muestra suficiente): ninguna los cumple. SupertrendRegime es la mejor candidata para *seguir investigando*, no para operar: OOS −6,8 %, PF 0,87.
- Un solo régimen de mercado en OOS (plano/bajista, −1,5 %), 3 pares muy correlacionados, sin slippage, OOS no virgen para la hipótesis, estrategias con parámetros de terceros.
- **Qué falta demostrar:** (1) ventaja bruta por operación claramente > 0,2 % de coste (idealmente ≥ 2–3× el coste), lo que apunta a menor frecuencia/mayor recorrido por operación (p. ej. timeframe 4h/1d) más que a nuevos filtros; (2) un periodo y/o pares nuevos que no hayan influido en ninguna decisión (más historia desde 2020–2023, otros pares líquidos); (3) modelo de ejecución realista (slippage, stops con huecos); (4) semanas de dry-run en vivo comparando con el backtest; (5) política de pérdida máxima definida antes de arriesgar dinero.

## Archivos añadidos/cambiados
`user_data/strategies/SupertrendRegime.py` (nuevo); `research/strategy-audit/` (AUDIT.md, audit.py, audit_output.txt, regime_check.py, run_phase2.sh, out/); `research/strategy-shortlist/load_trades.py` (nuevo, helper); sin tocar `config.json`, Laravel, BD ni estrategias originales. Sin commits.
