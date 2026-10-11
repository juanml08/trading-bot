# E2 — Señal C (48 h) frente a referencias que controlan el mercado: informe

Ejecutado el 2026-10-11 con `e2.py` según [PREREGISTRO.md](PREREGISTRO.md) (SHA-256 `ce778e52…1e18`, registrado en `results/run_meta.json`; sin cambios tras ejecutar). Datos: `results/` (`primary.json`, `secondary.json`, `placebo.json`, `decision.json`, `repro_e1.json`, todos los sorteos en `draws_primary.npz` y `draws_summary.csv`). Reproducción: `python -I e2.py selftest | run | placebo | decide`.

## 1. Veredicto

**INCONCLUSO (puerta de emparejamiento fallida).** C no supera una comparación justa de forma demostrable, y tampoco queda descartada por esta prueba.

- Diferencia primaria D (C − referencia B): **+0,18 pp** por operación. IC 95 % (bloques 14 d) **[−0,19; +0,56] pp**, p unilateral 0,169. El intervalo cruza 0 con bloques de 7, 14 y 28 días.
- **Hecho verificado:** frente a una referencia que controla régimen y fecha, la ventaja de E1 (+0,65 pp) se reduce a +0,18 pp y deja de ser estadísticamente distinguible de cero.
- **Limitación decisiva:** el emparejamiento por régimen no dejó equilibrado el retorno reciente (6 velas) de BTC ni del mercado. Por regla preregistrada no se emite conclusión de ventaja ni de descarte.
- C no se implementa. El drawdown de cartera de E1 (35 %) sigue sin cumplir el límite de 15 %.

## 2. Puertas y validaciones

| Puerta | Resultado |
|---|---|
| Reproducción de E1 (n, expectancy, PF, racha, cartera, exceso frente a la referencia de E1) | **OK, diferencias = 0** (n = 3.397; +0,5630 %; PF 1,276; DD 35,0 %; exceso +0,648 pp) |
| `selftest` (costos, causalidad de señales y de régimen, validez de parejas, efecto sintético +1 pp recuperado exacto) | OK |
| Placebo del procedimiento completo (200 desplazamientos comunes; señales, regímenes, sorteos de referencia, inclusión y bootstrap repetidos en cada uno) | **Calibrado:** rechazo 5,0 %, cobertura del IC 95,0 %, media de D −0,003 pp (sd 0,18 pp) |
| Emparejamiento adecuado (M1–M4) | **NO** (falla M3) |

## 3. Calidad del emparejamiento (referencia primaria B)

- Parejas por nivel: 99,0 % de las elecciones en ±14 d y 1,0 % en ±28 d (nunca 56 d). 3.363 señales empezaron en nivel 1, 23 en nivel 2 y **11 quedaron excluidas** (sin conjunto candidato ≥ 5): 3.386 de 3.397 incluidas (M1 OK).
- Conjunto candidato: mediana 65 barras, p10 22,5 (M2 OK). Distancia temporal media 5,4 d.
- Reutilización: ninguna barra se repite dentro de un sorteo; entre sorteos la barra más frecuente de una señal aparece en una mediana del 2,5 % de sus sorteos (p90 7 %); 136.157 barras de referencia distintas (M4 OK).
- Equilibrio (SMD, señales frente a sus referencias):

| Covariable | SMD | Criterio | |
|---|---|---|---|
| btc_ret180 (en la clave) | +0,02 | ≤ 0,10 | OK |
| btc_vol42 (en la clave) | −0,02 | ≤ 0,10 | OK |
| **btc_ret6** (no en la clave) | **+0,36** | ≤ 0,20 | **falla** |
| **mkt_ret6** (no en la clave) | **+0,38** | ≤ 0,20 | **falla** |
| asset_ret6 (diagnóstica; difiere por construcción) | +0,63 | — | — |

**Interpretación (hipótesis, no verificada):** las señales C ocurren tras un repunte corto de BTC y del mercado (+1,2 % en 6 velas frente a +0,2 %). Es el *timing* de corto plazo que el régimen de 30 días y la volatilidad de 7 días no capturan. Por eso la referencia B no es una comparación plenamente justa. Aun así, con esta referencia imperfecta, D ya no es significativo.

