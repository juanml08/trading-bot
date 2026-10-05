# Protocolo pre-registrado (escrito ANTES de ver resultados)

Fecha: 2026-10-05. Estudio offline, read-only: no toca código del bot ni BD.

## Datos
- Velas 1h spot de Binance (API pública de market data, sin autenticación), 2023-10-01 00:00 UTC → 2026-10-01 00:00 UTC.
- Universo fijo de 20 pares USDT líquidos: BTC ETH BNB XRP SOL ADA DOGE TRX LINK LTC AVAX DOT ETC BCH ATOM NEAR XLM UNI AAVE IOTA.
  (Sesgo de supervivencia reconocido: son pares vigentes hoy.)
- Barra elegible: con ≥800 velas de historia y ≥24 velas futuras.

## Separación temporal (por tiempo, sobre barras elegibles)
TRAIN 70% / VALIDATION 15% / TEST 15%, con embargo de 24 velas al final de cada tramo.
Etapa `dev` solo carga datos hasta el fin de VALIDATION (el TEST ni se lee). Etapa `test` se corre una sola vez con `frozen.json`.

## Medición de la señal
- Señal observada al cierre de la vela t. Entrada = open[t+1]. Salida = close[t+h], h ∈ {1,2,4,8,16,24}.
- Sin stops, SELL, trailing ni time-exit. Cooldown por activo de 24 velas entre señales.
- Bruto = sin comisión. Neto = bruto − 0,20% (0,1% + 0,1%).
- Horizonte primario pre-registrado: **8h** (se reportan los demás; 5 familias × 6 horizontes ⇒ se exige coherencia entre horizontes, no un p-valor aislado).

## Referencias
- A: todas las barras elegibles (mismo tramo).
- B: retorno equiponderado del universo en la misma barra y horizonte.
- C: entradas aleatorias emparejadas por activo × mes × tramo (mismo número de señales). Se calcula de forma exacta (media y varianza de muestreo sin reemplazo), p-valor unilateral normal. Es la referencia de decisión.
- Exceso = retorno de la señal − referencia.

## Familias (parámetro primario y vecindario fijados a priori)
- **A Retroceso en tendencia**: close>SMA168 y SMA168[t]>SMA168[t−24] y RSI(N) cruza hacia arriba el nivel 35. N∈{10,12,14,16,18}, primario 14.
- **B Ruptura**: primer cierre > máximo de las N velas previas. N∈{18,21,24,27,30}, primario 24.
- **C Expansión de volatilidad**: rango verdadero > 2× media de las N velas previas y vela alcista. N∈{18,21,24,27,30}, primario 24.
- **D Momentum cross-sectional**: el activo entra al top-3 del universo por ROC(N) con ROC>0 (no estaba en el top-3 en t−1). N∈{18,21,24,27,30}, primario 24.
- **E Régimen + entrada**: régimen = BTC>SMA(N) y volatilidad 24h de BTC ≤ su mediana de 720h; entrada = vela alcista con volumen>1,5× media 24h. N∈{160,180,200,220,240}, primario 200. Se reporta además "régimen solo" (todas las barras en régimen) para aislar el aporte de la entrada.

Régimen de mercado para desgloses: retorno de BTC a 720h; >+10% alcista, <−10% bajista, resto lateral.

## Compuertas (sobre desarrollo = TRAIN+VAL, horizonte 8h, parámetro primario salvo G7)
- G1 Edge bruto: exceso vs C > 0 con p<0,10 en TRAIN+VAL, exceso>0 en TRAIN y en VAL por separado, y exceso>0 en ≥4 de 6 horizontes.
- G2 Neto: retorno medio neto >0 (dev), exceso vs referencia A>0 profit factor neto ≥ 1,10 y ≥3 de 5 celdas del vecindario con neto medio>0.
- G3 Riesgo: percentil 5 del neto ≥ −4%, peor operación ≥ −15%, drawdown ≤ 20% medido en la serie diaria BRUTA (ver Enmienda 3).
- G4 Temporal: ≥60% de trimestres (n≥10) con exceso>0; sin el mejor trimestre el exceso sigue >0; exceso>0 en ≥2 de 3 regímenes (n≥20) y ningún régimen con n≥30 con exceso < −0,3%.
- G5 Activos: ≥60% de activos (n≥10) con exceso>0; sin el mejor activo el exceso sigue >0.
- G6 Frecuencia: ≥0,5 señales/día en el universo.
- G7 Robustez (bruta): ≥4 de 5 celdas del vecindario con exceso bruto>0 vs referencia C.
- G8 Fuera de muestra (TEST): exceso bruto>0 y retorno neto medio>0.

## Clasificación (en este orden)
- C: falla G1.
- E: pasa G1 y falla G3.
- D: pasa G1,G3 y falla G4, G5, G7 o el exceso bruto del TEST ≤ 0.
- B: pasa todo lo anterior pero falla G2, G6 o el neto del TEST ≤ 0.
- A: pasa todas.
Una familia descartada no se modifica ni se re-parametriza.

## Enmienda 1 (2026-10-05, tras ver resultados dev, ANTES de abrir el TEST)
El profit factor neto pasó de G3 a G2: en la redacción original, una familia con edge bruto que simplemente no cubre comisiones habría caído en "E (mala relación riesgo/retorno)" por un motivo que no es de riesgo. Sin otros cambios. Se hizo después de ver los resultados de desarrollo (no del TEST); queda declarado.

## Enmienda 2 (2026-10-05, antes de abrir el TEST)
Mismo defecto que la enmienda 1: la condición de ≥3/5 celdas con neto>0 estaba en G7 y habría clasificado como D (inestable) a una familia que solo no cubre comisiones. Pasa a G2; G7 queda como robustez bruta. Hecha tras ver resultados dev; declarada.

## Enmienda 3 (2026-10-05, antes de abrir el TEST)
La serie diaria para el drawdown estaba sin definir; mi primera implementación reinvertía el 100% del capital cada día con el promedio de las señales de ese día, lo que daba drawdowns artificiales (−99%). Convención definitiva: cada señal usa 1/max(10, nº de señales del día) del capital (≈10% por señal). El drawdown de G3 se mide sobre la serie bruta (riesgo de la señal, separado del costo, que juzga G2); el drawdown neto se reporta de todos modos. Hecha tras ver resultados dev; declarada.
