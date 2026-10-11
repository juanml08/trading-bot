# Preregistro E2 — ¿La señal C aporta algo más que mercado y régimen?

Cerrado el 2026-10-11, escrito ANTES de escribir o ejecutar `e2.py`. `e2.py` registra el SHA-256 de este archivo en `results/run_meta.json`. Cualquier cambio posterior es una **desviación** que se documenta en el informe; un cambio en la hipótesis primaria, la referencia primaria o un criterio exige detenerse y pedir autorización.

Estudio offline y de solo lectura (sin red, sin BD, sin código de producción, sin órdenes). Reutiliza `research/signal-audit/data4h`, `audit_4h.py` y funciones de `research/e1-multiday/e1.py` importadas sin modificar. Sigue `reglas.md`.

## 1. Estatus de la evidencia
Todo el calendario (2022-05-14 → 2026-09-27 de barras elegibles) ya fue visto; C se descubrió en estos datos. **No hay holdout.** E2 solo puede debilitar o reforzar una pista; nunca confirmar rentabilidad. El exceso frente a otros activos o BTC (referencia C) es una **descomposición diagnóstica**, no una prueba independiente de rentabilidad.

## 2. Hipótesis
- **H_E2 (única primaria):** la diferencia de expectancy neta por operación entre las señales C y su referencia aleatoria **pareada por activo, fecha cercana y régimen** (referencia B) es > 0.
- Nula: D ≤ 0. Una sola hipótesis primaria → sin corrección por multiplicidad. Todo lo demás es secundario/descriptivo y **no puede cambiar el veredicto**.

## 3. Parámetros fijos de C (idénticos a E1)
N = 24 velas de 4h (96 h); condición `TR[t] > 2·SMA(TR[t-N..t-1])` y `close>open`; cooldown 18 velas (72 h) por activo; entrada en la apertura de t+1; salida por tiempo al cierre de t+12 (48 h, regla R1, sin stop); elegibilidad t ∈ [800, T−19]; 20 pares de `data4h`. Costos: comisión 0,10 % y deslizamiento 0,05 % por lado, una vez por ejecución: `neto = (1−c)²(1−s)/(1+s)·P_sal/P_ent − 1` (ida y vuelta 0,2997 %). No se prueban otros N, horizontes ni stops.

## 4. Paso 0 — reproducción de E1 (puerta)
Antes de cualquier análisis, `e2.py` recalcula C-48h-R1 con `audit_4h.signals/cooldown` y `e1.ratios` y lo compara con `../e1-multiday/results/variants.json`. Debe cumplirse: n = 3.397; expectancy dentro de 1e-6 del valor de E1 (+0,5630 %); win rate, PF, ganancia/pérdida media, racha máxima (25) y cartera (retorno +131,7 %, DD 35,0 %, 718 omitidas) idénticos; y exceso frente a la referencia de E1 (misma semilla) dentro de 1e-6. Si algo difiere, el script **se detiene** y no se produce ningún resultado de E2.

## 5. Régimen de mercado (solo datos hasta el cierre de la vela de entrada t)
Sobre BTC (activo 0), en cada barra:
1. **Tendencia:** retorno de 180 velas (30 d): > +10 % alcista, < −10 % bajista, resto lateral (igual que E1).
2. **Volatilidad:** desviación típica (ddof 0) de los retornos simples de las últimas 42 velas (7 d) terminadas en t; su **rango percentil dentro de las 720 velas (120 d) terminadas en t** (inclusive, solo pasado); tercil: < 1/3 bajo, < 2/3 medio, resto alto.
3. **Clave de régimen** = tendencia × tercil de volatilidad (9 celdas). El mismo cálculo se aplica a la barra de referencia. No se usa nada posterior a t.
Covariables de equilibrio (todas al cierre de t): `btc_ret180`, `btc_vol42`, `btc_ret6` (retorno BTC 6 velas), `mkt_ret6` (media equiponderada de los 20 activos, 6 velas). Diagnósticas (no entran en criterios, difieren por construcción porque la señal es una vela de expansión): retorno y TR/media del activo en t, `asset_ret180`.

