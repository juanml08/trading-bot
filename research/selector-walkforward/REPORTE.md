# ¿Tiene ventaja real el selector de estrategias? — Experimento offline walk-forward

Fecha: 2026-10-11. Sin cambios en código productivo, `.env`, configuración, tests, migraciones ni BD (solo SELECT con sesión READ ONLY). Sin commits. Todo en `research/selector-walkforward/`. Criterios fijados antes de ver resultados en `PREREGISTRO.md`.

## 1. Resumen ejecutivo

**No hay evidencia de que el selector tenga ventaja. El experimento descarta ventajas mayores de ≈0,02% por decisión.**

- A (selector real) − B (azar entre supervivientes de Validation): **Δ = −0,001% por decisión, IC95% [−0,017; +0,016]** (bootstrap por bloques de 14 días, 28 bloques, 4.566 decisiones, 12,6 meses). Con bloques de 7 y 28 días el resultado es el mismo.
- A en la réplica de cartera (20 slots, 200 semillas de B): retorno −0,8% (B mediana −0,5%) sin costes; **−33,7% (B −33,3%) con costes base**. A cae en el percentil 21–34 de B: indistinguible.
- Sin ventaja bruta: expectancy de A ≈ −0,009% por operación antes de costes. Con costes base (0,10% comisión + 0,05% slippage por lado) pierde ≈ 0,31% por operación; PF 0,64; WR neto 33%.
- Pasar Validation tampoco predice el futuro: las estrategias que aprueban rinden igual o algo peor (−0,03%, IC [−0,06; −0,005] en S2) que el catálogo completo.
- Con retornos permutados (sin estructura alguna) el pipeline aprueba y selecciona **más** a menudo que con datos reales (17–20% de los instantes vs 10,3%). Las aprobaciones son compatibles con azar.

## 2. Entorno y datos

- Universo: los 30 símbolos reales del bot (primeros 30 USDT en exchangeInfo, incluidos USDC y TUSD). Velas 30m de `demo-api.binance.com`, 2025-09-01 → 2026-10-11: 583.230 velas, **0 huecos, 0 duplicados, 0 fuera de grilla, 0 OHLC inválidas** (`results/calidad_datos.json`). 1.416 velas con volumen cero y 5.760 planas (stablecoins/baja liquidez), 156 saltos >10% (reales, no se filtraron).
- Evaluación: 2025-09-21 → 2026-10-09 (~12,6 meses), 554.430 ejecuciones del pipeline (una por cierre de vela y símbolo) × 400 estrategias.
- Limitación: el histórico de demo-api puede diferir de mercado real; se verificó que reproduce exactamente lo que el bot vio (ver §3).

## 3. Fidelidad de la simulación

Validado contra producción y contra oráculos independientes:

| Prueba | Resultado |
|---|---|
| 150 evaluaciones guardadas en BD × 400 estrategias (TRAIN, VALIDATION, flags Discovery/Validation) | 60.000/60.000 idénticas |
| Núcleo vs traducción literal decimal bcmath del PHP (288 casos, incl. stablecoins) | 0 diferencias |
| Ciclo simulado vs traducción literal de `AutomaticTradingCycle` (200 casos) | 0 diferencias |
| 22 ciclos reales (cerrados/expirados) vs simulación | 21 coinciden en precios de entrada/salida, motivo y duración; el otro difiere por desfase de segundos entre activación y tick |
| Tasa de selección en la ventana de producción | 7,12% simulada vs 7,1% real (370/5.197) |
| Test anti-fuga (alterar todas las velas futuras) | 0 fugas |

Reglas reproducidas: ventana 20 días (959 velas), split 50/50, 400 estrategias SMA/EMA, umbrales Discovery (5 trades, WR≥30, DD≤15, P/L≥0) y Validation (6, WR≥60, DD≤10, P/L≥0), Pareto + desempate, BUY por estado (corta>larga), SELL en cruce con confirmación en la vela siguiente, stop 3% comprobado al cierre de cada tick (sale al cierre, no al −3%), salida por tiempo al 16º tick (8h), expiración HOLD a 8h, ventana viva de 199 velas, relleno al cierre de la última vela cerrada.