## 4. Resultados primarios (C-48h-R1, costo 0,30 %, n = 3.386 incluidas)

| | C | Referencia B |
|---|---|---|
| Expectancy neta | +0,566 % [+0,04; +1,11] | +0,385 % |
| Win rate | 49,6 % (todas) | 49,3 % (mediana de 100 sorteos) |
| Ganancia / pérdida media | +5,24 % / −4,04 % | +4,63 % / −3,76 % |
| Profit factor | 1,28 | 1,20 |
| P/L neto acumulado (suma) | +19,1 | +13,0 |
| Peor racha de pérdidas | 25 | 19 |
| Cartera (10 %/señal): retorno / drawdown | +131,7 % / 35,0 % | +159,5 % / 35,0 % (medianas) |

La referencia pareada **también** gana: +0,385 % de expectancy y una cartera con retorno y drawdown comparables a los de C. Es decir, la mayor parte de la rentabilidad de E1 se explica por *dónde y cuándo* hay entradas, no por la señal. El drawdown de la cartera de C no es mejor que el de entrar al azar en el mismo régimen (en E1 parecía mejor porque la referencia no controlaba el momento del mercado).

**D por longitud de bloque:** 7 d [−0,25; +0,61] pp; 14 d [−0,19; +0,56] pp (p 0,169); 28 d [−0,19; +0,57] pp. Ruido de simulación de los sorteos: D por sorteo entre +0,02 y +0,36 pp.

**Sensibilidad a costos (totales 0,20/0,30/0,40/0,50 %):** C +0,67/+0,57/+0,46/+0,36 %; D se mantiene en +0,18 pp en todos (los costos afectan por igual a ambos brazos). C sigue positiva en 0,50 %, pero eso no es ventaja frente al mercado.

## 5. Dependencia de submuestras (D, referencia B)

- **Mitades cronológicas:** primera −0,08 pp (n 1.756), segunda **+0,46 pp** (n 1.630; IC [−0,10; +1,05]). La ventaja viene de la segunda mitad.
- **Régimen de tendencia de BTC:** bajista +0,22 pp, lateral +0,44 pp (IC [−0,07; +0,94]), **alcista −0,35 pp**. Ninguno es significativo; con tendencia alcista C rinde menos que su referencia.
- **Año:** 2022 −0,10, 2023 −0,06, 2024 **+0,47**, 2025 +0,07, 2026 +0,48 pp.
- **Concentración:** 8 de 18 trimestres con D negativa. **Un solo trimestre aporta el 62 % de Σd** (2026-T1) y XLM el 29 % de los activos (8 de 20 activos negativos). Sin el mejor trimestre D baja a +0,07 pp.
- **Episodios:** quitando los 10 bloques de 14 d que más aportan, **D pasa a −0,24 pp** (los 10 bloques suman el 219 % de Σd). Sin los 10 bloques con más señales, D = +0,22 pp.
- **Señales simultáneas:** el 60 % de las señales ocurre en velas con ≥ 3 señales y el 45 % con ≥ 5 (máximo 20 en una vela); por eso el bootstrap agrupa activos en bloques de calendario común.
- **Huecos de entrada:** sin diferencias relevantes (media C +0,005 %, referencia +0,001 %).

## 6. Comparaciones secundarias (no modifican el veredicto; Holm descriptivo)

| Referencia | D (pp) | IC 95 % 14 d | p | p Holm | Nota |
|---|---|---|---|---|---|
| **Primaria B** (régimen 9 celdas, ≤ 28 d) | +0,18 | [−0,19; +0,56] | 0,169 | — | inadecuada (M3) |
| S1: solo ±14 d, sin fallback | +0,20 | [−0,17; +0,58] | 0,146 | 0,29 | inadecuada |
| S2: solo tendencia (3 celdas) | +0,45 | [+0,07; +0,85] | 0,013 | 0,064 | inadecuada |
| S3: sin régimen, solo activo y ±28 d | +0,52 | [+0,12; +0,94] | 0,007 | 0,043 | inadecuada |
| S4: permite 56 d | +0,19 | [−0,18; +0,57] | 0,157 | 0,29 | inadecuada |
| A: activo × trimestre (E1) | +0,65 | [+0,16; +1,16] | 0,006 | 0,039 | histórica |
| C (diagnóstica): frente a los otros 19 activos, mismas 12 velas | +0,09 | [−0,03; +0,21] | 0,070 | 0,21 | usa información posterior |
| C (diagnóstica): frente a BTC | +0,34 | [−0,04; +0,74] | 0,040 | 0,16 | usa información posterior |

