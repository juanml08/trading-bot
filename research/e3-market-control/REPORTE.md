# E3 — ¿La señal C aporta algo más que mercado, régimen y *timing*? Informe

Ejecutado el 2026-10-11 con `e3.py` según [PREREGISTRO.md](PREREGISTRO.md) (SHA-256 `59ffe8fa…8821`, registrado en `results/run_meta.json`; sin cambios después de ejecutar). Auditoría previa: [AUDITORIA_E2.md](AUDITORIA_E2.md). Datos y sorteos en `results/`. Tablas completas: `results/tables.md`.

## 0. Veredicto

**INCONCLUSO.** Con los datos locales **no es posible construir una comparación válida** para las señales C típicas: solo 540 de 3.397 señales (15,9 %) tienen controles comparables en ±14 días, frente al 80 % exigido. Además el placebo del procedimiento completo quedó por muy poco fuera de calibración. Por lo tanto **no se concluye ni que C tenga ventaja ni que carezca de ella**.

- Diferencia primaria D (C − control) en las 540 señales emparejables: **−0,49 pp** por operación, IC 95 % (14 d) **[−1,47; +0,42] pp**, p unilateral 0,847. Esa cifra **no es una estimación de C**: describe el 16 % de señales menos típicas (ver §4).
- Riesgo (veredicto aparte): drawdown de cartera de C **35,0 %** (límite 15 %) y racha máxima de 25 pérdidas → **no cumple**. C no se implementa.
- Recomendación: **dejar de ampliar esta hipótesis con más emparejamientos históricos** (cada variante adicional sobre los mismos datos aumenta el sesgo por selección). El único camino con valor confirmatorio es el seguimiento prospectivo (§9), y aun así solo detectaría efectos grandes.

## 1. Qué se verificó de E2

Todo lo calculable se **reprodujo exactamente** (D = +0,1810 pp, IC y p de 7/14/28 d, SMD, costos, mitades, cifras del informe de E2; E1: n = 3.397, +0,5630 %, DD 35,0 %, 718 omitidas). No hay fuga de información futura en las covariables ni errores de cálculo. Hallazgos de diseño (detalle en `AUDITORIA_E2.md`):

1. **F1 — controles contaminados.** ~40 % de los controles de E2 caen a ≤ 18 velas de la señal; 14 % contienen la propia vela de la señal en su ventana de retorno. Esos controles ganan +1,07 % de media (los de > 18 velas, −0,09 %). La referencia de E2 estaba inflada y su D (+0,18 pp) subestimada; la afirmación «la rentabilidad de C se explica sobre todo por contexto» no estaba bien sustentada.
2. **F2 — retorno reciente sin equilibrar** (ya declarado por E2: SMD +0,36 BTC, +0,38 mercado).
3. **F3 —** el placebo de E2 estaba calibrado, pero por construcción no podía detectar F1 ni F2.
4. **F4 —** la comparación de cartera/drawdown con la referencia no es homogénea (60 % de las señales de C son simultáneas; 7 % de los controles).

## 2. Qué cambió en E3 y por qué

| Cambio | Motivo |
|---|---|
| Zona de exclusión \|t′−t\| > 18 velas | corregir F1 (cooldown de C y solape de ventana) |
| Calipers de 0,25 DE en `btc_ret6` y `mkt_ret6` (0,63 % y 0,84 %), clave de régimen exacta de 9 celdas, mismo activo, ±14 d | corregir F2 |
| Equilibrio exigido SMD ≤ 0,10 (cuatro covariables) y estable por sorteo | E2 aceptaba 0,20 en las de 6 velas |
| Sin segundo nivel ni ±56 d; ±28 d solo como análisis aparte con sus propias puertas | no relajar criterios para aumentar muestra |
| Criterios de candidata más exigentes (7/14/28 d, 0,40 %, mitades/años, 5 bloques) y descarte con margen de equivalencia (límite superior < +0,10 pp) | evitar ambigüedad entre «sin significación» y «sin ventaja» |
| Ablación AB0–AB2 y prueba sintética de confusión | que se vea qué aporta cada corrección |