Diferencias inevitables/aproximaciones (documentadas): ticks alineados a cierres de vela (en producción tienen fase y deriva ~1 min/tick); búsqueda en cada vela de 30m (producción reintenta cada 15 min, pero sin vela nueva el resultado es idéntico); EMA en float con tolerancia 1e-13 (SMA exacta en enteros); operaciones planas replican el truncado de bcmath; el fill es el cierre de la vela anterior (producción usa un ejecutor simulado sin costes); costes añadidos solo en la simulación futura, el selector no se modifica; capital por slot = efectivo libre/20 (`MAX_ACTIVE_CYCLES=20` en `.env`); los ciclos 1–13 reales usaron hold de 4h, se simula 8h como pediste.

Hallazgos de código relevantes (sin modificar nada): el backtest solo sale por cruce, el vivo añade stop/tiempo/confirmación, así que las métricas de Validation no describen las operaciones reales; `StrategyEvaluator` y el ejecutor usan costes 0; en Validation los indicadores se reinician (con largo 200 quedan 280 velas útiles); la ventana viva de 199 velas hace que largo=200 nunca opere (A nunca lo elige); el stop de 3% pierde en promedio **3,7%** (21% de los stops >4%, máx 7,9%).

## 4. Diseño experimental

- Walk-forward: en cada cierre de vela, Discovery sobre la primera mitad de los 20 días previos, Validation sobre la segunda, selección solo con velas cerradas hasta ese instante; el ciclo se simula después. Comprobado que alterar velas posteriores no cambia nada.
- **Capa 1 (primaria, pareada):** por símbolo se recorren instantes; si A selecciona y no hay ciclo de A activo, se registra una decisión y el símbolo queda ocupado hasta que termina el ciclo. En cada decisión se simula A y todas las 400 estrategias con las mismas reglas, costes y ventana futura. B_surv = media exacta sobre supervivientes (equivale a muestreo uniforme; además 2.000 sorteos sembrados, semilla 20261011). B_front = frente de Pareto; B_cat = catálogo completo. Dependencia: bloques de calendario de 14 días con todos los símbolos agrupados; 28 bloques; 673 pares símbolo-semana distintos; autocorrelación lag-1 de A ≈ 0,003 dentro del símbolo.
- **Capa 2:** réplica secuencial de cartera (scanner por volumen, hasta 20 ciclos, 200 semillas × 4 escenarios de coste).
- 54% de las decisiones tienen un único superviviente (A≡B por construcción); se reporta también solo el 46% informativo.

## 5. Resultados A vs B (capa 1, escenario base S2; % por decisión sobre capital del slot)

| | A | B_surv | B_front | B_cat | B&H mismo tramo |
|---|---|---|---|---|---|
| S0 (sin costes) | −0,006 | −0,006 | −0,005 | +0,011 | −0,020 |
| S2 (base) | −0,215 | −0,214 | −0,213 | −0,184 | −0,320 |

- Métrica primaria Δ(A−B_surv) S2: **−0,0008% [−0,017; +0,016]**. Solo decisiones informativas: −0,002% [−0,040; +0,033].
- A−B_front: −0,002% [−0,011; +0,007]. A−B_cat: −0,031% [−0,075; +0,005].
- A vs buy&hold: +0,105% [+0,028; +0,190], pero refleja que el mercado cayó durante los tramos y que A pasa tiempo en efectivo; no es ventaja del selector (B también la tiene).
- Estadísticas de A (S2): entra en 69,6% de los ciclos; WR neto 32,8% (bruto 41,8% vs 66,7% mediana en Validation); expectancy −0,31% por operación cerrada; PF 0,64; salidas: 48% por tiempo, 46% por SELL, 5% por stop; duración media 5,9h; exposición media 8,6% del capital.
- Selección: A usa 179 estrategias distintas, 91% SMA, las 10 más frecuentes suman 38% y en 43% de las decisiones repite la estrategia de la decisión anterior del mismo símbolo. Mediana de 8 trades en Validation; 83% con ≤10.

## 6. Estabilidad

- Por símbolo (S2): Δ entre −0,10 (THETA) y +0,04 (BNB/ONT/NEO), sin patrón; la pérdida de A está repartida (CELR 11%, TRX 9%, ninguno domina; FET/ATOM/ONG ≈ 0,3%/5,8%/3,3%). Excluir cada símbolo no cambia el signo de forma consistente (Δ entre −0,003 y +0,002).
- Por mes: Δ alterna de signo (−0,082 en ene-2026 a +0,072 en oct-2026); A pierde en 13 de 14 meses en S2.
- Por régimen BTC 7d: subida −0,007; plano 0,000; bajada +0,008. Sin régimen favorable consistente.
- Tabla completa en `results/summary_real.json` (`per_symbol`, `per_month`, `per_regime`).