**Patrón:** el exceso decrece de forma monótona cuanto más estricto es el control de mercado (+0,65 → +0,52 → +0,45 → +0,18 pp). Que la cifra caiga al incluir el régimen de volatilidad de BTC (primaria frente a S2) indica que parte del resultado de E1 es régimen. Frente a los otros 19 activos en la misma ventana el exceso es solo +0,09 pp; esa descomposición es diagnóstica y no una prueba independiente.

## 7. Riesgos metodológicos

1. **Sesgo de selección:** C se descubrió en estos mismos datos y todo el calendario ya fue visto. Ningún p de este informe es confirmatorio.
2. **Emparejamiento imperfecto (hecho):** el retorno reciente de BTC y del mercado no queda equilibrado. Un ajuste adicional (clave con retorno de 6 velas) sería una nueva prueba, no registrada, y requeriría otro preregistro.
3. **Dependencia temporal:** bloques de 7/14/28 d con calendario común para todos los activos; el placebo del procedimiento completo está calibrado (5,0 % de rechazo). Con tan pocos trimestres decisivos, el IC puede seguir siendo optimista ante un episodio dominante.
4. **Sesgo de supervivencia:** 20 pares vigentes hoy.
5. **Costos y ejecución:** costos fijos; el deslizamiento real en velas de expansión probablemente sea mayor, pero afecta casi igual a ambos brazos.
6. **El drawdown de cartera depende de la convención** de 10 % por señal y del capital compartido; 70 operaciones omitidas en la referencia frente a 718 en C en E1 (no es comparable en conjuntos distintos).

## 8. Conclusión y recomendación

- **Hechos:** E1 se reproduce exactamente; la referencia pareada por régimen reduce el exceso de +0,65 a +0,18 pp (IC cruza 0); la referencia pareada produce una expectancy de +0,385 % sin señal alguna; el resultado depende de la segunda mitad, de 2024 y 2026 y de un trimestre que aporta el 62 %.
- **Hipótesis:** la mayor parte de la rentabilidad de C es *timing* de mercado a corto plazo (entradas tras repuntes del mercado) más una parte pequeña, no demostrada, atribuible a la señal.
- **Veredicto del preregistro:** inconcluso por emparejamiento inadecuado. No se afirma que C sea rentable ni que esté descartada.
- **Recomendaciones:**
  1. No implementar C ni cambiar parámetros operativos.
  2. Si se quiere seguir, preregistrar un E3 con una referencia que añada el retorno de 6 velas del mercado a la clave (o emparejamiento por puntuación de propensión) **y** exigir equilibrio previo, sabiendo que esto reduce el exceso observado.
  3. Un registro forward de solo lectura de señales C posteriores a 2026-10-01 es el único camino que da evidencia confirmatoria. Con +0,18 pp de efecto y sd de bloque elevada, harían falta muchos meses para detectarlo.
  4. Sobre la regla madre del negocio: aun suponiendo ventaja real, una racha de 25 pérdidas y un drawdown del 35 % no cumplen con «riesgo bajo».

## 9. Desviaciones

- El preregistro decía para «descartar» incluir un caso por IC de 28 d; se aplicó literalmente la sección 11 (los IC de 28 d solo bloquean el estatus de candidata). No hubo cambios de hipótesis ni de criterios.
- El comando `decide` escribió `decision.json` correctamente pero falló al imprimir en consola por la codificación cp1252 (carácter ≤). No afecta a los resultados.
- Las secciones de subgrupos (mitades, régimen) están basadas en la referencia primaria, que no superó la puerta de equilibrio; se informan como descriptivas.
