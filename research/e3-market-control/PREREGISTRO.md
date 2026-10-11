# Preregistro E3 — ¿La señal C aporta algo más que mercado, régimen y *timing* de corto plazo?

Cerrado el 2026-10-11, **antes de escribir o ejecutar `e3.py`**. `e3.py` registra el SHA-256 de este archivo en `results/run_meta.json`. Cualquier cambio posterior es una **desviación** que se documenta en el reporte; un cambio de hipótesis, de referencia primaria o de criterio exige detenerse y pedir autorización.

Estudio offline y de solo lectura: sin red, sin BD, sin código de producción, sin órdenes. Usa los datos locales `research/signal-audit/data4h` y funciones de `audit_4h.py` y `e1.py` importadas sin modificar. Sigue `reglas.md` (en particular: no asumir que una estrategia es rentable).

## 0. Transparencia sobre lo que se vio antes de registrar

- Todo el calendario (barras elegibles 2022-05-14 → 2026-09-27) ya fue examinado en E1/E2 y C se descubrió en estos datos. **No hay holdout.** E3 solo puede debilitar o reforzar una pista; nunca confirmar rentabilidad.
- Antes de cerrar este preregistro se leyó el código y los resultados de E2 y se calcularon diagnósticos **de E2** (no de E3) que figuran en `AUDITORIA_E2.md`: (a) el emparejamiento de E2 no equilibró `btc_ret6` ni `mkt_ret6`; (b) alrededor del 40 % de las referencias de E2 caen a ≤ 18 velas de la señal, y esas referencias tienen una expectancy mucho mayor porque su ventana de 12 velas solapa o incluye la propia vela de la señal. La zona de exclusión de la sección 4 se deriva de la lógica del diseño (cooldown de 18 velas de C, ventana de operación de 12 velas), **no** se ajustó con resultados de E3, que no existen todavía. Para que el lector pueda juzgar su efecto, la sección 8 prerregistra una ablación escalonada.
- Los umbrales de los calipers y de las puertas se fijan aquí por convención (0,25 DE, SMD ≤ 0,10), sin haber ejecutado ningún emparejamiento de E3.

## 1. Hipótesis y estatus

- **H_E3 (única primaria):** la diferencia de expectancy neta por operación, **D = C − referencia**, es > 0, donde la referencia es una entrada aleatoria del mismo activo, cercana en fecha, del mismo régimen de BTC, con retornos recientes de BTC y del mercado comparables y sin solapamiento con la señal. Nula: D ≤ 0. Una sola hipótesis primaria → sin corrección de multiplicidad para ella. Todo lo demás es secundario/descriptivo y **no puede cambiar el veredicto ni sustituir una comparación primaria fallida**.
- Un resultado «sin significación» **no** se interpreta como ausencia de ventaja.

## 2. Definición de C (congelada; idéntica a E1/E2)

20 pares (`data4h`, 10.404 velas de 4 h, 2022-01-01 → 2026-09-30 20:00 UTC). Señal en la vela t: vela alcista (`close > open`) con `TR[t] > 2·SMA(TR[t−24..t−1])`, `TR = max(h−l, |h−c₋₁|, |l−c₋₁|)`; `audit_4h.signals("C", 24, …)` + `audit_4h.cooldown` (18 velas por activo, 72 h) + elegibilidad t ∈ [800, T−19]. Entrada en la apertura de t+1; salida al cierre de t+12 (48 h, sin stop; `e1.ratios(…, 12, "R1")`). Costos: comisión 0,10 % y deslizamiento 0,05 % por lado, `neto = (1−c)²(1−s)/(1+s)·P_sal/P_ent − 1` (ida y vuelta 0,2998 %). No se prueban otros N, horizontes ni stops.

**Puerta V0 (reproducción):** antes de cualquier análisis `e3.py` recalcula C y exige igualdad exacta con `../e1-multiday/results/variants.json["C-48h-R1"]` (n = 3.397, expectancy, win rate, PF, racha 25, cartera ret/DD/exposición/omitidas) y con las señales de E2. Si difiere, el script se detiene y no hay resultados.

Decisión de ambigüedad: «true range de las 24 velas anteriores» = SMA de 24 del TR en t−24..t−1, como en el código existente (la vela t no entra en su propia media).

## 3. Variables de emparejamiento (todas conocidas al cierre de la vela t de la señal; la entrada es en t+1)