## 7. Costes y riesgo

| Escenario | A (%/decisión) | Δ A−B [IC95% 14d] | Cartera A final | B mediana [2,5–97,5] |
|---|---|---|---|---|
| S0 sin costes | −0,006 | +0,0005 [−0,016; +0,017] | −0,8% | −0,5% [−2,3; +1,2] |
| S1 (0,075%+0,02%) | −0,138 | −0,000 [−0,017; +0,016] | −23,1% | −22,8% |
| S2 base (0,10%+0,05%) | −0,215 | −0,001 [−0,017; +0,016] | −33,7% | −33,3% [−34,5; −32,0] |
| S3 (0,10%+0,10%) | −0,284 | −0,001 [−0,018; +0,015] | −42,0% | −41,6% |

Drawdown máximo de cartera A: 11,6% (sin costes), 36,9% (S2); B ≈ igual. Los 20 slots solo se llenan el 0,18% del tiempo; media de 3,8 ciclos activos. Ni siquiera el escenario optimista S1 es rentable: el coste ida y vuelta (≥0,19%) supera por mucho cualquier expectancy bruta (≈0).

## 8. Comparaciones múltiples y calibración

- Con retornos permutados (4 universos, semillas 1–4) el pipeline pasa Discovery 48–51 estrategias por evaluación (37 en real), Validation 0,87–1,13 (0,45 en real) y selecciona en 17–20% de instantes (10,3% real). Aprobar con WR≥60 y ~8 trades es normal por azar.
- En los 4 nulos Δ(A−B) tuvo IC95% que incluye 0 (rango de puntos −0,001 a +0,010), coherente con el tamaño nominal del IC.
- La mejor estrategia fija a posteriori sobre los instantes de decisión rinde +0,05% bruto (SMA 4/35) frente a una mediana de +0,009%: es lo esperable al elegir el máximo de 400 y no puede presentarse como elegida de antemano.

## 9. Limitaciones

- Una sola ventana histórica (12,6 meses, un régimen bajista/lateral para el universo; B&H medio negativo). No prueba nada sobre otros regímenes ni otros universos o timeframes.
- Decisiones dependientes (misma señal de mercado entre símbolos); por eso la inferencia usa 28 bloques, no 4.566 observaciones.
- Fill al cierre de vela y slippage fijo; no hay profundidad de libro, liquidez baja en alts ni slippage mayor en stops (S3 lo aproxima).
- Réplica de ticks alineados (sin fase/deriva), EMA en float, hold 8h aunque los primeros ciclos reales usaron 4h.
- Demo-api puede diferir del mercado real en liquidez.
- B_surv aísla solo el valor de Pareto/desempate; el poder para detectar Δ es alto (±0,017%) pero la hipótesis relevante (¿ganar dinero?) es otra: ahí A es negativo con IC que excluye 0 (S2: −0,215% [−0,29; −0,14]).

## 10. Veredicto y siguiente paso

**Veredicto: sin evidencia de ventaja** (Δ≈0, IC95% incluye 0 y es tan estrecho que descarta efectos de relevancia económica; el criterio "favorable" no se cumple). Adicionalmente, y no como veredicto A vs B: la expectancy neta de la estrategia completa es **negativa** con IC que excluye 0, es decir, el sistema tal como está pierde dinero tras costes realistas, y no por mala suerte del selector sino porque el conjunto SMA/EMA a 30m no tiene ventaja bruta.

**Siguiente paso recomendado: descartar esta hipótesis de estrategia (cruces SMA/EMA a 30m con selector por Validation) y rediseñar** antes de cualquier ajuste de parámetros: (1) la familia de señales (ver la auditoría 1h/4h previa en `research/signal-audit`), (2) la validación (muestras de ~8 trades no discriminan), (3) incluir costes y reglas de salida reales en el backtest. Cualquier cambio debe probarse en un experimento nuevo con este mismo arnés (Capa 1 + nulos).

## Reproducción

`bash run_all.sh` (ver el archivo y `run_nulls.sh`). Resultados numéricos: `results/summary_real.json`, `summary2_real.json`, `layer2_real.json`, `summary_null{1-4}.json`, `calidad_datos.json`. Semillas: bootstrap y sorteos 20261011; universos nulos 1–4. Tests: `test_core.py`, `test_cycle.py`, `test_leakage.py`, `validate_db.py`, `validate_cycles.py`.
