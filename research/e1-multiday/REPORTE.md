# E1 — Ruptura (B) y expansión de volatilidad (C) a 24/48/72 h: informe

Ejecutado el 2026-10-11 con `e1.py` según [PREREGISTRO.md](PREREGISTRO.md) (SHA-256 `f13d2c14…4274`, registrado en `results/run_meta.json` y verificado idéntico tras la ejecución). Tablas completas en `results/tables.md`; datos en `results/variants.json`, `decisions_primary.json`, `placebo.json`.

## 1. Veredicto

| Primaria | Veredicto (reglas de la sección 9) |
|---|---|
| **B-48h-R1** (ruptura) | **Investigar más, con tendencia a descartar:** sin ventaja demostrable. Expectancy +0,071 % con IC95 % [−0,50; +0,66], exceso +0,07 pp con p Holm 0,41. Falla 1, 2, 3, 5, 6 y 7. |
| **C-48h-R1** (expansión de volatilidad) | **Investigar más. Mejor candidata de todo el proyecto hasta ahora, pero NO se mantiene como candidata**: falla el criterio de riesgo (drawdown de cartera 35 % frente al límite de 15 %). |

**Nada de esto es evidencia confirmatoria.** Todo el calendario ya había sido examinado por estudios anteriores, y la pista de C a 24–72 h salió precisamente de ahí (`REPORTE_4H.md`). Este resultado es **consistente con ese hallazgo, pero no independiente de él**: el p-valor está sesgado por selección. Solo datos posteriores a 2026-10-01 pueden confirmar.

## 2. Qué se ejecutó

20 pares de `data4h`, 2022-05-14 → 2026-09-27 de barras elegibles (T = 10.404 velas por activo), N = 24, cooldown de 72 h, entrada en la apertura siguiente, salida por tiempo (R1) y variante con stop del 8 % (R2). Costos 0,10 % + 0,05 % por lado (ida y vuelta 0,2997 %, una sola vez por ejecución). Referencia: 2.000 sorteos por activo × trimestre con cooldown, mismo horizonte, salida y costos (déficit de muestreo: 0). Bootstrap de 10.000 remuestreos por bloques de 14 días con todos los activos agrupados. Semilla 20261011. 12 variantes registradas, todas reportadas abajo. Reproducción: `python -I e1.py selftest | run | placebo` en esta carpeta (≈2,5 minutos).

## 3. Resultados (costo base; las 12 variantes)

| variante | tipo | n | win % | gan. media | pérd. media | expectancy neta | IC95 % (14 d) | PF | exceso vs ref | p exceso | p Holm | DD cartera | racha pérd. |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| B-24h-R1 | explor. | 3170 | 42,3 | +3,71 % | −3,04 % | −0,184 % | [−0,53; +0,17] | 0,89 | −0,04 pp | 0,594 | 1,00 | 51,3 % | 24 |
| B-24h-R2 | explor. | 3170 | 42,2 | +3,71 % | −3,04 % | −0,190 % | [−0,53; +0,16] | 0,89 | +0,01 pp | 0,472 | 1,00 | 49,3 % | 24 |
| **B-48h-R1** | **PRIM.** | 3170 | 46,3 | +4,93 % | −4,12 % | +0,071 % | [−0,50; +0,66] | 1,03 | +0,07 pp | 0,412 | 0,412 | 53,7 % | 21 |
| B-48h-R2 | explor. | 3170 | 45,2 | +4,98 % | −4,18 % | −0,037 % | [−0,59; +0,52] | 0,98 | +0,08 pp | 0,381 | 1,00 | 48,1 % | 21 |
| B-72h-R1 | explor. | 3170 | 45,6 | +6,44 % | −5,07 % | +0,177 % | [−0,65; +1,00] | 1,06 | +0,02 pp | 0,482 | 1,00 | 65,0 % | 27 |
| B-72h-R2 | explor. | 3170 | 43,8 | +6,42 % | −4,92 % | +0,050 % | [−0,66; +0,77] | 1,02 | +0,07 pp | 0,425 | 1,00 | 53,1 % | 28 |
| C-24h-R1 | explor. | 3397 | 46,8 | +3,79 % | −3,28 % | +0,032 % | [−0,37; +0,42] | 1,02 | +0,22 pp | 0,122 | 0,733 | 39,8 % | 37 |
| C-24h-R2 | explor. | 3397 | 46,3 | +3,76 % | −3,20 % | +0,022 % | [−0,34; +0,37] | 1,01 | +0,27 pp | 0,062 | 0,433 | 41,5 % | 37 |
| **C-48h-R1** | **PRIM.** | 3397 | 49,6 | +5,24 % | −4,04 % | **+0,563 %** | **[+0,03; +1,11]** | **1,28** | **+0,65 pp** | **0,0056** | **0,0112** | **35,0 %** | 25 |
| C-48h-R2 | explor. | 3397 | 48,6 | +5,18 % | −4,10 % | +0,412 % | [−0,11; +0,93] | 1,20 | +0,61 pp | 0,008 | 0,080 | 35,6 % | 21 |
| C-72h-R1 | explor. | 3397 | 48,7 | +6,59 % | −4,86 % | +0,719 % | [+0,02; +1,42] | 1,29 | +0,70 pp | 0,016 | 0,140 | 42,2 % | 32 |
| C-72h-R2 | explor. | 3397 | 47,3 | +6,50 % | −4,82 % | +0,532 % | [−0,09; +1,18] | 1,21 | +0,66 pp | 0,016 | 0,140 | 35,6 % | 32 |