Las definiciones de C, costos, horizonte y cooldown son idénticas a E1/E2 (puerta V0 superada con diferencias = 0).

## 3. ¿Se equilibró el retorno reciente de BTC y del mercado?

**Sí, en las señales que se pudieron emparejar** (puerta V3 cumplida), pero a costa de la muestra:

| Covariable | Señales incluidas | Controles | SMD | p5–p95 por sorteo |
|---|---|---|---|---|
| btc_ret180 | +3,62 % | +3,08 % | +0,039 | +0,021 … +0,058 |
| btc_vol42 | 0,72 % | 0,75 % | −0,074 | −0,096 … −0,051 |
| **btc_ret6** | +0,28 % | +0,26 % | **+0,020** | −0,002 … +0,043 |
| **mkt_ret6** | +0,54 % | +0,51 % | **+0,024** | +0,003 … +0,044 |
| mkt_ret6 sin el propio activo (diag.) | +0,43 % | +0,50 % | −0,056 | |
| asset_ret6 (diag.; difiere por construcción) | +2,67 % | +0,54 % | +0,771 | |

Es un equilibrio **construido por selección**: solo quedan señales cuyo mercado reciente fue tranquilo (btc_ret6 medio +0,28 %). Las 2.857 excluidas, las típicas de C, vienen tras un repunte del mercado (btc_ret6 +1,39 %, mkt_ret6 +1,79 %).

## 4. Señales incluidas y excluidas; tamaño efectivo

- **Incluidas 540 (15,9 %); excluidas 2.857 (84,1 %), todas por tener < 5 controles admisibles.** La mediana de controles admisibles por señal en el conjunto completo es **1**; entre las incluidas, 8 (puerta V2 cumplida).
- Puerta V1 (≥ 80 % de las señales y ≥ 60 bloques de 14 d): **falla** (15,9 %; 81 bloques).
- Distancia temporal media a los controles: 8,1 días (mediana 7,7; p10–p90 4,0–12,7 d). Controles a ≤ 18 velas: 0 % (por construcción).
- Reutilización: ninguna barra se repite dentro de un sorteo; cuota de la barra más frecuente por señal: mediana 13,7 %, p90 21,8 %; 99,8 % de las incluidas ≤ 35 % (V4 cumplida). 6.007 barras de control distintas para 1.077.716 elecciones (≈ 179 elecciones por barra: **la reutilización entre sorteos es alta**, inevitable con conjuntos de ~8 candidatos; por eso el tamaño efectivo es el de las señales y no el de las elecciones).
- Tamaño efectivo (varianza del bootstrap de 14 d): n_eff ≈ **190** frente a 540 señales (81 bloques de 14 d no vacíos).
- Las incluidas son distintas de las excluidas: C rinde −0,34 % en las incluidas y +0,73 % en las excluidas; 60 % de las incluidas están en volatilidad baja (34 % en las excluidas). *Descriptivo; añadido tras ver la primera ejecución, no interviene en ningún criterio.*
- Ventana ±28 d (análisis separado): 923 incluidas (27 %) → **no válida** (V1).

## 5. Resultado de C frente al control y su incertidumbre

Solo sobre las 540 señales emparejables (**sin validez para C en conjunto**):

| | C | Control |
|---|---|---|
| Expectancy neta (0,30 %) | **−0,34 %** | +0,14 % |
| Diferencia D | **−0,49 pp** | |

IC 95 %: 7 d [−1,53; +0,50]; **14 d [−1,47; +0,42]**; 28 d [−1,54; +0,40] pp; p unilateral (14 d) 0,847. El IC cruza 0 con los tres largos de bloque; el rango de D por sorteo (simulación) es [−0,84; −0,15] pp, mucho menor que la incertidumbre estadística. El IC del bootstrap condiciona a los controles y no corrige por haber elegido C mirando estos mismos datos: **no es inferencia confirmatoria**.

