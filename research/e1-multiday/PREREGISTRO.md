# Preregistro E1 — Ruptura (B) y expansión de volatilidad (C) con hold de 24/48/72 h

Fecha de cierre: 2026-10-11, escrito ANTES de ejecutar `e1.py`. El script registra el SHA-256 de este archivo en `results/run_meta.json`. Cualquier cambio posterior se documenta como **desviación** en el informe; un cambio en una hipótesis primaria o en un criterio exige detenerse y pedir autorización.

Estudio offline y de solo lectura. Reutiliza `research/signal-audit/data4h` y las funciones `load`, `signals`, `cooldown` de `audit_4h.py` (importadas sin modificar). No hay red, base de datos ni código de producción. Sigue `reglas.md`.

## 1. Estatus de la evidencia
Todo el calendario 2022-01 → 2026-10-01 ya fue visto por estudios previos (incluida la pista de C a 24–72 h). **No hay holdout virgen.** Este estudio solo puede **descartar** o dejar una **candidata no confirmada**. La confirmación exige datos posteriores a 2026-10-01.

## 2. Hipótesis
- **H_B48 (primaria):** señal B, hold 48 h, salida por tiempo (R1): expectancy neta > 0 con costos base, y exceso > 0 frente a la referencia aleatoria pareada.
- **H_C48 (primaria):** idem para la señal C.
- Las demás variantes (sección 5) son **exploratorias** y no pueden cambiar la decisión sobre las primarias.

## 3. Datos, señales y reglas
- 20 pares de `data4h` (BTC ETH BNB XRP SOL ADA DOGE TRX LINK LTC AVAX DOT ETC BCH ATOM NEAR XLM UNI AAVE IOTA), velas 4h UTC. Sesgo de supervivencia reconocido (pares vigentes hoy).
- **Señal B:** `close[t] > max(high[t-N..t-1])` y la vela anterior no cumplía. **Señal C:** `TR[t] > 2·media(TR[t-N..t-1])` y `close>open`. **N = 24** (único parámetro de las variantes de decisión). Código idéntico al de `audit_4h.signals`. Cooldown 18 velas (72 h) por activo, igual que `audit_4h.cooldown`. Las señales se calculan con datos hasta el cierre de la vela t.
- **Elegibilidad:** t ∈ [800, T−19] (calentamiento de 800 velas, como en `audit_4h`, y 18 velas futuras completas). Es el mismo conjunto para los tres horizontes. Primer t elegible ≈ 2022-05-14.
- **Entrada:** apertura de la vela t+1 (precio de mercado P_e).
- **Horizontes:** 6, 12, 18 velas = 24, 48, 72 h. Salida por tiempo (R1): cierre de la vela t+h.
- **Salida con stop (R2, exploratoria, no optimizada):** R1 más un stop fijo del 8 % bajo P_e (nivel S = 0,92·P_e). Un único valor, no se prueban otros.
  - Se revisa desde la vela de entrada (t+1) hasta la vela de salida por tiempo, incluidas ambas.
  - **Hueco:** si la apertura de la vela j (j>t+1) es ≤ S, sale a esa apertura (peor que S). Si no, y el mínimo de la vela ≤ S, sale en S.
  - **Vela con stop y salida por tiempo a la vez:** gana el stop (supuesto conservador).
  - **Orden intravela:** se desconoce, y se asume que el mínimo ocurre después de la entrada. No hay take-profit, así que no hay otra condición de salida que compita con el stop.
  - El stop paga el mismo deslizamiento que cualquier salida; en la práctica los stops resbalan más, y la sensibilidad de costos lo cubre parcialmente.

## 4. Costos
Comisión c = 0,10 % y deslizamiento s = 0,05 % **por lado, una sola vez por ejecución**:
`neto = (1−c)² · (1−s)/(1+s) · P_salida/P_entrada − 1` (compra a P_e(1+s) con comisión sobre el nominal; venta a P_s(1−s) con comisión sobre el producto). Costo ida y vuelta ≈ 0,2998 %. Los precios P son de mercado (apertura/cierre/nivel del stop), nunca ya ajustados.
**Sensibilidad** por operación completa X ∈ {0,20; 0,30; 0,40; 0,50 %}: se escalan c y s proporcionalmente (relación 2:1) hasta que el costo ida y vuelta exacto sea X. Se reporta también el costo de equilibrio de cada variante.

## 5. Variantes registradas (todas se reportan)
12 variantes = {B, C} × {24, 48, 72 h} × {R1, R2}. Primarias: **B-48h-R1** y **C-48h-R1**. Vecindario N ∈ {18, 21, 27, 30} para B y C a 48 h R1: solo descriptivo (media neta y exceso), sin pruebas ni selección. No se prueba nada más. Si se corre algo más, se declara como exploración adicional.

