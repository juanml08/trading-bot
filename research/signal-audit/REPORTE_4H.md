# Experimento 4h — informe

Estudio offline y read-only (sin cambios en código del bot ni en BD, sin ciclos reales/paper). Protocolo en [PROTOCOLO_4H.md](PROTOCOLO_4H.md) (reutiliza [PROTOCOLO.md](PROTOCOLO.md)); tablas completas en [REPORTE_DATOS_4H.md](REPORTE_DATOS_4H.md); comparación base en [REPORTE.md](REPORTE.md). Scripts: `fetch_data_4h.py`, `audit_4h.py`, `report_4h.py`.

> **Regla madre:** El Trading Bot busca obtener pequeñas ganancias de manera frecuente y consistente, priorizando la preservación del capital y un riesgo bajo sobre la maximización del rendimiento.

## Conclusión

**NO. Ninguna familia justifica pasar de investigación offline a implementación.** Las cinco quedan en **C (sin evidencia)**, igual que en el ciclo de 1h salvo la C de 1h, que era E. No implementar nada y seguir investigando.

Respuesta a la pregunta principal: **el paso a 4h no produce una mejora suficientemente grande ni robusta.** Mejora el *perfil de riesgo* de algunas familias (menos drawdown en B y D) y acerca B al punto de equilibrio neto en desarrollo (+0,02%), pero ninguna familia supera a la referencia aleatoria de forma coherente entre tramos, y todas pierden dinero neto en el TEST-final.

## Qué se hizo

1. Se descargaron velas 4h de Binance (API pública) para los mismos 20 pares: 10.404 velas por activo (2022-01 → 2026-10-01), sin huecos ni datos defectuosos. Detalle en REPORTE_DATOS_4H.md §2.
2. Se escribió el protocolo 4h **antes** de descargar datos. Todos los parámetros conservan su número de velas; solo se convirtieron horizontes, cooldown y embargo (a 18 velas = 72h = horizonte máximo) y la ventana del régimen de mercado (30 días). Horizonte primario: 8h, la misma duración que en 1h, para que la comparación sea directa.
3. Se ejecutó solo TRAIN+VAL, se evaluaron las compuertas G1–G7 y se **congeló** `frozen_4h.json`: las cinco quedaron en C antes de abrir cualquier TEST.
4. Se abrieron **una sola vez** TEST-final y TEST-PRE (ver abajo) y se verificó que la clasificación no cambió.

### Sobre el TEST (contaminación)
- **TEST-final (2026-04-21 → 2026-09-27)** cae sobre el mismo calendario que el TEST de 1h (2026-04-23 → 2026-09-29), ya consumido. Además TRAIN+VAL de 1h ya se había examinado. **En 2023-10 → 2026-10 no existe ningún segmento virgen**, y los 4 días posteriores a 2026-10-01 no alcanzan para nada. Por eso el TEST-final solo puede descartar, no confirmar.
- **TEST-PRE (2022-10-01 → 2023-09-27)** es un período que el experimento de 1h nunca usó (agregado en el protocolo antes de ver resultados). Es la única evidencia no contaminada. Tiene un régimen de mercado distinto (salida del mercado bajista de 2022), así que también sirve para detectar dependencia de régimen.

## Clasificación

| Familia | 1h | 4h | Motivo principal en 4h (8h, TRAIN+VAL) |
|---|---|---|---|
| A — Retroceso en tendencia | C | **C** | Exceso bruto −0,13 pp (p=0,79); solo 0,29 señales/día (<0,5); 2/6 horizontes positivos; negativo en TEST-final y TEST-PRE. |
| B — Ruptura | C | **C** | Exceso +0,12 pp (p=0,02) pero positivo solo en 3/6 horizontes (exige 4) y se invierte a −0,10 pp en TEST-final. Neto +0,02%, PF 1,02. |
| C — Expansión de volatilidad | E | **C** | Exceso +0,115 pp (p=0,03) y 6/6 horizontes positivos, pero VAL es −0,03 pp. Falla G1 a 8h; neto −0,015%. Negativo en TEST-final. |
| D — Momentum cross-sectional | C | **C** | Exceso +0,02 pp (p=0,37): indistinguible de aleatoria. |
| E — Régimen + entrada | C | **C** | Exceso −0,05 pp, 1/6 horizontes positivos; TEST-final −0,43 pp. |