## 6. Referencia primaria B — emparejamiento
Por cada señal (activo a, barra t) y sorteo, se elige **una** barra de referencia t′ con:
- mismo activo a; t′ elegible; t′ ≠ t;
- misma clave de régimen en t′ que en t;
- **sin señal C en bruto** en (a, t′) (se excluyen las barras que cumplen la condición de C aunque el cooldown las anulara);
- |t′ − t| ≤ ventana, con **nivel 1 = ±14 d (84 velas)**, **nivel 2 = ±28 d (168)**, nivel 3 = ±56 d (336, solo secundaria S4);
- misma salida (t′+1 → t′+12), mismo horizonte y costos;
- cooldown: ≥ 18 velas entre referencias del mismo activo dentro del mismo sorteo. Como una barra elegida bloquea su vecindario, **ninguna barra se reutiliza dentro de un sorteo**.
**Regla de nivel:** se empieza por el primer nivel cuyo conjunto candidato tenga ≥ 5 barras; si en el sorteo todas sus barras están bloqueadas se sube al siguiente nivel permitido. **La primaria permite solo niveles 1 y 2** (nunca 56 d, para no comparar épocas distintas).
**Sorteo:** 2.000 sorteos, `numpy.default_rng(20261011+101)`; en cada sorteo las señales se procesan en orden aleatorio (sin prioridad por fecha); elección uniforme dentro del conjunto candidato (sin vecino más cercano). Se registran todos los sorteos (barra y nivel por señal y sorteo en `results/draws_primary.npz`, resumen por sorteo en `draws_summary.csv`).
**Señales simultáneas:** se tratan como operaciones separadas con su propia pareja; la dependencia entre ellas se absorbe en el bootstrap por bloques de calendario (sección 8).
**Estimador:** m_i = media, sobre los sorteos en que la señal i fue emparejada, del retorno neto de su referencia. Una señal se **incluye** si se emparejó en ≥ 50 % de los sorteos; las demás se **excluyen en ambos brazos** y se informan. d_i = neto_i − m_i. **D = media de d_i** (diferencia de expectancy). También se informa la distribución de D por sorteo.

### 6.1 Calidad del emparejamiento (puerta del veredicto)
Se informan: parejas por nivel (14 d / 28 d / 56 d no usado), señales excluidas, tamaño del conjunto candidato (mediana, p10), distancia temporal media, reutilización entre sorteos (mayor cuota de una misma barra de referencia por señal; distribución), y equilibrio de covariables (diferencia estandarizada SMD = (media_señal − media_ref)/√((var_s+var_r)/2), calculada sobre las señales incluidas con las referencias de todos los sorteos).
**El emparejamiento es ADECUADO solo si se cumplen todas:**
- M1: ≥ 85 % de las señales incluidas.
- M2: mediana del tamaño del conjunto candidato de las incluidas ≥ 10.
- M3: |SMD| ≤ 0,10 en `btc_ret180` y `btc_vol42`; |SMD| ≤ 0,20 en `btc_ret6` y `mkt_ret6`.
- M4: para ≥ 90 % de las incluidas, la barra de referencia más frecuente aparece en ≤ 35 % de sus sorteos.
**Si no es adecuado, el veredicto es INCONCLUSO (emparejamiento inadecuado); no se emite conclusión sobre ventaja ni descarte.**

## 7. Comparaciones secundarias (todas rotuladas «secundaria», no modifican el veredicto)
- **S1:** misma clave, solo nivel 1 (±14 d, sin fallback).
- **S2:** clave solo de tendencia (3 celdas), niveles 1–2.
- **S3:** sin clave de régimen (solo activo y ventana ±14 d/±28 d).
- **S4:** clave primaria permitiendo el nivel 3 (56 d).
- **A (referencia histórica):** activo × trimestre de E1 (`e1.reference_mc`, 2.000 sorteos, semilla de E1).
- **C (referencia de mercado; descomposición diagnóstica):** retorno neto de las mismas 12 velas de (i) la media equiponderada de los otros 19 activos y (ii) BTC (excluidas las señales de BTC). Usa información posterior a la entrada, por lo que **no** sirve para emparejar ni como prueba.
- Los p de las secundarias se reportan con Holm descriptivo (una familia aparte); nunca sustituyen a H_E2.
- Vecindario de N, otros horizontes y stops: **no se ejecutan**.