## 6. Referencia aleatoria pareada
Por (activo × trimestre calendario UTC de la barra t), mismo número k de entradas que las señales reales de esa celda, sorteadas **entre todas las barras elegibles de esa celda** (incluidas barras sin señal, es la referencia incondicional), con la **misma salida, horizonte, costos y cooldown** (≥18 velas entre entradas del mismo activo dentro de la celda; muestreo por permutación aleatoria y aceptación secuencial). Misma exposición por construcción: igual horizonte y misma regla de salida. 2.000 sorteos, semilla 20261011 (`numpy.default_rng`). Si una celda no puede completar k entradas con el espaciado, se usan las aceptadas y se registra el déficit. Limitación: el espaciado no se impone entre celdas adyacentes.
- Esperanza de la referencia de cada celda = media de los sorteos. **Exceso de una señal = retorno neto de la señal − esperanza de su celda.**
- Validaciones obligatorias (`selftest`, `placebo`): sorteos distintos y con espaciado; esperanza con espaciado ≈ media analítica; corrección causal de señales; fórmula de costos; reglas del stop; **placebo con desplazamiento circular común de todos los activos** (≥60 días) que conserva agrupamiento y co-movimiento y rompe la relación con el precio, para comprobar que la tasa de falsos positivos del bootstrap esté cerca de lo nominal.

## 7. Incertidumbre y multiplicidad
- **Bootstrap por bloques:** bloques de calendario de 14 días definidos por la fecha de la barra de señal, **agrupando todos los activos** (preserva la dependencia temporal y cruzada). 10.000 remuestreos con semilla 20261011. El mismo remuestreo de bloques para todas las variantes. Estadístico: razón de sumas (media por operación). Sensibilidad con bloques de 7 y 28 días. Limitación: operaciones que cruzan el borde de un bloque quedan en el bloque de su señal.
- **p-valor del exceso:** `p = (1 + #{remuestreos con exceso medio ≤ 0}) / (10.000 + 1)`, unilateral. (El p de los sorteos de la referencia se reporta como complemento; no se usa para decidir porque no recoge el agrupamiento temporal de las señales.)
- **Holm, familias definidas ahora:** **F1** = {p_exceso(H_B48), p_exceso(H_C48)}, α = 0,05. **F2** = las otras 10 variantes, Holm aparte, **solo descriptivo**; nunca sustituye ni modifica a F1. Los IC de expectancy neta no se ajustan: son condiciones adicionales conjuntas (intersección), no pruebas separadas.

## 8. Métricas (todas las variantes)
Operaciones cerradas; win rate; ganancia y pérdida medias; expectancy neta; P/L neto acumulado (suma de retornos de operación); profit factor neto; peor operación y percentil 5; mayor racha de pérdidas (en orden de salida, desempate por índice de activo); por activo; por año calendario; por régimen BTC (retorno de BTC a 180 velas = 30 días antes de la señal: > +10 % alcista, < −10 % bajista, resto lateral); sensibilidad a los 4 costos; costo de equilibrio; exceso frente a la referencia con IC.
**Serie de capital y drawdown (definición única).** Cartera con capital inicial 1,0. Al cierre de la vela t se calcula el patrimonio E_t (efectivo + valor de liquidación neta de las posiciones abiertas, marcadas al cierre de t con salida hipotética con deslizamiento y comisión). La señal abre en t+1 una posición de **10 % de E_t**; si el efectivo no alcanza, **la operación se omite y se cuenta**. Así las operaciones simultáneas **comparten** un mismo capital y nunca se tratan como capital independiente. El patrimonio se registra al cierre de cada vela de 4h (salidas procesadas antes). **Drawdown máximo** = peor caída pico-valle de esa serie (pico inicial 1,0). Se informan también el retorno total de la cartera, la exposición media y el número de omitidas. La misma simulación se aplica a 100 sorteos de la referencia (mediana y rango de retorno y drawdown).

## 9. Criterios de decisión (aplicados a las dos primarias, a costo base 0,30 %)
**Candidata (no confirmada)** solo si se cumplen **todas**:
1. Expectancy neta > 0 y límite inferior del IC95 % (14 d) > 0.
2. Exceso > 0 y p ajustado por Holm (F1) < 0,05.
3. Expectancy neta media > 0 con costo de 0,40 %.
4. Expectancy neta > 0 en las **dos mitades cronológicas** (corte en el punto medio del calendario elegible) y en ≥ 60 % de los años calendario con n ≥ 30 señales.
5. Ningún activo aporta más del 30 % de la suma del P/L neto, y sin el mejor activo (por suma neta) la expectancy neta sigue > 0.
6. Profit factor neto ≥ 1,15.
7. Drawdown máximo de la cartera (sección 8) ≤ 15 %.
8. n ≥ 150 señales y ≥ 30 bloques de 14 días con señales.

**Descartar** si la expectancy neta ≤ 0 a 0,30 %, o si el límite superior del IC95 % del exceso < 0.
**Investigar más** en cualquier otro caso. Con cualquier veredicto, las exploratorias no cambian la decisión; una candidata solo puede confirmarse con datos posteriores a 2026-10-01 (forward) y un nuevo preregistro.

## 10. Reproducción
`python -I e1.py selftest` · `python -I e1.py run` · `python -I e1.py placebo` (en `research/e1-multiday`; ver `REPORTE.md`). Requiere `numpy`. Salidas en `results/`.