## 6. Sensibilidad, estabilidad y riesgo

**Ablación escalonada** (descriptiva; todas rotuladas «no válida» por no cumplir alguna puerta):

| Variante | Incluidas | C | Control | D (pp) | IC 95 % 14 d (pp) | Equilibrio ret6 (SMD btc / mkt) | Controles ≤ 18 velas |
|---|---|---|---|---|---|---|---|
| AB0: régimen, ±14 d (≈ E2-S1) | 3.361 | +0,57 % | +0,38 % | +0,20 | [−0,17; +0,57] | **+0,37 / +0,40** | 40 % |
| AB1: AB0 + zona de exclusión | 3.150 | +0,55 % | −0,01 % | **+0,55** | [+0,08; +1,06] | **+0,43 / +0,44** | 0 % |
| AB2: AB0 + calipers (sin zona) | 1.036 | −0,11 % | +0,35 % | −0,47 | [−1,01; +0,03] | +0,03 / +0,03 | 45 % |
| **Primaria** | 540 | −0,34 % | +0,14 % | −0,49 | [−1,47; +0,42] | +0,02 / +0,02 | 0 % |

Lectura: quitar los controles contaminados (AB0→AB1) **sube** D de +0,20 a +0,55 pp, lo que confirma F1 (E2 subestimaba D). Pero exigir equilibrio del retorno reciente (→ AB2/primaria) lo **invierte** a −0,5 pp, sobre una submuestra no representativa. El signo de D depende de qué señales se comparan y de si el *timing* se controla: **los datos no separan la señal C del repunte de mercado que la acompaña.**

**Coste 0,40 %:** C −0,44 %, control +0,04 %, D −0,49 pp (IC 14 d [−1,47; +0,42]).

**Estabilidad (primaria):** D < 0 en ambas mitades (−0,10 / −1,06 pp), en los tres regímenes (bajista −0,59, lateral −0,37, alcista −0,80 pp) y en 3 de 5 años (2022 −0,10; 2023 +0,05; 2024 −0,40; 2025 −4,38; 2026 +1,17 pp). 16 de 20 activos y 11 de 17 trimestres con D negativa; Σd < 0, de modo que las cuotas de concentración no están definidas. Holm (familia F2: mitades, regímenes, años; ±28 d excluida por no válida): **ningún p ajustado < 1,0**. Nada de esto sustituye a la comparación primaria fallida.

**Placebo (200 desplazamientos, procedimiento completo):** rechazo 3,5 % (≤ 9 % ✔), cobertura del IC 97,0 % (≥ 92 % ✔), **media de D −0,107 pp (límite ±0,10 ✘)**; calibración **no** cumplida por 0,007 pp. Con sd 0,40 pp entre placebos, la media tiene error típico ≈ 0,03 pp: el sesgo negativo es real aunque pequeño (hipótesis no investigada: la regla de inclusión/exclusión y la zona de exclusión no son neutras ante el *clustering* de las señales desplazadas). Va en la dirección conservadora, pero la regla prerregistrada obliga a no emitir CANDIDATA ni DESCARTAR.

**Riesgo (independiente de la ventaja):**
- Cartera de C (3.397 señales, 10 % por señal, capital compartido): retorno +131,7 %, **drawdown 35,0 %**, exposición 31 %, 718 omitidas, **racha máxima 25 pérdidas**. **Incumple el límite de 15 %.**
- Solo las 540 incluidas: retorno −20,5 %, drawdown 35,4 %.
- Referencia sobre esas 540 (100 sorteos): drawdown mediano 14,8 % (rango 7,9–24,8 %), racha mediana 14, exposición 6 %. **No es comparable** (los controles no son simultáneos como las señales, F4); no se usa como evidencia de que C controle o empeore el riesgo.