Los p Holm de las primarias pertenecen a la familia F1 (2 pruebas). Los de las exploratorias, a F2 (10 pruebas), solo descriptivos.

**Sensibilidad a costos (expectancy neta por operación) y costo de equilibrio:**

| | 0,20 % | 0,30 % | 0,40 % | 0,50 % | equilibrio |
|---|---|---|---|---|---|
| B-48h-R1 | +0,171 % | +0,071 % | −0,029 % | −0,130 % | 0,371 % |
| C-48h-R1 | +0,664 % | +0,563 % | +0,462 % | +0,361 % | 0,858 % |
| C-72h-R1 | +0,819 % | +0,718 % | +0,617 % | +0,516 % | 1,011 % |

## 4. C-48h-R1 frente a los 8 criterios

| # | Criterio | Resultado |
|---|---|---|
| 1 | Expectancy > 0 y límite inferior del IC (14 d) > 0 | **Cumple** (+0,563 %; límite inferior +0,031 %). Frágil: con bloques de 28 días el límite inferior es −0,005 %; con 7 días, +0,007 %. |
| 2 | Exceso > 0 y p Holm < 0,05 | **Cumple** (+0,65 pp; p Holm 0,0112). IC95 % del exceso [+0,16; +1,16] pp. |
| 3 | Neta > 0 con costo de 0,40 % | **Cumple** (+0,462 %). |
| 4 | Positiva en ambas mitades y en ≥ 60 % de los años | **Cumple.** Mitades +0,21 % y +0,95 %; años positivos 2023–2026 (4 de 5); **2022 fue −0,40 %** (exceso +0,16 pp). |
| 5 | Concentración | **Cumple.** Mejor activo XLM 14 % del P/L; sin él +0,51 %. LTC es el único activo con suma negativa (−107 pp). |
| 6 | PF ≥ 1,15 | **Cumple** (1,28). |
| 7 | DD de cartera ≤ 15 % | **No cumple: 35,0 %.** |
| 8 | n ≥ 150 y ≥ 30 bloques | **Cumple** (3.397 señales; 227 bloques de 7 días con señales). |

Detalles de la cartera (10 % del patrimonio por señal, capital compartido, 718 operaciones omitidas por falta de efectivo, exposición media 31 %): retorno +131,7 % y drawdown 35,0 %. La referencia aleatoria con las mismas reglas rinde una mediana de −33,8 % (rango −74,5 % a +54,1 %) con drawdown mediano de 55,9 %. Es decir, el drawdown de la señal es **menor** que el de entrar al azar, pero la cartera sigue siendo una exposición amplia a un mercado cripto correlacionado, y no cumple la regla madre de riesgo bajo.

## 5. Riesgo y forma de la distribución (C-48h-R1)

- Win rate 49,6 % (IC95 % [45,9; 53,2]); ganancia media +5,24 %, pérdida media −4,04 %. No es la imagen de «pequeñas ganancias frecuentes»: son movimientos grandes y ganancias algo mayores que las pérdidas, con win rate de moneda al aire.
- Peor operación −45,3 %; percentil 5 −8,6 %; racha máxima de 25 pérdidas seguidas (por orden de salida). A 48 h estos números chocan con «preservar el capital».
- El stop del 8 % (R2, exploratorio) recorta la expectancy de +0,563 % a +0,412 % y no baja el drawdown (35,6 %): no mejora el perfil.
- Por régimen BTC: alcista +1,00 % (exceso +0,48 pp), lateral +0,26 % (+0,44 pp), bajista +0,82 % (+1,53 pp). El exceso es positivo en los tres.
- Vecindario de N (descriptivo): N = 18, 21, 24, 27, 30 dan +0,455 %, +0,541 %, +0,563 %, +0,568 %, +0,575 % de expectancy; la ventaja no es un punto aislado de la grilla. En B el vecindario es +0,05 % a +0,13 %, es decir, nada.

## 6. B-48h-R1 frente a los criterios

Fallan 1, 2, 3, 5, 6 y 7. Expectancy +0,071 % con IC que cruza 0 de lado a lado, exceso +0,07 pp (p Holm 0,41), PF 1,03 y equilibrio de costo 0,37 %. El mejor activo (XLM) aporta el **53 %** del P/L, hay años de −0,81 % (2022) y −0,69 % (2025), y el drawdown de cartera es 53,7 %. B no aporta evidencia de ventaja.

