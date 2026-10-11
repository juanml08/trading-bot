# Auditoría de E2 (solo lectura) — previa a E3

Hecha el 2026-10-11 leyendo `research/e2-market-control/{e2.py, PREREGISTRO.md, REPORTE.md, results/*}` y `research/e1-multiday/e1.py`, y **recalculando** los números con código propio (`python -I e3.py audit` → `results/audit_e2.json`). Ningún archivo de E1/E2 se modificó.

## Resumen

| Hallazgo | Gravedad | Efecto sobre E2 |
|---|---|---|
| **F1.** Sin zona de exclusión temporal: ~40 % de los controles de E2 caen a ≤ 18 velas de la señal; 30 % solapan la ventana de la operación y 14 % contienen la propia vela de la señal en su ventana de retorno. | **Alta (sesgo en la referencia)** | La referencia de E2 está inflada (+0,385 %); D queda **subestimado** y la afirmación «la rentabilidad de C se explica por contexto» es **más débil de lo que el informe dice**. El veredicto INCONCLUSO sigue en pie (por M3), pero D = +0,18 pp no es una estimación limpia. |
| **F2.** El emparejamiento no controló `btc_ret6` / `mkt_ret6` (ya declarado por E2: SMD 0,36 / 0,38). | Alta (declarada) | Motivo del INCONCLUSO de E2. E3 lo corrige con calipers. |
| **F3.** El placebo está calibrado, pero **no puede detectar F1 ni F2**: los desplazamientos rompen la asociación señal–*timing* y alejan la señal de su propio pico. | Media | La «calibración» de E2 se refiere solo a falsos positivos bajo una nula sin confusión. |
| **F4.** La comparación de cartera/drawdown C-vs-referencia no es homogénea: el 60 % de las señales de C cae en velas con ≥ 3 señales simultáneas; en los controles solo el 7 %. | Media | El drawdown de la referencia (35 %) no es evidencia de que C «no aporte» control de riesgo, ni al revés. |
| Resto (costos, señales, régimen causal, bootstrap, SMD, tablas del informe) | — | Verificado **sin errores**. |

**Ninguno invalida los datos ni el procedimiento de E2 hasta el punto de impedir E3**; F1 y F2 se corrigen dentro de los límites autorizados (nuevo preregistro, nuevo código en `e3-market-control/`). Se informa abajo el impacto de F1 antes de continuar.

## Verificación por punto solicitado

### 1. Retornos previos de BTC
`ret180 = c_BTC[t]/c_BTC[t−180] − 1` (tendencia ±10 %), `vol42 = std de 42 retornos simples terminados en t`, percentil en las últimas 720 velas inclusive (`e2.py:51-60`). `btc_ret6 = c_BTC[t]/c_BTC[t−6] − 1` (`e2.py:61`). Todos usan solo cierres ≤ t; la entrada es la apertura de t+1. Verificado con la prueba de causalidad (alterar velas posteriores a t₀ no cambia ninguna covariable ≤ t₀; pasa en E2 y en `e3.py selftest`). **Sin fuga.**

### 2. Retorno del mercado
`mkt_ret6 = mean_a(c_a[t]/c_a[t−6] − 1)`, media equiponderada de los **20 pares** de `data4h`, **incluido el propio activo de la señal** (`e2.py:62`). Consecuencias: (a) el activo de la señal aporta ~1/20 de su propio salto (+3,2 % medio en 6 velas); excluirlo (19 activos) deja el SMD en **+0,355** (frente a +0,379): la conclusión no cambia; (b) universo = 20 pares vigentes hoy (sesgo de supervivencia) y equiponderado (dominado por altcoins; σ = 3,36 % frente a 2,52 % de BTC). E3 conserva la definición (comparabilidad) y publica el equilibrio de la variante sin el propio activo como diagnóstico.

### 3. Variables de emparejamiento y fuga
Clave (tendencia × tercil de volatilidad de BTC) y covariables se calculan en t (cierre) para la señal y en t′ para el control. La exclusión de «señal bruta» usa solo información en t′. No hay fuga hacia adelante. Única información posterior a t en E2: la referencia diagnóstica «frente a otros 19 / BTC» (rotulada como tal, no usada para decidir).

### 4. SMD y comparabilidad
`SMD = (m_señal − m_ref)/√((v_señal + v_ref)/2)` por sorteo sobre las incluidas, promediado en 2.000 sorteos (`e2.py:192-207`). **Recalculado: coincide exactamente** (btc_ret180 +0,0226; btc_vol42 −0,0218; btc_ret6 +0,3629; mkt_ret6 +0,3791). Limitaciones: solo compara medias y varianzas (no colas), y `asset_ret6` (SMD +0,63) difiere por construcción porque la señal *es* una vela de expansión. Las observaciones emparejadas **no** son comparables en el retorno reciente de BTC/mercado (señales: +1,22 %/+1,60 %; controles: +0,18 %/+0,21 %).

### 5. Ventanas, exclusiones y reutilización — **hallazgo F1**
Mecánica verificada (`e2.py:104-162`): nivel 1 ±84 velas, nivel 2 ±168, mínimo 5 candidatos, ninguna barra repetida dentro de un sorteo, bloqueo ±17 velas por activo; 99,0 % de los controles en ±14 d. Reutilización entre sorteos: mediana de la cuota máxima 2,5 %, p90 7 %.

**Pero la única exclusión temporal era `t′ ≠ t`.** Como la clave de régimen persiste varios días, los controles se concentran cerca de la señal. Medido sobre los 6.744.430 controles de E2 (`audit_e2.json`):

