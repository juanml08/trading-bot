# Preregistro (escrito ANTES de ejecutar el barrido completo ni ver resultados A vs B)

Fecha de redacción: 2026-10-11. Nada de lo siguiente se modifica después de ver los resultados; si algo cambia, se documenta como desviación.

## Universo y datos
- Los 30 símbolos reales del bot (primeros 30 pares USDT en estado TRADING de exchangeInfo, incluidos USDCUSDT y TUSDUSDT; no se excluyen stablecoins porque producción tampoco las excluye).
- Velas 30m de `demo-api.binance.com` (mismo host que `BINANCE_BASE_URL`), 2025-09-01 → 2026-10-11, sin relleno de huecos.
- Instantes de evaluación: cada cierre de vela de 30m con ventana completa de 20 días (959 velas) y horizonte futuro completo (32 velas).

## Grupos
- **A**: selector real (Discovery → Validation → Pareto → desempate) sin modificar. Réplica validada contra la BD (ver REPORTE).
- **B (control principal, "B_surv")**: estrategia elegida al azar, uniforme, entre las que superaron Discovery y Validation en el mismo (símbolo, instante). Aísla el valor de Pareto + desempate.
- **B_front** (secundario): al azar entre el frente de Pareto.
- **B_cat** (secundario): al azar entre las 400 estrategias del catálogo en los mismos instantes de decisión de A. Aísla el valor de los filtros Discovery+Validation.
- **C** (referencia): comprar y mantener el mismo símbolo durante el mismo intervalo que el ciclo de A.

## Diseño (capa 1, evento pareado) y réplica secuencial (capa 2)
- Capa 1: por símbolo, se recorren los instantes en orden; si A selecciona y el símbolo no tiene ciclo activo de A, se registra una decisión y el símbolo queda ocupado hasta que termina el ciclo de A. En cada decisión, A y B se evalúan sobre la misma ventana futura con las mismas reglas.
- Capa 2: réplica de la operativa completa (scanner por volumen, hasta 20 ciclos simultáneos, capital por slot = disponible/20) para A y para B con R=200 semillas (semilla base 20261011). Se usa para exposición, drawdown de cartera y retorno de cartera.

## Reglas de ejecución simuladas (ver REPORTE sección 3)
Stop 3% evaluado sobre cierre de vela en cada tick (salida al cierre, no al -3%), salida por tiempo a 16 ticks (8h), SELL con confirmación en la vela siguiente, BUY por estado (media corta > larga), ciclo HOLD expira a las 8h sin BUY, ventana viva de 199 velas, una evaluación por vela de 30m.

## Costes (por lado) — escenarios fijados de antemano
| Escenario | Comisión | Slippage |
|---|---|---|
| S0 (bot tal cual) | 0 | 0 |
| S1 optimista | 0.075% | 0.02% |
| **S2 base** | 0.10% | 0.05% |
| S3 pesimista | 0.10% | 0.10% |

El escenario base para el veredicto es **S2**. Se reportan todos.

## Métrica primaria y estadística
- Δ = (1/N) Σ_i [ r_A,i − mean_{s ∈ Surv_i} r_s,i ], con r = retorno neto sobre el capital del slot en S2 (0 si el ciclo expira sin entrar). N = nº de decisiones de A.
- IC 95%: bootstrap por bloques (bloques de calendario de 14 días, agrupando todos los símbolos para respetar la correlación cruzada y la dependencia temporal), 10.000 remuestreos, percentiles 2.5/97.5. Sensibilidad con bloques de 7 y 28 días.
- Percentil de A dentro de la distribución de B con 2.000 sorteos aleatorios sembrados (semilla 20261011).
- Umbral de relevancia práctica: |Δ| ≥ 0.10% por decisión (≈ un tercio del coste ida y vuelta base 0.30%).

## Criterios de veredicto (fijados de antemano)
- **Evidencia favorable**: Δ > 0 en S2, IC95% excluye 0, Δ ≥ 0.10%, y la ventaja no depende de un único activo, mes ni evento extremo: Δ sigue > 0 al excluir cada símbolo individualmente, al excluir cada mes individualmente y al recortar el 1% superior de r_A.
- **Evidencia desfavorable**: Δ < 0, IC95% excluye 0, con signo negativo también en la mayoría de los meses.
- **Sin evidencia de ventaja**: cualquier otro caso (IC incluye 0 o |Δ| < 0.10%).
- Pregunta separada (no es A vs B): ¿la expectancy neta de A en S2 es positiva con IC95% que excluye 0?

## Pruebas exploratorias (no entran en el veredicto)
Resultados por símbolo, mes, régimen BTC, escenarios de coste, B_front, B_cat, buy&hold, universos nulos (retornos permutados) para calibrar falsos positivos y tamaño del IC. Se declaran como exploratorias y no se ajustan por comparaciones múltiples; solo la métrica primaria tiene valor confirmatorio.