## 8. Incertidumbre
- **Bootstrap por bloques de calendario** de 14 d (primario), 7 d y 28 d (sensibilidad), definidos por la fecha de la barra de señal, **agrupando todos los activos**: en cada remuestreo se elige un mismo conjunto de bloques de calendario para todos los activos, de modo que los movimientos comunes del mercado nunca se tratan como observaciones independientes. Cada d_i vive en el bloque de su señal. 10.000 remuestreos, semilla 20261011+días (mismos índices que E1 para cada longitud). Estadístico: razón de sumas (media por operación).
- IC 95 % percentil de la expectancy neta de C, de la referencia y de D. **p unilateral de H_E2** = (1 + #{D* ≤ 0})/(10.000+1) con bloques de 14 d.
- Limitación: operaciones que cruzan el borde de un bloque quedan en el bloque de su señal; el IC condiciona a las m_i (2.000 sorteos de la referencia, ruido de simulación reportado aparte).

## 9. Placebo del procedimiento completo
`python -I e2.py placebo`: 200 desplazamientos circulares (≥ 60 d, semilla 20261011+7) de **todas** las señales de todos los activos conjuntamente dentro del calendario elegible (conservan agrupamiento y co-movimiento, rompen la relación señal–precio). Para **cada** desplazamiento se repite el procedimiento completo: señales y máscara en bruto desplazadas, cooldown, elegibilidad, construcción de conjuntos candidatos por régimen y ventana, sorteo de referencias (100 sorteos), regla de inclusión, cálculo de D y bootstrap por bloques de 14 d (2.000 remuestreos), p unilateral e IC. **El procedimiento está calibrado solo si:** tasa de rechazo (p < 0,05 con D > 0) ≤ 9 %; cobertura del IC 95 % de 0 ≥ 92 %; y |media de D placebo| ≤ 0,10 pp. Si no, el veredicto no puede ser «candidata».

## 10. Métricas
Para C y cada referencia: operaciones, win rate, ganancia/pérdida media, expectancy neta, P/L neto acumulado (suma de retornos), profit factor, mayor racha de pérdidas (orden de salida, desempate por activo), peor operación y percentil 5; cartera (10 % del patrimonio por señal, capital compartido, omitir si falta efectivo; `e1.portfolio`) con retorno, drawdown máximo pico-valle, exposición y omitidas (para la referencia: mediana y rango de 100 sorteos); D por activo, trimestre, año, mitad cronológica (punto medio del calendario elegible), régimen de tendencia y celda de régimen; sensibilidad a costos totales 0,20/0,30/0,40/0,50 % (escalando c y s en proporción 2:1 como en E1); diagnóstico de huecos de entrada (open[t′+1]/close[t′]−1) y de señales simultáneas (distribución de señales por barra y por bloque); dependencia de episodios: D sin los 10 bloques de 14 d con más señales y sin los 10 bloques que más aportan a Σd.

## 11. Criterios de decisión (C-48h-R1, costo base 0,30 %, referencia B primaria)
Se aplican en este orden:
0. **Puertas:** reproducción de E1 (sección 4) y emparejamiento adecuado (6.1). Si fallan, se detiene o es **INCONCLUSO**.
1. **DESCARTAR C como candidata** si D ≤ 0, o el límite superior del IC 95 % (14 d) de D < 0, o si la calibración del placebo se cumple y aun así la expectancy neta de C ≤ 0.
2. **CANDIDATA NO CONFIRMADA** solo si se cumplen **todas**: placebo calibrado; D > 0 y límite inferior del IC 95 % (14 d) > 0; límite inferior del IC 95 % con bloques de 28 d > 0; expectancy neta de C > 0 con costo 0,40 %; D > 0 en ambas mitades cronológicas; D > 0 en los tres regímenes de tendencia (alcista, lateral, bajista); ningún activo y ningún trimestre aporta > 30 % de Σd, y D > 0 sin el mejor activo y sin el mejor trimestre.
3. **INCONCLUSO** en cualquier otro caso (incluye: D > 0 con IC que cruza 0, o bloques de 28 d con límite inferior ≤ 0).
El criterio de riesgo de E1 (DD de cartera ≤ 15 %) es independiente y sigue **sin cumplirse** salvo evidencia nueva; aun con «candidata» C no se implementa en el bot ni se cambia ningún parámetro operativo. La recomendación nunca afirma rentabilidad por superar una referencia débil.

## 12. Validaciones obligatorias (`python -I e2.py selftest`)
Causalidad de señales y de régimen (alterar velas futuras no cambia el pasado); fórmula de costos; validez de las parejas (mismo activo, ventana, régimen, sin señal bruta, sin duplicados, cooldown entre referencias, elegibilidad) en una muestra de sorteos; inyección de un efecto sintético conocido (+1 pp sobre los retornos de las barras de señal ⇒ D aumenta ≈ +1 pp); reproducción de E1.

## 13. Reproducción
En `research/e2-market-control`: `python -I e2.py selftest` · `python -I e2.py run` · `python -I e2.py placebo` · `python -I e2.py decide`. Requiere `numpy`. Salidas en `results/`.