| Variable | Definición exacta | Uso |
|---|---|---|
| activo | mismo activo a | coincidencia exacta |
| fecha | |t′ − t| ≤ 84 velas (±14 d) (primaria) | ventana |
| tendencia BTC | retorno de BTC de 180 velas (30 d): > +10 % alcista, < −10 % bajista, resto lateral | coincidencia exacta (clave de 9 celdas) |
| volatilidad BTC | desviación típica (ddof 0) de los 42 retornos simples de BTC terminados en t; percentil dentro de las 720 velas terminadas en t (inclusive); tercil < 1/3, < 2/3, resto | coincidencia exacta (clave de 9 celdas) |
| `btc_ret6` | c_BTC[t]/c_BTC[t−6] − 1 | caliper |
| `mkt_ret6` | **media equiponderada, sobre los 20 activos del universo (incluido el propio activo de la señal), de c_a[t]/c_a[t−6] − 1** | caliper |

Los criterios de régimen son los de E2 (verificados causales en la auditoría). El retorno 6 velas **incluye** la propia vela de la señal (cierra en t); es información disponible antes de la entrada en t+1. `mkt_ret6` incluye al activo de la señal; se informa como diagnóstico el equilibrio de la variante que lo excluye (19 otros activos) y `asset_ret6`/`asset_ret180` (el retorno propio del activo difiere por construcción, porque la señal *es* una vela de expansión; no se empareja).

**Calipers (prerregistrados):** `|btc_ret6[t′] − btc_ret6[t]| ≤ 0,25·σ_b` y `|mkt_ret6[t′] − mkt_ret6[t]| ≤ 0,25·σ_m`, donde σ_b y σ_m son las desviaciones típicas (ddof 0) de `btc_ret6` y `mkt_ret6` sobre todas las barras elegibles [800, T−19] (solo una escala; se guardan en `results/config.json`). Ambos calipers deben cumplirse a la vez. No se modifican.

## 4. Referencia primaria — reglas de elegibilidad de cada control

Para la señal (a, t), un control t′ es admisible si y solo si:
1. mismo activo a; t′ ∈ [800, T−19];
2. **zona de exclusión: |t′ − t| > 18 velas.** Motivo: el cooldown de C impide por construcción otra señal del mismo activo en ≤ 18 velas, y un control a ≤ 12 velas comparte la ventana de la operación (si t′ < t, su ventana de retorno contiene la propia vela de la señal). Es una corrección metodológica respecto de E2;
3. misma clave de régimen de 9 celdas en t′ que en t;
4. sin señal C *en bruto* (antes del cooldown) en (a, t′);
5. |t′ − t| ≤ 84 (**ventana primaria ±14 d, sin segundo nivel ni fallback**);
6. los dos calipers de la sección 3;
7. misma salida (t′+1 → t′+12), mismo horizonte y costos que C.

Una señal con **menos de 5** controles admisibles se **excluye (en ambos brazos)** y se informa; **no se relajan** calipers ni ventana para recuperarla.

### 4.1 Sorteo, reutilización y límites
- 2.000 sorteos, `numpy.default_rng(20261011+301)`. En cada sorteo las señales se procesan en orden aleatorio (sin prioridad por fecha) y se elige **un** control por señal, uniforme entre los admisibles no bloqueados.
- **Límite dentro de un sorteo:** un control elegido bloquea, para ese activo y sorteo, las velas t′−17 … t′+17 (cooldown ≥ 18 entre controles del mismo activo, y ninguna barra se usa dos veces). Controles de **distintos** activos sí pueden coincidir en la misma fecha (es el co-movimiento real del mercado; la dependencia resultante se absorbe en el bootstrap).
- **Límite entre sorteos:** una misma barra puede ser el control de la misma señal en varios sorteos; se limita con la puerta V4 (sección 6): para ≥ 90 % de las señales incluidas, la barra más frecuente debe aparecer en ≤ 35 % de sus sorteos. No hay reutilización ilimitada porque el conjunto admisible mínimo es 5 y cada sorteo consume como máximo una barra por señal.
- Si en un sorteo todos los admisibles de una señal están bloqueados, esa señal queda sin pareja en ese sorteo. Una señal se **incluye** si se emparejó en ≥ 50 % de los sorteos.
- **Estimador:** m_i = media neta de la referencia de la señal i sobre sus sorteos emparejados; d_i = neto_i − m_i; **D = media de d_i** sobre las incluidas. Se registran todos los sorteos (`results/draws_primary.npz`, `draws_summary.csv`).
- Señales simultáneas: operaciones separadas, cada una con su control; la dependencia entre ellas se trata en el bootstrap por bloques de calendario (sección 7).

### 4.2 Ventana secundaria ±28 d (84 → 168 velas)
Mismas reglas, calipers, zona de exclusión, clave de régimen y puertas V1–V4, con la ventana de 168 velas **desde el principio** (no como rescate de señales de la primaria). Semilla SEED+302. **Solo se interpreta si pasa sus propias puertas V1–V4**; si no, se declara «no válida». No se usa ±56 d en ningún análisis de E3.