Ninguna llegó a evaluarse en G2–G8 como candidata: la clasificación se decide en G1 (orden pre-registrado). Los resultados de TEST se reportan igual, completos, en REPORTE_DATOS_4H.md.

## Comparación 1h vs 4h (primario, 8h)

Desarrollo = TRAIN+VAL; "exceso" = vs entrada aleatoria emparejada (referencia C).

| Familia | TF | señales/día | exceso bruto | neto medio | win neto | PF neto | peor operación | DD bruto | TEST-final exceso / neto |
|---|---|---|---|---|---|---|---|---|---|
| A | 1h | 1,18 | +0,03 | −0,07% | 45,7% | 0,91 | −8,2% | −8,8% | +0,18 / +0,10 |
| A | 4h | 0,29 | −0,13 | −0,22% | 47,7% | 0,80 | −12,7% | −9,3% | −0,28 / −0,36 |
| B | 1h | 7,53 | +0,03 | −0,11% | 44,8% | 0,88 | −16,5% | −51,4% | +0,03 / −0,07 |
| B | 4h | 2,07 | +0,12 | **+0,02%** | 45,6% | 1,02 | −19,7% | −12,8% | −0,10 / −0,15 |
| C | 1h | 7,55 | +0,12 | −0,04% | 47,8% | 0,96 | −19,7% | −23,7% | −0,17 / −0,30 |
| C | 4h | 2,07 | +0,12 | −0,02% | 46,6% | 0,99 | −19,7% | −19,0% | −0,20 / −0,28 |
| D | 1h | 5,52 | −0,06 | −0,19% | 43,4% | 0,82 | −31,6% | −41,5% | −0,15 / −0,24 |
| D | 4h | 1,50 | +0,02 | −0,08% | 43,5% | 0,92 | −12,1% | −9,0% | −0,10 / −0,14 |
| E | 1h | 4,95 | +0,02 | −0,12% | 44,0% | 0,87 | −14,5% | −33,0% | −0,16 / −0,27 |
| E | 4h | 1,51 | −0,05 | −0,16% | 41,8% | 0,85 | −11,7% | −29,4% | −0,43 / −0,50 |

Lectura:
- **Mejora real pero modesta:** B pasa de −0,11% a +0,02% neto y D reduce mucho el drawdown (−41% → −9%). Ninguna llega a PF ≥ 1,10 ni a un edge que sobreviva fuera de muestra.
- **Frecuencia:** cae 3–4× (cooldown y barras más largas). Siguen cumpliendo ≥0,5/día todas menos A (0,29).
- **Win rate neto sigue por debajo de 50%** en todas; la referencia de entrar en cualquier vela a 8h sigue costando ≈ −0,14 a −0,29 pp neto en todos los tramos. El costo de 0,2% sigue dominando a 8h.
- **El TEST-final es peor en 4h que en 1h** para A, B y E (el 4h de A, que en 1h fue la «pista» del TEST, no la reproduce).
- **TEST-PRE (virgen)** es muy distinto: B, C, D y E tienen exceso positivo y neto ≥ 0 allí (C: +0,56 pp, neto +0,40%, PF 1,62; E: +0,36 pp, neto +0,20%). Pero esas mismas familias fallan en desarrollo y en TEST-final. Es el patrón de **dependencia de régimen / período** que el protocolo manda descartar (E es el caso más claro: fuerte en 2022-23, negativa en 2023-26).

## Una pista que no se rescata (familia C a horizontes largos)

La familia C tiene exceso positivo a 24h, 48h y 72h en **los cuatro tramos** (TRAIN, VAL, TEST-final y TEST-PRE): por ejemplo a 48h, +0,61 / +1,23 / +0,64 / +0,62 pp, con neto positivo en TRAIN, VAL, TEST-final y TEST-PRE. A 72h, VAL queda en −0,2% neto.