## 7. Qué conclusiones están justificadas y cuáles no

**Justificadas (hechos):**
- E2 se reproduce exactamente; su referencia estaba contaminada (F1) y su D estaba subestimado; el emparejamiento de E2 no equilibraba el retorno reciente.
- Con calipers prerregistrados, solo el 16 % de las señales C tiene controles comparables en ±14 d; el 84 % restante ocurre en situaciones de mercado para las que no existe un control comparable en el mismo activo, régimen y semana.
- En las señales sí emparejables C no supera a su control (D = −0,49 pp, IC cruza 0).
- Con costos de 0,30–0,40 % y sin ningún control, C tiene expectancy positiva en toda la muestra (E1), pero eso no establece ventaja propia.
- El drawdown de C (35 %) y su racha de 25 pérdidas no cumplen la regla madre del negocio.

**No justificadas:**
- Que C tenga una ventaja propia (la única variante con D positivo y IC > 0, AB1, no está equilibrada: SMD 0,43/0,44).
- Que C carezca de ventaja (el D negativo viene de una submuestra seleccionada y el IC es amplio).
- Cualquier interpretación causal o de rentabilidad futura; cualquier p-valor como confirmatorio.

## 8. Limitaciones que impiden confirmar una ventaja
1. No hay holdout: C se descubrió en estos datos y todo el calendario fue examinado en E1, E2 y E3.
2. **Falta de solapamiento (*positivity*):** las señales C coinciden con repuntes del mercado; no hay controles comparables para la mayoría. Aflojar los calipers reintroduce el desequilibrio; no se hizo, por regla.
3. Una señal de «vela de expansión» es en sí misma un evento de mercado; separarla del *timing* de corto plazo puede ser imposible con 4,7 años y 20 pares.
4. Universo de 20 pares vigentes hoy (sesgo de supervivencia); mercado equiponderado.
5. Pocos trimestres decisivos; dependencia entre bloques contiguos no modelada; IC condicionado a los controles.
6. Costos y deslizamiento fijos; en velas de expansión el deslizamiento real probablemente sea mayor.
7. Placebo fuera de tolerancia por 0,007 pp, con sesgo negativo pequeño no explicado.
8. Reutilización entre sorteos alta (≈ 179 elecciones por barra distinta).
9. **Desviación:** tras ver la primera ejecución se añadió el bloque descriptivo «incluidas vs excluidas» a `primary.json` y se volvió a ejecutar (misma semilla, resultados idénticos). No interviene en ninguna puerta ni criterio.

## 9. Plan de seguimiento prospectivo (solo diseño; **no implementado**)

Requiere autorización explícita aparte. No toca el bot, el scheduler, la base de datos de producción ni la configuración; no realiza operaciones.

**Alcance y separación.** La última vela histórica examinada abre el 2026-09-30 20:00 UTC (cierra el 2026-10-01 00:00). Las observaciones prospectivas son señales de velas que **abren a partir de 2026-10-01 00:00 UTC**. Todo lo anterior queda marcado `discovery` y **no entra nunca en la prueba prospectiva**; ni se re-estiman parámetros con ellas ni se mezclan en el IC primario.

**Qué registrar (almacén independiente, solo añadir, p. ej. archivos JSONL/Parquet fuera de la BD de trading, con hash encadenado):**
1. *Panel de velas:* para cada vela de 4 h cerrada y cada uno de los 20 pares, OHLCV, hora de apertura/cierre, hora de captura y fuente. Sin reescribir si el proveedor corrige datos; una corrección se guarda como versión nueva.
2. *Panel de covariables por vela* (para poder construir controles después): TR, SMA24 de TR, razón TR/SMA, condición alcista, estado de cooldown, tendencia BTC (ret180), volatilidad (vol42 y percentil de 720), clave de régimen, `btc_ret6`, `mkt_ret6`, `asset_ret6`.
3. *Registro de señal C:* activo, vela de señal, valores de las condiciones, si se suprimió por cooldown, versión/commit de la definición congelada y marca de tiempo de registro **anterior a la apertura de la vela de entrada**.
4. *Resultado a 48 h:* precio de entrada (apertura observada de t+1), precio de salida (cierre de t+12), retornos netos con costos 0,30 % y 0,40 %, registrados solo cuando la vela de salida cierra; sin stops, sin ajustes.