## 5. Métricas
Para C y la referencia: operaciones, win rate, ganancia/pérdida media, expectancy neta, suma, profit factor, peor operación, percentil 5, racha máxima de pérdidas (orden de salida, desempate por activo); D con IC 95 % (percentil) y p unilateral; D por mitad cronológica (punto medio del calendario elegible), año, trimestre, tendencia de BTC, tercil de volatilidad, celda de régimen y activo; concentración (mejor activo y mejor trimestre: cuota de Σd y D sin ellos; D sin los 5 y sin los 10 bloques de 14 d que más aportan a Σd; D sin los 10 con más señales); sensibilidad a costos totales de ida y vuelta de **0,30 % y 0,40 %** (escala c y s en proporción 2:1 como en E1; se recalculan D, IC y neto de C); tamaño efectivo: n incluidas, bloques no vacíos de 7/14/28 d y n_eff = Var(d)/Var_bootstrap(D) (14 d); distancia temporal de los controles (media, mediana, deciles) y reutilización (cuota máxima por señal, barras distintas por señal, uso máximo y p99 de una barra por sorteo, barras de referencia distintas / elecciones). **Riesgo, independiente de la ventaja:** cartera de C (10 % del patrimonio por señal, capital compartido, `e1.portfolio`) con retorno, drawdown máximo, exposición y omitidas — con las 3.397 señales (igual que E1) y con las incluidas — y, para la referencia, mediana y rango de 100 sorteos del retorno, drawdown y racha de pérdidas.

## 6. Puertas de validez de la comparación primaria (si falla alguna → INCONCLUSO)
- **V1 muestra:** señales incluidas ≥ 80 % de las 3.397 **y** ≥ 60 bloques de 14 d no vacíos.
- **V2 candidatos:** mediana del tamaño del conjunto admisible de las incluidas ≥ 8.
- **V3 equilibrio** (SMD = (media_señal − media_ref)/√((var_s+var_r)/2), por sorteo, sobre las incluidas): |SMD medio| ≤ **0,10** en `btc_ret180`, `btc_vol42`, `btc_ret6` y `mkt_ret6`, **y** el intervalo p5–p95 de los SMD por sorteo dentro de ±0,15 en las cuatro.
- **V4 reutilización:** ≥ 90 % de las incluidas con cuota de la barra más frecuente ≤ 35 %.
- **V5 placebo calibrado** (sección 9) — necesaria para poder emitir CANDIDATA o DESCARTAR.

Las puertas se evalúan sobre la variante primaria; el resto de variantes tienen su propio informe de equilibrio y se rotulan «válida/no válida».