**No cambia la clasificación**, por tres razones:
1. El protocolo fijó 8h como horizonte primario; elegir ahora 48h/72h porque se ve bien es exactamente lo que la regla prohíbe («no seleccionar el horizonte ganador después de ver TEST»). Está pre-registrado como análisis de horizonte, no como candidato.
2. Frente al mercado en la misma barra (exceso B) el resultado es débil e inestable: 48h → +0,14 / −0,11 / +0,48 / +0,02 pp. Gran parte parece *timing de mercado* (velas de expansión alcista coinciden con rachas de mercado) y no selección de activo.
3. A esos horizontes el riesgo no se midió con el mismo detalle (el desglose de colas, MAE/MFE y drawdown es a 8h), y mantener una posición 2–3 días en una vela de volatilidad extrema con un peor caso de −19,7% a 8h es lo contrario de «bajo riesgo» hasta demostrar lo contrario.

Es una **hipótesis nueva** que merece su propio protocolo y datos nuevos, no un resultado de este experimento.

## Riesgos y limitaciones

- **Contaminación del TEST-final** (arriba). El único tramo limpio es TEST-PRE, un solo régimen de un año.
- **Menos potencia estadística**: ~4× menos señales que 1h (A tiene 258 señales en desarrollo, 45 en TEST-final y 78 en TEST-PRE). Esto afecta sobre todo a A y a las condiciones n≥10 de G4/G5.
- **Sin slippage:** el resultado neto utiliza comisión (0,1% por lado), pero no modela slippage; empeoraría todo.
- Sesgo de supervivencia (20 pares vigentes hoy), solo spot long, un único conjunto de definiciones por familia. Misma limitación que en 1h.
- p-valores sin corrección por comparaciones múltiples (5 familias × 6 horizontes × 5 parámetros × 3 segmentos); por eso se exige coherencia, no un p aislado.
- MAE/MFE a 4h usan mínimos/máximos de velas de 4h, así que subestiman la excursión intravela.
- Los umbrales absolutos de G3 (p5 ≥ −4%, peor ≥ −15%, DD ≤ 20%) no se escalaron; a 8h la duración de la posición es la misma que en 1h, así que son comparables.
- **Cambios metodológicos:** ninguno tras ver resultados (registro en PROTOCOLO_4H.md §7).

## Recomendación del siguiente paso

1. **No implementar ninguna familia ni tocar el bot.**
2. **El problema es el costo, no solo la señal.** A 8h entrar en una vela cualquiera cuesta ≈ −0,14 a −0,29 pp neto en todos los tramos. Verificar el costo real de la cuenta (maker vs taker, descuento BNB): si un round trip fuera ≤0,1%, B y C en 4h cambiarían de signo en desarrollo, aunque ninguna sería robusta por eso solo.
3. **Si se sigue investigando, una sola hipótesis nueva y pre-registrada:** C (o su equivalente simple) con horizonte de 24–72h, con protocolo propio, riesgo medido a ese horizonte y **datos posteriores a 2026-10-01 (paper/forward)** como TEST virgen. Hasta tener esos datos, el TEST-PRE ya está consumido para esa hipótesis.
4. **Probar 1d** (siguiente escalón de relación movimiento/costo) como hipótesis separada, con su propio protocolo y sin reutilizar los TEST ya vistos.
5. Dejar que el bot se abstenga (HOLD) es válido y es el resultado correcto de este estudio.

## Reproducir

```
cd research/signal-audit
python fetch_data_4h.py data4h
python audit_4h.py quality data4h results
python audit_4h.py dev  data4h results
python report_4h.py gates          # congela frozen_4h.json
python audit_4h.py test data4h results   # una sola vez
python report_4h.py final          # genera REPORTE_DATOS_4H.md
```
Requiere Python 3 con `numpy` y `requests`. Los datos (`data4h/`, ~10 MB) y los JSON de `results/` quedan en la carpeta.