**Cómo evitar fugas.** (a) La señal se calcula solo con velas ya cerradas; (b) el registro de señal se sella (hash + hora) antes de que exista la vela de entrada; (c) los controles **no** se eligen durante la recolección: se construyen después, desde el panel prospectivo, con el procedimiento de un preregistro nuevo, congelado **antes** del primer análisis (mismas reglas: activo, ±14 d, régimen, calipers, zona de exclusión > 18 velas, puertas de validez); (d) controles solo con barras prospectivas (futuras respecto de 2026-10-01); (e) ningún cambio de definición de C ni de parámetros durante el seguimiento; cualquier cambio abre una cohorte nueva.

**Análisis y decisión.** Una única prueba primaria al final de cada hito (12 y 24 meses; no se mira antes), con el mismo bootstrap de calendario y la misma regla de INCONCLUSO ante muestra o equilibrio insuficientes. Orden de magnitud: con ~700 señales al año, el error típico de D es ≈ 0,4 pp/año^½; detectar +0,5 pp con potencia del 80 % exige ~5 años y +0,2 pp, décadas. Conviene aceptar de antemano que el seguimiento solo puede confirmar un efecto grande o dejar la hipótesis en INCONCLUSO, y fijar una fecha de cierre (p. ej. 24 meses) tras la cual, si no hay ventaja equilibrada y válida, **se archiva C**. La evaluación de riesgo (DD ≤ 15 %) es independiente.

## 10. Archivos, comandos y reproducibilidad

Creados (todos en `research/e3-market-control/`): `AUDITORIA_E2.md`, `PREREGISTRO.md`, `e3.py`, `REPORTE.md`, y `results/`: `audit_e2.json`, `config.json` (parámetros, calipers, σ), `run_meta.json` (semilla, versiones, SHA-256 de datos y código), `repro_e1.json`, `primary.json`, `secondary.json`, `placebo.json` (200 filas), `decision.json`, `tables.md`, `draws_primary.npz` (todos los sorteos), `draws_summary.csv`, `repro_check.json`, `SHA256SUMS.txt`.

Comandos (desde `research/e3-market-control`, Python 3.14.7, numpy 2.5.3): `python -B -I e3.py audit` · `selftest` · `run` (≈ 100 s) · `placebo` (≈ 40 s, 6 procesos) · `decide` · `repro`.

Pruebas: `selftest` OK (costos, causalidad de señales/régimen/covariables, definición explícita de `btc_ret6`/`mkt_ret6`, reproducción de E1 y de las señales de E2, validez de las parejas de la primaria y de ±28 d con 0 violaciones, efecto sintético +1 pp recuperado exactamente, prueba de confusión sintética: la referencia solo-régimen se sesga +0,72 pp y la primaria +0,02 pp). `repro` recalcula la primaria y reproduce idénticos los sorteos, D, IC y puertas; el placebo se ejecutó dos veces con resultados idénticos (independiente del reparto entre procesos: semilla por desplazamiento).

## 11. Confirmaciones
- No se modificó ningún archivo existente (el `git status` solo añade `research/e3-market-control/`; los cambios locales previos de `api/` y los directorios sin seguimiento previos siguen intactos).
- No se tocó código de producción, estrategias, configuración, pruebas existentes ni el estado del bot; no se escribió en la base de datos; no se hicieron operaciones de trading; no se usó la red ni se descargaron datos.
- No se hicieron commits.
- Archivos temporales de la sesión (salida de una prueba de E2, copia de un placebo y scripts de sondeo) viven fuera del repositorio.