| Controles | Cuota | Expectancy neta media |
|---|---|---|
| a ≤ 18 velas de la señal | 40,0 % | **+1,07 %** |
| a > 18 velas | 60,0 % | **−0,09 %** |
| a ≤ 12 velas (comparten ventana de operación) | 30,3 % | — |
| ventana de retorno que **contiene la propia vela de la señal** (t′ < t ≤ t′+12) | 14,1 % | **+3,00 %** |

Los controles cercanos heredan parte del movimiento de la señal (o su pico), y por eso su retorno es alto. Usando solo controles a > 18 velas en las 3.320 señales que los tienen, D pasa de **+0,21 pp a +0,58 pp** (diagnóstico de auditoría, **no** un resultado de E3; todavía incluye el desequilibrio F2).

**Impacto:** la referencia de E2 está sesgada al alza y D al baja. Afirmaciones de `REPORTE.md` que quedan debilitadas: «la mayor parte de la rentabilidad de E1 se explica por *dónde y cuándo* hay entradas» y «el exceso decrece de forma monótona cuanto más estricto el control». El INCONCLUSO sigue siendo correcto (M3 falló de forma independiente), pero no debe leerse como «C ≈ azar condicionado al régimen».

### 6. Bootstrap
Bloques de calendario (7/14/28 d) definidos por la fecha de la señal (`ts[rt]` relativo al primer elegible), **con los mismos índices de bloque para todos los activos** en cada remuestreo (`e1.boot_indices` genera una matriz [B, nb] compartida): conserva el co-movimiento dentro de un bloque y la dependencia temporal dentro del bloque; no la de bloques contiguos. 10.000 remuestreos, semillas SEED+días, estadístico razón de sumas. **IC y p recalculados: coinciden exactamente** (14 d: [−0,187; +0,558] pp, p = 0,169; 7 d: [−0,246; +0,609]; 28 d: [−0,193; +0,570]). Limitaciones: el IC condiciona a los m_i (D por sorteo entre +0,02 y +0,36 pp, informado); 114 bloques de 14 d no vacíos; un trimestre aporta el 62 % de Σd, de modo que el IC puede ser optimista; la hipótesis se eligió en estos datos.

### 7. Placebo
Reproduce el procedimiento completo (señales y máscara bruta desplazadas, cooldown, pools, sorteos, inclusión, D, bootstrap) — verificado en `e2.py:484-518`. Resultado: rechazo 5,0 %, cobertura 95,0 %, media D −0,003 pp. **Interpretación correcta:** calibración frente a falsos positivos cuando la señal no guarda relación con el precio. Error de Monte Carlo de una tasa del 5 % con 200 placebos ≈ ±1,5 pp, y los 200 desplazamientos reutilizan el mismo calendario (no independientes). Además usa 100 sorteos y 2.000 remuestreos (frente a 2.000/10.000 en el análisis real). **No detecta F1 ni F2:** tras el desplazamiento la señal ya no coincide con un pico de mercado ni con su propio pico, así que no hay desequilibrio ni controles contaminados que detectar. E3 lo reconoce y añade una prueba sintética de confusión en `selftest`.

### 8. Costos, expectativas y drawdown frente a E1
- Fórmula `(1−c)²(1−s)/(1+s)` contrastada con compra/venta explícita (error < 1e-12); ida y vuelta 0,2998 % (≈ 0,30 %); escalado 0,40 % exacto por bisección.
- Reproducción de E1 **idéntica**: n = 3.397, expectancy +0,5630 %, PF, racha 25, cartera +131,7 % / DD 35,0 % / 718 omitidas (diferencias = 0).
- D recalculado por costo: +0,1812/+0,1810/+0,1808/+0,1806 pp (0,20/0,30/0,40/0,50 %), igual al informe. Mitades recalculadas: −0,08 / +0,46 pp (igual al informe). Resto de cifras del informe (años, régimen, concentración, secundarias, Holm) contrastadas con `primary.json`/`secondary.json`: **coinciden**.
- **F4 (drawdown):** la cartera de C (3.397 señales, capital compartido) y la de la referencia (controles de las incluidas, 100 sorteos) no son comparables: el 60 % de las señales de C está en velas con ≥ 3 señales, frente al 7 % de los controles; por eso la referencia omite ~70 operaciones y C 718. El drawdown de E2 (35 % ambas) no es una comparación homogénea. E3 reporta el drawdown de C como métrica de riesgo **independiente** y la referencia con esta salvedad.

## Qué se corrige en E3 (decidido por diseño, no por resultados de E3)
1. **Zona de exclusión |t′−t| > 18 velas** (cooldown de C; evita solape y contaminación) — F1.
2. **Calipers de 0,25 DE** en `btc_ret6` y `mkt_ret6` — F2.
3. SMD ≤ 0,10 en las cuatro covariables (en E2 ≤ 0,20 en las de 6 velas) y estabilidad por sorteo.
4. Sin segundo nivel automático ni ±56 d: ±14 d primaria; ±28 d solo como análisis separado con sus propias puertas.
5. Criterios de ventaja que exigen coherencia entre bloques de 7/14/28 d, costo 0,40 %, mitades/años y robustez a los 5 bloques más influyentes; riesgo evaluado aparte.
6. Ablación escalonada (AB0–AB2) para que el lector vea qué parte del cambio viene de cada corrección.
7. Prueba sintética de confusión en `selftest`.

## Conclusión de la auditoría
No hay errores de cálculo en E2 (todo se reproduce exactamente), pero sí **un defecto de diseño (F1) que sesga la referencia y subestima D**, y un emparejamiento incompleto (F2). Ninguno impide construir una comparación válida con los datos locales (se dispone de 60 % de controles a > 18 velas y del retorno a 6 velas), de modo que se continúa con E3. Si las puertas de E3 no se cumplen, el resultado será INCONCLUSO y no se reintentará esta hipótesis con más relajaciones.