## 7. Incertidumbre
- **Bootstrap por bloques de calendario** de **14 d (primario), 7 d y 28 d (sensibilidad)**, definidos por la fecha de la vela de la señal y **agrupando todos los activos**: en cada remuestreo se elige un mismo conjunto de bloques de calendario para todos los activos, de modo que el co-movimiento no se trata como independencia. **10.000 remuestreos**, semilla 20261011+días (los mismos índices que E1/E2 para cada longitud). Estadístico: razón de sumas (media por operación).
- IC 95 % percentil de C, de la referencia y de D; **p unilateral de H_E3** = (1 + #{D* ≤ 0})/(10.000 + 1) con bloques de 14 d.
- Limitaciones declaradas: (i) los bloques no capturan dependencia entre bloques contiguos (la salida de 48 h cruza como máximo un borde de bloque); (ii) el IC condiciona a los m_i (ruido de los 2.000 sorteos, informado aparte); (iii) con una selección de la hipótesis en estos mismos datos, el IC **no** puede leerse como inferencia confirmatoria aunque el placebo esté calibrado; (iv) pocos trimestres decisivos pueden volver el IC optimista.

### 7.1 Multiplicidad
Familia secundaria **F2** (p unilaterales, bootstrap de 14 d sobre cada subconjunto, **Holm** con α = 0,05): ventana ±28 d (si es válida), primera mitad, segunda mitad, tres regímenes de tendencia y cada año con ≥ 30 señales incluidas. Activo, trimestre, tercil de volatilidad, celdas y ablaciones son **descriptivos, sin p-valor de decisión**. Ningún resultado de F2 puede modificar el veredicto.

## 8. Ablación escalonada (descriptiva, para entender qué cambió respecto de E2)
Mismas 2.000 sorteos/10.000 bootstrap, semillas SEED+401…403, sin efecto sobre el veredicto:
- **AB0:** clave de 9 celdas, ±14 d, sin calipers, sin zona de exclusión (≈ S1 de E2).
- **AB1:** AB0 + zona de exclusión.
- **AB2:** AB0 + calipers (sin zona).
- **Primaria:** AB0 + zona + calipers.
Se informa D, IC, equilibrio y fracción de controles a ≤ 18 velas de cada una.

## 9. Placebo del procedimiento completo
`python -I e3.py placebo`: **200** desplazamientos circulares (≥ 60 d, semilla 20261011+7) de **todas** las señales de todos los activos conjuntamente dentro del calendario elegible. Para **cada** desplazamiento se repite el procedimiento completo: señales y máscara en bruto desplazadas, cooldown, elegibilidad, cálculo de la clave de régimen y de los calipers en la nueva fecha, conjuntos admisibles (zona, ventana, régimen, calipers), 100 sorteos, regla de inclusión, D y bootstrap de 14 d (2.000 remuestreos), IC y p. **Calibrado solo si:** tasa de rechazo (p < 0,05 con D > 0) ≤ 9 %; cobertura del IC 95 % de 0 ≥ 92 %; |media de D placebo| ≤ 0,10 pp. Se interpreta con sus límites: 200 desplazamientos del mismo calendario no son independientes, el error de Monte Carlo de una tasa del 5 % es ≈ 1,5 pp, y un desplazamiento rompe la asociación señal–*timing* de mercado, de modo que **no demuestra que el emparejamiento elimine el sesgo por *timing***; ese punto lo cubre la prueba sintética de confusión del `selftest`.

## 10. Criterios de decisión (C-48h-R1, costo base 0,30 %, referencia primaria; en este orden)
0. **V0** falla → el script se detiene (sin resultados).
1. **INCONCLUSO** si falla V1, V2, V3 o V4 (comparación no válida: equilibrio inadecuado, muestra insuficiente o reutilización excesiva) o V5 (placebo no calibrado) — este último impide además CANDIDATA y DESCARTAR.
2. **DESCARTAR LA HIPÓTESIS DE VENTAJA EN ESTE EXPERIMENTO** solo si V1–V5 se cumplen **y** el límite superior del IC 95 % de D es **< +0,10 pp** (un tercio del costo de ida y vuelta; la ventaja mínima que justificaría seguir) con **los tres** largos de bloque (7, 14 y 28 d). No se descarta C por un p > 0,05.
3. **CANDIDATA NO CONFIRMADA** solo si V1–V5 se cumplen y se cumplen **todas**:
   a. D > 0 y límite inferior del IC 95 % (14 d) > 0;
   b. límite inferior del IC 95 % > 0 también con bloques de 7 d y de 28 d;
   c. a costo 0,40 %: D > 0, límite inferior del IC 95 % (14 d) de D > 0 y neto de C > 0;
   d. D > 0 en ambas mitades cronológicas y en ≥ 60 % de los años con ≥ 30 señales incluidas;
   e. ningún activo y ningún trimestre aporta > 30 % de Σd, y D > 0 sin el mejor activo y sin el mejor trimestre;
   f. D > 0 sin los 5 bloques de 14 d que más aportan a Σd.
4. **INCONCLUSO** en cualquier otro caso (incluye: D > 0 con IC que cruza 0; resultados incompatibles entre longitudes de bloque, p. ej. límite inferior > 0 con 14 d y ≤ 0 con 7 d o 28 d).

**Riesgo (veredicto aparte):** la cartera de C debe cumplir DD ≤ 15 % (criterio de E1) y además se informa la racha máxima de pérdidas. Cualquier resultado de ventaja es **independiente** de ello: C no se implementa en el bot ni se cambia ningún parámetro mientras no supere también la evaluación de riesgo, y «CANDIDATA NO CONFIRMADA» no autoriza su incorporación ni demuestra rentabilidad futura.

## 11. Validaciones obligatorias (`python -I e3.py selftest`)
Costos (fórmula explícita y escala 0,30/0,40 %); causalidad de señales, régimen y covariables (alterar velas futuras no cambia el pasado); reproducción de E1 y de las señales de E2; validez de cada pareja en una muestra de sorteos (activo, ventana, régimen, sin señal bruta, zona de exclusión, ambos calipers, elegibilidad, cooldown, sin duplicados); efecto sintético +1 pp recuperado exactamente; **prueba sintética de confusión** (un efecto que depende solo de `mkt_ret6` y no de la señal desplaza D de la referencia solo-régimen mucho más que D de la referencia primaria); sorteos distintos.

## 12. Reproducción
En `research/e3-market-control`: `python -I e3.py audit | selftest | run | placebo | decide | repro`. Requiere `numpy`. Salidas en `results/` (config, semilla, hashes de datos y código, tablas, sorteos).

## 13. Plan prospectivo (solo diseño)
Se entrega al final de `REPORTE.md`. **No se implementa**: ni bot, ni scheduler, ni BD.