## 7. Validaciones de la metodología

- `selftest`: todas pasan. Fórmula de costos reproducida con una compra/venta explícita; stop con hueco, toque intravela, toque en la vela de entrada y coincidencia con la salida por tiempo (gana el stop); señales causales (alterar velas futuras no cambia el pasado) y cooldown ≥ 18 velas; referencia sin duplicados, con espaciado, con k igual al de las señales y esperanza con espaciado ≈ analítica (diferencia media 0,11 pp con 200 sorteos); cartera de una operación igual al 10 % del retorno neto.
- `placebo` (200 desplazamientos circulares comunes a todos los activos, ≥ 60 días, conservando agrupamiento): tasa de falsos positivos del test de exceso de **2,0 % (B) y 1,5 % (C)** frente a 5 % nominal, y cobertura del IC95 % de 97,5 % en ambas. El bootstrap por bloques es **ligeramente conservador, no optimista**.
- Hash del preregistro sin cambios tras ejecutar. Sin desviaciones en hipótesis ni criterios. Detalles menores: el costo ida y vuelta base resulta 0,2997 % (el preregistro decía ≈0,2998 %), y el escenario «0,30 %» de la sensibilidad escala exactamente a 0,30 %, ligeramente distinto del base.

## 8. Limitaciones

1. **No es confirmatorio.** Calendario ya visto; la hipótesis C a 24–72 h nació de ese calendario. El p ajustado por Holm corrige 2 pruebas, no la selección previa.
2. **La referencia aparea por activo y trimestre, no por momento del mercado.** Una parte del exceso puede ser *market timing*: las velas de expansión alcista coinciden con tramos alcistas del conjunto del mercado. `REPORTE_4H.md` ya había visto que frente a la referencia de mercado en la misma barra el exceso a 48 h era débil e inestable. Aquí no se probó una referencia pareada por barra (habría sido una prueba no registrada). Sigue siendo una señal observable antes de entrar, pero conviene saber cuánto es selección de activo y cuánto es régimen.
3. **Cartera:** el DD de la cartera depende de la convención de 10 % por señal; las señales se agrupan en rachas de mercado, con hasta unas 10 posiciones simultáneas y correlacionadas. Con 718 omitidas, la cartera real no ejecuta todas las operaciones del análisis por operación.
4. **Costos:** fijos y proporcionales. Un stop real resbala más que 0,05 %; en velas de volatilidad extrema (justo cuando dispara C) el slippage real probablemente sea mayor. Con 0,50 % el resultado sigue siendo positivo (+0,36 %).
5. **Sesgo de supervivencia:** 20 pares vigentes hoy. Solo spot long.
6. **Dependencia temporal residual:** operaciones que cruzan el borde de un bloque se asignan al bloque de su señal; el IC del neto con bloques de 28 días roza 0.
7. **Datos:** 4h con OHLC sin comprobar contra el mercado real; Binance Demo/Paper puede diferir de las condiciones reales de liquidez.

## 9. Recomendación

- **B (ruptura): descartar.** Sin ventaja neta ni exceso, concentrada en un activo, con drawdown de 54 %. Coincide con el resultado a 8 h de los estudios previos.
- **C (expansión de volatilidad) a 48 h: investigar más; mantenerla solo como pista, no como candidata a implementar.** Es la primera señal del proyecto con expectancy neta positiva, exceso significativo tras corregir por 2 pruebas, estabilidad en el vecindario de N, tres regímenes, 4 de 5 años y resistencia a costos hasta 0,86 %. Pero:
  - el criterio de riesgo falla (35 % de drawdown, peor operación −45 %, 25 pérdidas seguidas);
  - el resultado no es independiente de la pista que lo originó;
  - no se ha separado la selección de activo del *market timing*.
- **Pasos mínimos, en este orden:**
  1. Preregistrar (nuevo documento) la hipótesis C-48h con una referencia pareada por barra de mercado, para medir el exceso por selección de activo.
  2. Iniciar un registro forward (solo lectura de precios, sin órdenes) de las señales C posteriores a 2026-10-01. Con ≈2 señales/día harían falta unos 3–4 meses para ≈150–250 señales, que apenas dan potencia para detectar un efecto de +0,5 pp: hay que planear un horizonte de al menos 6 meses.
  3. Definir un criterio de riesgo propio de la cartera (tamaño por señal, tope de exposición simultánea y de correlación) antes de volver a juzgar el drawdown. Sin eso, ninguna versión de C cumple la regla madre.
- **No implementar nada en el bot.** Aun siendo la mejor pista, no pasa el filtro de riesgo ni tiene confirmación fuera de muestra, y `reglas.md` exige validar antes de considerar válida una estrategia.
