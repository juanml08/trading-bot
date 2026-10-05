# Auditoría de familias de señales — informe

Estudio offline y read-only (sin cambios en código del bot ni en BD). Tablas completas por familia en [REPORTE_DATOS.md](REPORTE_DATOS.md); protocolo pre-registrado en [PROTOCOLO.md](PROTOCOLO.md); código en `fetch_data.py`, `audit.py`, `report.py`.

> **Regla madre:** El Trading Bot busca obtener pequeñas ganancias de manera frecuente y consistente, priorizando la preservación del capital y un riesgo bajo sobre la maximización del rendimiento.

## Conclusión

**Ninguna de las cinco familias merece implementarse.** Cuatro quedan en C (sin evidencia) y una en E (mala relación riesgo/retorno). Según el criterio acordado, la conclusión correcta es no implementar nada y buscar otra hipótesis.

El obstáculo principal no es la falta de oportunidades (todas generan 1–8 señales por día en el universo). Es el **costo**: ir al mercado en una vela cualquiera pierde ~0,17 pp por operación a 8h una vez descontado el 0,2% round trip. La mejor ventaja bruta que apareció en todo el estudio (+0,12 pp sobre aleatoria) es menor que la comisión, y esa ventaja no sobrevivió al TEST.

## Clasificación

| Familia | Clase | Motivo principal |
|---|---|---|
| A — Retroceso en tendencia | **C** | Sin exceso significativo sobre aleatoria (8h: +0,03 pp, p=0,37; positivo en solo 2 de 6 horizontes). |
| B — Ruptura | **C** | Exceso bruto pequeño y decreciente (8h: +0,03 pp, p=0,17); no cubre costos en ninguna celda. |
| C — Expansión de volatilidad | **E** | Único edge bruto real en desarrollo (p≈0, 5/5 parámetros), pero con colas malas (peor −19,7%, p5 −4,5%, DD bruto −24%). En TEST se invirtió a 8h. |
| D — Momentum cross-sectional | **C** | Exceso negativo en 6/6 horizontes. Peor que aleatoria. |
| E — Régimen + entrada | **C** | Exceso ≈ 0 en desarrollo y negativo en TEST; el régimen solo tampoco tiene edge (−0,08 pp). |

## Respuesta a la pregunta del objetivo

*¿Puede alguna familia ayudar a convertir 20 USDT en ganancias pequeñas pero frecuentes, con riesgo bajo y preservando el capital?* **No, con la evidencia disponible.** Lo que tuvo frecuencia no tuvo ventaja neta de costos, y lo que tuvo algo de ventaja bruta (C) no fue estable fuera de muestra ni tuvo riesgo aceptable.

## Hallazgos que importan para el siguiente paso

1. **El costo domina.** Referencia de entrar en cualquier vela, neto a 8h: −0,17% (TRAIN+VAL) y −0,14% (TEST). Con ventajas brutas de 0,03–0,12 pp, un round trip de 0,2% las borra. Cualquier hipótesis a esta escala de tiempo parte con ese handicap.
2. **C muestra por qué no basta mirar desarrollo.** En TRAIN+VAL era la señal más sólida (p≈0, todos los parámetros vecinos, 90% de activos). En TEST, a 8h, el exceso fue −0,17 pp (p=0,998). El rendimiento bruto de 1h (+0,06 a +0,09 pp) sí persistió en ambos tramos, pero es 3 veces menor que la comisión.
3. **Dependencia de régimen.** En C el exceso de desarrollo viene casi todo del régimen bajista (+0,78 pp con 990 señales; ≈0 en alcista y lateral) y ese mismo régimen dio −0,45 pp en TEST. En A el exceso es negativo en el régimen lateral (575 de 1.062 señales). Es el patrón de "señal inestable" que se quería detectar.
4. **A tuvo un buen TEST, pero no cuenta.** Exceso +0,175 pp y neto +0,10% a 8h con 177 señales (p=0,12). Falló la compuerta de edge en desarrollo (G1) y fue clasificada C antes de abrir el TEST; reajustarla ahora con el TEST sería exactamente el sobreajuste que se quería evitar. Con 5 familias × 6 horizontes, un resultado así aparece por azar con frecuencia. Solo sirve como pista a verificar con datos nuevos.
5. **El TEST ya se consumió.** Cualquier experimento posterior necesita datos posteriores a 2026-10-01 o un universo distinto como nuevo TEST.

## Riesgos y limitaciones del estudio

- Universo de 20 pares vigentes hoy (sesgo de supervivencia; favorece levemente a las señales).
- Un solo timeframe (1h), un solo conjunto de definiciones por familia, y solo spot long.
- La comisión de 0,2% es la del enunciado. No se modeló slippage, que empeoraría todo, ni descuentos (BNB, maker), que lo mejorarían.
- Los p-valores no se corrigen por comparaciones múltiples. Por eso se exigió coherencia entre horizontes, parámetros, tramos, activos y trimestres, no un p-valor aislado.
- La referencia aleatoria (activo × mes × tramo) se calculó de forma exacta y se validó con 1.500 simulaciones Monte Carlo (media 0,00043 vs 0,00042; σ 0,00031 vs 0,00031).
- **Tres enmiendas al protocolo** se hicieron después de ver resultados de desarrollo y antes de abrir el TEST. Las tres corrigen defectos lógicos de clasificación (el costo mezclado dentro de compuertas de riesgo y robustez, y una serie diaria mal definida que daba drawdowns de −99%). Están en PROTOCOLO.md. No cambiaron la clasificación de ninguna familia.

## Recomendación del siguiente paso

No implementar ninguna señal. Si se quiere seguir investigando, hipótesis nuevas, definidas antes de mirar datos, y con las lecciones de arriba:

1. **Subir la relación movimiento esperado / costo.** Medir las mismas familias (o nuevas) en 4h y 1d, donde el movimiento típico es mucho mayor que el 0,2% fijo. Es el cambio con más palanca según los datos.
2. **Revisar el costo real en la cuenta demo/real** (tipo de orden maker vs taker, BNB). Si un round trip pudiera ser ≤0,1%, varias ventajas brutas observadas cambiarían de signo, aunque ninguna era robusta.
3. **Acumular datos nuevos (paper o histórico posterior a 2026-10-01)** como TEST virgen antes de volver a evaluar cualquier pista, incluida la de A.
4. **No agregar filtros a las familias descartadas.** Cualquier variante debe tratarse como una hipótesis nueva con su propio protocolo.

## Mapa del entregable (24 puntos)

| Punto | Dónde |
|---|---|
| 1 Hipótesis | [PROTOCOLO.md](PROTOCOLO.md) (familias) |
| 2–4 Datos, período, split | REPORTE_DATOS.md §2 |
| 5 Definición de señales | REPORTE_DATOS.md §3 |
| 6 Oportunidades; 16 Frecuencia | REPORTE_DATOS.md §5, por familia |
| 7–8 Retorno bruto y neto; 17 Referencias | REPORTE_DATOS.md §5, tablas por horizonte (Ref A, B, C) |
| 9–15 Win rate, PF, DD, MAE/MFE, días +, peor día, racha | REPORTE_DATOS.md §5, "Riesgo a 8h" |
| 18–20 Por activo, trimestre, régimen | REPORTE_DATOS.md §5 |
| 21 Robustez de parámetros | REPORTE_DATOS.md §5 |
| 22 Riesgos | este documento |
| 23 Clasificación | este documento y REPORTE_DATOS.md §4 |
| 24 Recomendación | este documento |
