# Apéndice de datos — experimento 4h (auditoría de familias de señales)

Estudio offline, read-only. No se modificó código del bot ni BD. Protocolo pre-registrado en [PROTOCOLO_4H.md](PROTOCOLO_4H.md) (reutiliza [PROTOCOLO.md](PROTOCOLO.md)). Informe: [REPORTE_4H.md](REPORTE_4H.md).

> **Regla madre:** El Trading Bot busca obtener pequeñas ganancias de manera frecuente y consistente, priorizando la preservación del capital y un riesgo bajo sobre la maximización del rendimiento.

## 1. Resumen

| Familia | Parámetro primario | Clase 1h | Clase 4h (pre-TEST) | Clase 4h final |
|---|---|---|---|---|
| A — Retroceso en tendencia | 14 | C | C | **C** |
| B — Ruptura | 24 | C | C | **C** |
| C — Expansión de volatilidad | 24 | E | C | **C** |
| D — Momentum cross-sectional | 24 | C | C | **C** |
| E — Régimen + entrada | 200 | C | C | **C** |

## 2. Datos, período y separación

- Velas **4h** spot de Binance (`data-api.binance.vision`, API pública sin autenticación), solo velas cerradas. 20 pares USDT: BTC, ETH, BNB, XRP, SOL, ADA, DOGE, TRX, LINK, LTC, AVAX, DOT, ETC, BCH, ATOM, NEAR, XLM, UNI, AAVE, IOTA. Sesgo de supervivencia reconocido (pares vigentes hoy), igual que en 1h.
- Descarga: 2022-01-01 → 2026-10-01 00:00 UTC: **10.404 velas por activo (208.080 en total)**, sin huecos, sin volumen cero, sin velas OHLC incoherentes (ver `results/calidad_datos_4h.json`).
- Tramo principal (mismo calendario que 1h; calentamiento desde 2023-04-01): primera barra elegible 2023-11-03 08:00 UTC. Velas por activo en el tramo principal cargado: 7.674.
- TRAIN: 2023-11-03T08:00:00.000 → 2025-11-10T16:00:00.000
- VAL: 2025-11-13T20:00:00.000 → 2026-04-18T16:00:00.000
- TEST-final: 2026-04-21T20:00:00.000 → 2026-09-27T16:00:00.000 (**contaminado**: coincide casi por completo con el TEST de 1h, 2026-04-23 → 2026-09-29, ya consumido).
- TEST-PRE (virgen para estas hipótesis, nunca usado en 1h): 2022-10-01T00:00:00.000 → 2023-09-27T16:00:00.000.
- Embargo de 18 velas (72h) entre tramos. TEST-final y TEST-PRE se abrieron una sola vez, juntos, con `frozen_4h.json` ya escrito.
- Capital de referencia (20 USDT) **no interviene**: todo es retorno porcentual por operación. Capital y tamaño de posición se diseñan después.
- Comisión: bruto = 0; neto = −0,20% round trip (0,1% por lado). **El resultado neto utiliza comisión, pero no modela slippage.** Entrada en open[t+1] tras la señal en el cierre de t; salida en close[t+h]. Sin stops, SELL, trailing ni time exit. Cooldown 18 velas por activo.

### Referencia A (cualquier barra elegible), horizonte 8h

| Tramo | n | bruto medio | neto medio | win neto | PF neto | p5 neto |
|---|---|---|---|---|---|---|
| TRAIN | 88620 | +0.063% | -0.137% | 45.6% | 0.85 | -4.01% |
| VAL | 18720 | -0.086% | -0.286% | 41.3% | 0.68 | -3.65% |
| TRAIN+VAL | 107340 | +0.037% | -0.163% | 44.9% | 0.83 | -3.94% |
| TEST | 19080 | +0.061% | -0.139% | 43.2% | 0.81 | -3.07% |
| TEST-PRE | 43420 | +0.001% | -0.199% | 41.8% | 0.74 | -3.19% |

## 3. Definición exacta de las señales (parámetros en velas de 4h)

- **A Retroceso en tendencia**: close>SMA168 y SMA168[t]>SMA168[t−24] y RSI Wilder(N) cruza al alza el nivel 35. N∈{10,12,14,16,18}.
- **B Ruptura**: primer cierre > máximo de los N máximos previos. N∈{18,21,24,27,30}.
- **C Expansión de volatilidad**: rango verdadero > 2× media de las N velas previas y vela alcista (close>open). N∈{18,21,24,27,30}.
- **D Momentum cross-sectional**: el activo entra al top-3 del universo por ROC(N) con ROC>0 (no estaba en t−1). N∈{18,21,24,27,30}.
- **E Régimen + entrada**: BTC>SMA(N) y vol de BTC (24 velas) ≤ mediana de 720 velas (régimen); entrada = vela alcista con volumen>1,5× media de 24 velas. N∈{160,…,240}.
- Régimen de mercado para desgloses: retorno de BTC a 30 días (180 velas); >+10% alcista, <−10% bajista.
- Cooldown de 18 velas por activo en todas. Los parámetros conservan su número de velas (ver PROTOCOLO_4H.md §2).

## 4. Compuertas (desarrollo = TRAIN+VAL, 8h) y resultado fuera de muestra

### A — Retroceso en tendencia

| Compuerta | ¿Pasa? | Detalle |
|---|---|---|
| G1 edge bruto vs aleatoria | **no** | exceso C 8h -0.131% (p=0.790); TRAIN -0.173%, VAL +0.323%; horizontes con exceso>0: 2/6 |
| G2 neto/PF | **no** | neto medio -0.219%, exceso A -0.056%, PF neto 0.80; celdas con neto>0: 0/5 |
| G3 riesgo | **no** | p5 neto -4.68%, peor -12.67%, DD bruto (slots 10%) -9.3% |
| G4 temporal/régimen | **no** | trimestres+ 50% (8), sin mejor trim. ≤0; regímenes+ 1/2, régimen con exceso<-0,3% |
| G5 activos | **no** | activos+ 33% (18), sin mejor activo ≤0 |
| G6 frecuencia | **no** | 0.29 señales/día |
| G7 robustez de parámetros | **no** | celdas con exceso bruto>0: 0/5 |
| G8a TEST-final (contaminado) | **no** | n=45, exceso C 8h -0.279% (p=0.830), neto medio -0.363% |
| G8b TEST-PRE (virgen) | **no** | n=78, exceso C 8h -0.135% (p=0.688), neto medio -0.281% |

### B — Ruptura

| Compuerta | ¿Pasa? | Detalle |
|---|---|---|
| G1 edge bruto vs aleatoria | **no** | exceso C 8h +0.121% (p=0.020); TRAIN +0.128%, VAL +0.084%; horizontes con exceso>0: 3/6 |
| G2 neto/PF | **no** | neto medio +0.021%, exceso A +0.184%, PF neto 1.02; celdas con neto>0: 3/5 |
| G3 riesgo | **no** | p5 neto -3.90%, peor -19.66%, DD bruto (slots 10%) -12.8% |
| G4 temporal/régimen | **no** | trimestres+ 55% (11), sin mejor trim. >0; regímenes+ 3/3 |
| G5 activos | sí | activos+ 60% (20), sin mejor activo >0 |
| G6 frecuencia | sí | 2.07 señales/día |
| G7 robustez de parámetros | sí | celdas con exceso bruto>0: 5/5 |
| G8a TEST-final (contaminado) | **no** | n=300, exceso C 8h -0.105% (p=0.807), neto medio -0.152% |
| G8b TEST-PRE (virgen) | sí | n=637, exceso C 8h +0.256% (p=0.002), neto medio +0.118% |

### C — Expansión de volatilidad

| Compuerta | ¿Pasa? | Detalle |
|---|---|---|
| G1 edge bruto vs aleatoria | **no** | exceso C 8h +0.115% (p=0.026); TRAIN +0.149%, VAL -0.030%; horizontes con exceso>0: 6/6 |
| G2 neto/PF | **no** | neto medio -0.015%, exceso A +0.148%, PF neto 0.99; celdas con neto>0: 0/5 |
| G3 riesgo | **no** | p5 neto -4.98%, peor -19.66%, DD bruto (slots 10%) -19.0% |
| G4 temporal/régimen | **no** | trimestres+ 55% (11), sin mejor trim. ≤0; regímenes+ 1/3 |
| G5 activos | sí | activos+ 65% (20), sin mejor activo >0 |
| G6 frecuencia | sí | 2.07 señales/día |
| G7 robustez de parámetros | sí | celdas con exceso bruto>0: 4/5 |
| G8a TEST-final (contaminado) | **no** | n=335, exceso C 8h -0.195% (p=0.961), neto medio -0.284% |
| G8b TEST-PRE (virgen) | sí | n=833, exceso C 8h +0.564% (p=0.000), neto medio +0.397% |

### D — Momentum cross-sectional

| Compuerta | ¿Pasa? | Detalle |
|---|---|---|
| G1 edge bruto vs aleatoria | **no** | exceso C 8h +0.024% (p=0.371); TRAIN +0.017%, VAL +0.057%; horizontes con exceso>0: 3/6 |
| G2 neto/PF | **no** | neto medio -0.081%, exceso A +0.082%, PF neto 0.92; celdas con neto>0: 0/5 |
| G3 riesgo | **no** | p5 neto -4.27%, peor -12.12%, DD bruto (slots 10%) -9.0% |
| G4 temporal/régimen | **no** | trimestres+ 55% (11), sin mejor trim. ≤0; regímenes+ 1/3 |
| G5 activos | **no** | activos+ 50% (20), sin mejor activo ≤0 |
| G6 frecuencia | sí | 1.50 señales/día |
| G7 robustez de parámetros | **no** | celdas con exceso bruto>0: 3/5 |
| G8a TEST-final (contaminado) | **no** | n=228, exceso C 8h -0.096% (p=0.741), neto medio -0.144% |
| G8b TEST-PRE (virgen) | sí | n=543, exceso C 8h +0.162% (p=0.049), neto medio +0.007% |

### E — Régimen + entrada

| Compuerta | ¿Pasa? | Detalle |
|---|---|---|
| G1 edge bruto vs aleatoria | **no** | exceso C 8h -0.050% (p=0.774); TRAIN -0.119%, VAL +0.382%; horizontes con exceso>0: 1/6 |
| G2 neto/PF | **no** | neto medio -0.155%, exceso A +0.008%, PF neto 0.85; celdas con neto>0: 0/5 |
| G3 riesgo | **no** | p5 neto -4.00%, peor -11.69%, DD bruto (slots 10%) -29.4% |
| G4 temporal/régimen | **no** | trimestres+ 55% (11), sin mejor trim. ≤0; regímenes+ 1/2 |
| G5 activos | **no** | activos+ 45% (20), sin mejor activo ≤0 |
| G6 frecuencia | sí | 1.51 señales/día |
| G7 robustez de parámetros | **no** | celdas con exceso bruto>0: 0/5 |
| G8a TEST-final (contaminado) | **no** | n=291, exceso C 8h -0.425% (p=1.000), neto medio -0.496% |
| G8b TEST-PRE (virgen) | sí | n=456, exceso C 8h +0.356% (p=0.000), neto medio +0.200% |

## 5. Resultados por familia (parámetro primario)

### A — Retroceso en tendencia (N=14) → clase final **C**

**Oportunidades** (TRAIN+VAL): 258 señales — 0.29/día en el universo, 0.014 por activo-día, 2.0/semana, 9/mes. TEST-final: 45 (0.28/día). TEST-PRE: 78 (0.22/día).

**Consistencia entre tramos (8h)**

| Tramo | n | bruto medio | neto medio | exceso C | p (C) | PF neto |
|---|---|---|---|---|---|---|
| TRAIN | 236 | -0.048% | -0.248% | -0.173% | 0.840 | 0.78 |
| VAL | 22 | +0.289% | +0.089% | +0.323% | 0.206 | 1.16 |
| TEST-final | 45 | -0.163% | -0.363% | -0.279% | 0.830 | 0.46 |
| TEST-PRE | 78 | -0.081% | -0.281% | -0.135% | 0.688 | 0.65 |

**Retornos por horizonte — TRAIN+VAL**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 4h | 258 | -0.279% | -0.191% | -0.479% | +0.018% | -0.271% | -0.297% | -0.007% | -0.335% | 0.998 |
| 8h | 258 | -0.019% | +0.112% | -0.219% | +0.037% | -0.032% | -0.056% | +0.013% | -0.131% | 0.790 |
| 16h | 258 | +0.328% | +0.317% | +0.128% | +0.073% | +0.211% | +0.255% | +0.117% | +0.106% | 0.322 |
| 24h | 258 | +0.026% | +0.093% | -0.174% | +0.109% | -0.016% | -0.083% | +0.042% | -0.311% | 0.862 |
| 48h | 258 | +0.464% | +0.050% | +0.264% | +0.209% | +0.533% | +0.255% | -0.069% | -0.147% | 0.644 |
| 72h | 258 | +0.984% | +0.822% | +0.784% | +0.308% | +0.858% | +0.677% | +0.126% | +0.127% | 0.396 |

**Retornos por horizonte — TEST-final (contaminado)**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 4h | 45 | -0.007% | -0.022% | -0.207% | +0.031% | -0.005% | -0.038% | -0.002% | -0.068% | 0.628 |
| 8h | 45 | -0.163% | -0.198% | -0.363% | +0.061% | +0.104% | -0.225% | -0.268% | -0.279% | 0.830 |
| 16h | 45 | +0.008% | +0.000% | -0.192% | +0.118% | +0.339% | -0.111% | -0.332% | -0.213% | 0.693 |
| 24h | 45 | +0.819% | +0.413% | +0.619% | +0.180% | +1.004% | +0.638% | -0.185% | +0.480% | 0.185 |
| 48h | 45 | +0.145% | -0.133% | -0.055% | +0.392% | +0.269% | -0.248% | -0.124% | -0.568% | 0.766 |
| 72h | 45 | +0.879% | +0.205% | +0.679% | +0.606% | +1.075% | +0.273% | -0.196% | -0.139% | 0.557 |

**Retornos por horizonte — TEST-PRE (virgen)**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 4h | 78 | -0.111% | +0.013% | -0.311% | +0.000% | -0.100% | -0.111% | -0.011% | -0.138% | 0.764 |
| 8h | 78 | -0.081% | +0.078% | -0.281% | +0.001% | -0.000% | -0.082% | -0.081% | -0.135% | 0.688 |
| 16h | 78 | -0.500% | +0.356% | -0.700% | +0.003% | -0.441% | -0.503% | -0.059% | -0.609% | 0.941 |
| 24h | 78 | +0.232% | +0.649% | +0.032% | +0.009% | -0.454% | +0.224% | +0.686% | +0.068% | 0.444 |
| 48h | 78 | +0.848% | +1.019% | +0.648% | +0.024% | +0.406% | +0.823% | +0.442% | +0.538% | 0.200 |
| 72h | 78 | +0.225% | +0.002% | +0.025% | +0.043% | -0.006% | +0.182% | +0.231% | -0.196% | 0.602 |

**Riesgo a 8h — TRAIN+VAL**: win rate neto 47.7% (bruto 52.3%); PF neto 0.80 (bruto 0.98); media neta -0.219%, mediana neta -0.088%; pérdida media -2.09%, mediana -1.26%; p5/p10 -4.68%/-3.38%; peor operación -12.67%; pérdidas <-2%: 19.0%; ganancias netas (0,1%]: 21.3%; MAE medio/mediana/p5 -2.47%/-1.58%/-6.96%; MFE medio/mediana +1.80%/+1.31%; DD slots 10% neto/bruto -11.0%/-9.3%; días+ (con señal) 46%, días+ (calendario) 6%; peor día -9.94%; racha negativa máx. 3 días; semanas+ (con señal) 48%, semanas+ (calendario) 23%; peor semana -4.54%; racha perdedora máx. 12 operaciones seguidas

**Riesgo a 8h — TEST-final**: win rate neto 28.9% (bruto 42.2%); PF neto 0.46 (bruto 0.70); media neta -0.363%, mediana neta -0.398%; pérdida media -0.95%, mediana -0.81%; p5/p10 -1.80%/-1.64%; peor operación -3.71%; pérdidas <-2%: 4.4%; ganancias netas (0,1%]: 15.6%; MAE medio/mediana/p5 -1.28%/-0.92%/-2.74%; MFE medio/mediana +1.41%/+0.74%; DD slots 10% neto/bruto -1.8%/-1.1%; días+ (con señal) 23%, días+ (calendario) 4%; peor día -3.33%; racha negativa máx. 2 días; semanas+ (con señal) 20%, semanas+ (calendario) 13%; peor semana -1.79%; racha perdedora máx. 9 operaciones seguidas

**Riesgo a 8h — TEST-PRE**: win rate neto 43.6% (bruto 52.6%); PF neto 0.65 (bruto 0.88); media neta -0.281%, mediana neta -0.122%; pérdida media -1.41%, mediana -0.97%; p5/p10 -3.10%/-2.13%; peor operación -9.27%; pérdidas <-2%: 15.4%; ganancias netas (0,1%]: 24.4%; MAE medio/mediana/p5 -1.57%/-0.84%/-5.08%; MFE medio/mediana +1.43%/+0.92%; DD slots 10% neto/bruto -2.6%/-2.0%; días+ (con señal) 51%, días+ (calendario) 7%; peor día -9.27%; racha negativa máx. 3 días; semanas+ (con señal) 43%, semanas+ (calendario) 19%; peor semana -3.33%; racha perdedora máx. 9 operaciones seguidas

**Por activo (TRAIN+VAL)**

| Activo | n | exceso C 8h | neto medio 8h | win neto | señales/mes |
|---|---|---|---|---|---|
| AAVE | 14 | -0.030% | +0.007% | 36% | 0.48 |
| ADA | 7 | +0.165% | +0.089% | 43% | 0.24 |
| ATOM | 12 | -0.952% | -1.103% | 50% | 0.41 |
| AVAX | 11 | +0.270% | +0.199% | 55% | 0.37 |
| BCH | 15 | -0.040% | -0.154% | 47% | 0.51 |
| BNB | 18 | -0.481% | -0.574% | 50% | 0.61 |
| BTC | 19 | -0.068% | -0.167% | 53% | 0.65 |
| DOGE | 13 | -0.562% | -0.511% | 46% | 0.44 |
| DOT | 11 | +0.630% | +0.485% | 36% | 0.37 |
| ETC | 10 | +0.666% | +0.542% | 60% | 0.34 |
| ETH | 13 | -1.372% | -1.455% | 23% | 0.44 |
| IOTA | 11 | +0.418% | +0.297% | 55% | 0.37 |
| LINK | 14 | -1.363% | -1.403% | 50% | 0.48 |
| LTC | 12 | +1.795% | +1.671% | 67% | 0.41 |
| NEAR | 12 | +0.450% | +0.338% | 50% | 0.41 |
| SOL | 14 | -0.579% | -0.616% | 50% | 0.48 |
| TRX | 17 | -0.009% | -0.121% | 53% | 0.58 |
| UNI | 13 | -0.086% | -0.229% | 38% | 0.44 |
| XLM | 9 | +0.000% | -0.160% | 56% | 0.31 |
| XRP | 13 | -0.542% | -0.630% | 38% | 0.44 |

**Por trimestre (TRAIN+VAL)**

| Trimestre | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| 2023Q4 | 36 | -0.007% | +0.045% | 47% |
| 2024Q1 | 36 | +0.739% | +0.729% | 53% |
| 2024Q2 | 19 | +0.624% | +0.338% | 58% |
| 2024Q3 | 20 | -0.353% | -0.504% | 45% |
| 2024Q4 | 51 | -1.650% | -1.754% | 37% |
| 2025Q1 | 8 | +0.805% | +0.780% | 75% |
| 2025Q2 | 30 | +0.689% | +0.562% | 50% |
| 2025Q3 | 34 | -0.480% | -0.505% | 44% |
| 2025Q4 | 2 | +0.655% | +0.436% | 50% |
| 2026Q1 | 22 | +0.323% | +0.089% | 50% |

**Por régimen de mercado (TRAIN+VAL)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 132 | -0.308% | -0.345% | 48% |
| bajista | 3 | -0.739% | -0.838% | 33% |
| lateral | 123 | +0.074% | -0.069% | 48% |

**Por régimen (TEST-final)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 30 | -0.264% | -0.311% | 33% |
| bajista | 1 | +0.482% | +0.060% | 100% |
| lateral | 14 | -0.367% | -0.506% | 14% |

**Por régimen (TEST-PRE)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 34 | +0.328% | +0.274% | 50% |
| bajista | 5 | -1.252% | -1.397% | 20% |
| lateral | 39 | -0.396% | -0.622% | 41% |

**Robustez de parámetros (8h)**

| N | n (dev) | exceso C | p | bruto medio | neto medio | TEST-final exceso C | TEST-final neto | TEST-PRE exceso C | TEST-PRE neto |
|---|---|---|---|---|---|---|---|---|---|
| 10 | 538 | -0.279% | 0.993 | -0.146% | -0.346% | -0.091% | -0.156% | +0.128% | +0.010% |
| 12 | 391 | -0.291% | 0.987 | -0.166% | -0.366% | -0.382% | -0.470% | +0.135% | +0.004% |
| 14 (primario) | 258 | -0.131% | 0.790 | -0.019% | -0.219% | -0.279% | -0.363% | -0.135% | -0.281% |
| 16 | 172 | -0.277% | 0.912 | -0.177% | -0.377% | +0.134% | +0.065% | -0.089% | -0.266% |
| 18 | 119 | -0.307% | 0.891 | -0.224% | -0.424% | +0.018% | -0.025% | -0.518% | -0.719% |

### B — Ruptura (N=24) → clase final **C**

**Oportunidades** (TRAIN+VAL): 1848 señales — 2.07/día en el universo, 0.103 por activo-día, 14.5/semana, 63/mes. TEST-final: 300 (1.89/día). TEST-PRE: 637 (1.76/día).

**Consistencia entre tramos (8h)**

| Tramo | n | bruto medio | neto medio | exceso C | p (C) | PF neto |
|---|---|---|---|---|---|---|
| TRAIN | 1551 | +0.256% | +0.056% | +0.128% | 0.028 | 1.06 |
| VAL | 297 | +0.039% | -0.161% | +0.084% | 0.238 | 0.82 |
| TEST-final | 300 | +0.048% | -0.152% | -0.105% | 0.807 | 0.84 |
| TEST-PRE | 637 | +0.318% | +0.118% | +0.256% | 0.002 | 1.14 |

**Retornos por horizonte — TRAIN+VAL**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 4h | 1848 | +0.179% | -0.034% | -0.021% | +0.018% | +0.142% | +0.160% | +0.037% | +0.129% | 0.001 |
| 8h | 1848 | +0.221% | +0.035% | +0.021% | +0.037% | +0.138% | +0.184% | +0.084% | +0.121% | 0.020 |
| 16h | 1848 | +0.319% | +0.000% | +0.119% | +0.073% | +0.253% | +0.246% | +0.066% | +0.119% | 0.077 |
| 24h | 1848 | +0.176% | -0.179% | -0.024% | +0.109% | +0.205% | +0.066% | -0.029% | -0.123% | 0.885 |
| 48h | 1848 | +0.314% | +0.000% | +0.114% | +0.209% | +0.319% | +0.106% | -0.005% | -0.254% | 0.963 |
| 72h | 1848 | +0.269% | -0.523% | +0.069% | +0.308% | +0.195% | -0.038% | +0.074% | -0.561% | 0.999 |

**Retornos por horizonte — TEST-final (contaminado)**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 4h | 300 | -0.021% | -0.166% | -0.221% | +0.031% | +0.010% | -0.052% | -0.031% | -0.100% | 0.875 |
| 8h | 300 | +0.048% | -0.166% | -0.152% | +0.061% | +0.030% | -0.014% | +0.017% | -0.105% | 0.807 |
| 16h | 300 | +0.065% | -0.271% | -0.135% | +0.118% | +0.164% | -0.053% | -0.099% | -0.231% | 0.907 |
| 24h | 300 | +0.215% | -0.326% | +0.015% | +0.180% | +0.236% | +0.034% | -0.021% | -0.230% | 0.854 |
| 48h | 300 | +1.785% | +0.446% | +1.585% | +0.392% | +1.354% | +1.393% | +0.431% | +0.883% | 0.003 |
| 72h | 300 | +2.171% | +0.521% | +1.971% | +0.606% | +1.641% | +1.565% | +0.530% | +0.844% | 0.018 |

**Retornos por horizonte — TEST-PRE (virgen)**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 4h | 637 | +0.280% | -0.037% | +0.080% | +0.000% | +0.206% | +0.280% | +0.074% | +0.250% | 0.000 |
| 8h | 637 | +0.318% | -0.066% | +0.118% | +0.001% | +0.197% | +0.317% | +0.121% | +0.256% | 0.002 |
| 16h | 637 | +0.408% | -0.222% | +0.208% | +0.003% | +0.318% | +0.405% | +0.090% | +0.285% | 0.010 |
| 24h | 637 | +0.299% | -0.324% | +0.099% | +0.009% | +0.147% | +0.290% | +0.151% | +0.108% | 0.235 |
| 48h | 637 | +0.435% | -0.268% | +0.235% | +0.024% | +0.353% | +0.410% | +0.081% | +0.051% | 0.401 |
| 72h | 637 | +0.699% | -0.348% | +0.499% | +0.043% | +0.524% | +0.656% | +0.175% | +0.123% | 0.309 |

**Riesgo a 8h — TRAIN+VAL**: win rate neto 45.6% (bruto 50.4%); PF neto 1.02 (bruto 1.26); media neta +0.021%, mediana neta -0.165%; pérdida media -1.75%, mediana -1.30%; p5/p10 -3.90%/-2.84%; peor operación -19.66%; pérdidas <-2%: 17.4%; ganancias netas (0,1%]: 19.9%; MAE medio/mediana/p5 -1.76%/-1.24%/-4.87%; MFE medio/mediana +2.18%/+1.35%; DD slots 10% neto/bruto -20.5%/-12.8%; días+ (con señal) 41%, días+ (calendario) 23%; peor día -14.92%; racha negativa máx. 5 días; semanas+ (con señal) 41%, semanas+ (calendario) 40%; peor semana -5.39%; racha perdedora máx. 20 operaciones seguidas

**Riesgo a 8h — TEST-final**: win rate neto 39.0% (bruto 46.7%); PF neto 0.84 (bruto 1.06); media neta -0.152%, mediana neta -0.366%; pérdida media -1.53%, mediana -1.23%; p5/p10 -3.32%/-2.59%; peor operación -5.99%; pérdidas <-2%: 17.7%; ganancias netas (0,1%]: 17.3%; MAE medio/mediana/p5 -1.62%/-1.22%/-4.02%; MFE medio/mediana +1.93%/+1.01%; DD slots 10% neto/bruto -6.2%/-3.7%; días+ (con señal) 33%, días+ (calendario) 21%; peor día -4.53%; racha negativa máx. 7 días; semanas+ (con señal) 30%, semanas+ (calendario) 30%; peor semana -2.39%; racha perdedora máx. 10 operaciones seguidas

**Riesgo a 8h — TEST-PRE**: win rate neto 41.3% (bruto 47.3%); PF neto 1.14 (bruto 1.44); media neta +0.118%, mediana neta -0.266%; pérdida media -1.41%, mediana -0.99%; p5/p10 -3.50%/-2.33%; peor operación -8.64%; pérdidas <-2%: 14.1%; ganancias netas (0,1%]: 20.1%; MAE medio/mediana/p5 -1.60%/-1.08%/-4.93%; MFE medio/mediana +2.19%/+1.20%; DD slots 10% neto/bruto -10.0%/-7.5%; días+ (con señal) 36%, días+ (calendario) 19%; peor día -8.64%; racha negativa máx. 4 días; semanas+ (con señal) 35%, semanas+ (calendario) 34%; peor semana -8.64%; racha perdedora máx. 16 operaciones seguidas

**Por activo (TRAIN+VAL)**

| Activo | n | exceso C 8h | neto medio 8h | win neto | señales/mes |
|---|---|---|---|---|---|
| AAVE | 90 | +0.773% | +0.689% | 52% | 3.06 |
| ADA | 86 | +0.287% | +0.194% | 51% | 2.93 |
| ATOM | 90 | +0.042% | -0.120% | 40% | 3.06 |
| AVAX | 95 | +0.078% | +0.010% | 47% | 3.23 |
| BCH | 87 | -0.166% | -0.247% | 41% | 2.96 |
| BNB | 102 | -0.064% | -0.191% | 40% | 3.47 |
| BTC | 102 | -0.015% | -0.147% | 43% | 3.47 |
| DOGE | 89 | -0.054% | -0.124% | 38% | 3.03 |
| DOT | 91 | +0.151% | +0.022% | 49% | 3.10 |
| ETC | 83 | +0.474% | +0.326% | 46% | 2.82 |
| ETH | 101 | -0.027% | -0.142% | 43% | 3.44 |
| IOTA | 80 | +0.294% | +0.190% | 51% | 2.72 |
| LINK | 99 | -0.259% | -0.361% | 39% | 3.37 |
| LTC | 88 | +0.289% | +0.156% | 52% | 2.99 |
| NEAR | 97 | +0.315% | +0.249% | 53% | 3.30 |
| SOL | 95 | +0.217% | +0.163% | 52% | 3.23 |
| TRX | 112 | -0.008% | -0.135% | 47% | 3.81 |
| UNI | 89 | +0.236% | +0.169% | 44% | 3.03 |
| XLM | 87 | -0.135% | -0.198% | 44% | 2.96 |
| XRP | 85 | +0.142% | +0.087% | 40% | 2.89 |

**Por trimestre (TRAIN+VAL)**

| Trimestre | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| 2023Q4 | 145 | +0.313% | +0.406% | 49% |
| 2024Q1 | 212 | +0.039% | +0.070% | 48% |
| 2024Q2 | 152 | +0.522% | +0.284% | 56% |
| 2024Q3 | 204 | -0.244% | -0.406% | 39% |
| 2024Q4 | 198 | +0.482% | +0.655% | 54% |
| 2025Q1 | 165 | +0.496% | +0.270% | 45% |
| 2025Q2 | 185 | -0.262% | -0.392% | 40% |
| 2025Q3 | 207 | -0.060% | -0.128% | 45% |
| 2025Q4 | 156 | -0.102% | -0.364% | 43% |
| 2026Q1 | 169 | +0.356% | +0.085% | 46% |
| 2026Q2 | 55 | -0.370% | -0.501% | 25% |

**Por régimen de mercado (TRAIN+VAL)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 601 | +0.101% | +0.187% | 49% |
| bajista | 196 | +0.727% | +0.480% | 44% |
| lateral | 1051 | +0.019% | -0.159% | 44% |

**Por régimen (TEST-final)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 128 | -0.533% | -0.507% | 37% |
| bajista | 41 | +0.146% | -0.202% | 24% |
| lateral | 131 | +0.233% | +0.209% | 46% |

**Por régimen (TEST-PRE)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 222 | +0.485% | +0.406% | 45% |
| bajista | 56 | -0.207% | -0.504% | 36% |
| lateral | 359 | +0.187% | +0.036% | 40% |

**Robustez de parámetros (8h)**

| N | n (dev) | exceso C | p | bruto medio | neto medio | TEST-final exceso C | TEST-final neto | TEST-PRE exceso C | TEST-PRE neto |
|---|---|---|---|---|---|---|---|---|---|
| 18 | 2157 | +0.127% | 0.010 | +0.212% | +0.012% | -0.019% | -0.089% | +0.099% | -0.057% |
| 21 | 1992 | +0.078% | 0.084 | +0.172% | -0.028% | -0.090% | -0.150% | +0.108% | -0.043% |
| 24 (primario) | 1848 | +0.121% | 0.020 | +0.221% | +0.021% | -0.105% | -0.152% | +0.256% | +0.118% |
| 27 | 1738 | +0.050% | 0.208 | +0.153% | -0.047% | -0.039% | -0.076% | +0.324% | +0.191% |
| 30 | 1621 | +0.088% | 0.082 | +0.201% | +0.001% | -0.018% | -0.048% | +0.408% | +0.282% |

### C — Expansión de volatilidad (N=24) → clase final **C**

**Oportunidades** (TRAIN+VAL): 1853 señales — 2.07/día en el universo, 0.104 por activo-día, 14.5/semana, 63/mes. TEST-final: 335 (2.11/día). TEST-PRE: 833 (2.30/día).

**Consistencia entre tramos (8h)**

| Tramo | n | bruto medio | neto medio | exceso C | p (C) | PF neto |
|---|---|---|---|---|---|---|
| TRAIN | 1508 | +0.249% | +0.049% | +0.149% | 0.014 | 1.04 |
| VAL | 345 | -0.092% | -0.292% | -0.030% | 0.605 | 0.72 |
| TEST-final | 335 | -0.084% | -0.284% | -0.195% | 0.961 | 0.70 |
| TEST-PRE | 833 | +0.597% | +0.397% | +0.564% | 0.000 | 1.62 |

**Retornos por horizonte — TRAIN+VAL**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 4h | 1853 | +0.110% | +0.000% | -0.090% | +0.018% | +0.105% | +0.091% | +0.005% | +0.075% | 0.037 |
| 8h | 1853 | +0.185% | +0.034% | -0.015% | +0.037% | +0.180% | +0.148% | +0.006% | +0.115% | 0.026 |
| 16h | 1853 | +0.471% | +0.081% | +0.271% | +0.073% | +0.490% | +0.398% | -0.019% | +0.333% | 0.000 |
| 24h | 1853 | +0.321% | -0.029% | +0.121% | +0.109% | +0.381% | +0.211% | -0.060% | +0.115% | 0.131 |
| 48h | 1853 | +1.119% | +0.454% | +0.919% | +0.209% | +1.026% | +0.911% | +0.094% | +0.726% | 0.000 |
| 72h | 1853 | +1.321% | +0.274% | +1.121% | +0.308% | +1.245% | +1.014% | +0.077% | +0.743% | 0.000 |

**Retornos por horizonte — TEST-final (contaminado)**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 4h | 335 | -0.059% | -0.282% | -0.259% | +0.031% | -0.110% | -0.089% | +0.052% | -0.115% | 0.927 |
| 8h | 335 | -0.084% | -0.275% | -0.284% | +0.061% | -0.170% | -0.145% | +0.086% | -0.195% | 0.961 |
| 16h | 335 | +0.680% | -0.060% | +0.480% | +0.118% | +0.316% | +0.562% | +0.364% | +0.468% | 0.002 |
| 24h | 335 | +0.728% | +0.196% | +0.528% | +0.180% | +0.377% | +0.548% | +0.351% | +0.407% | 0.020 |
| 48h | 335 | +1.296% | +0.211% | +1.096% | +0.392% | +0.818% | +0.903% | +0.478% | +0.640% | 0.014 |
| 72h | 335 | +1.700% | -0.171% | +1.500% | +0.606% | +1.087% | +1.094% | +0.613% | +0.741% | 0.021 |

**Retornos por horizonte — TEST-PRE (virgen)**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 4h | 833 | +0.469% | +0.150% | +0.269% | +0.000% | +0.405% | +0.468% | +0.063% | +0.453% | 0.000 |
| 8h | 833 | +0.597% | +0.315% | +0.397% | +0.001% | +0.540% | +0.596% | +0.057% | +0.564% | 0.000 |
| 16h | 833 | +0.709% | +0.280% | +0.509% | +0.003% | +0.727% | +0.706% | -0.018% | +0.643% | 0.000 |
| 24h | 833 | +0.733% | +0.407% | +0.533% | +0.009% | +0.713% | +0.724% | +0.019% | +0.629% | 0.000 |
| 48h | 833 | +0.829% | +0.505% | +0.629% | +0.024% | +0.805% | +0.805% | +0.024% | +0.615% | 0.000 |
| 72h | 833 | +0.873% | +0.534% | +0.673% | +0.043% | +0.932% | +0.829% | -0.060% | +0.545% | 0.005 |

**Riesgo a 8h — TRAIN+VAL**: win rate neto 46.6% (bruto 50.3%); PF neto 0.99 (bruto 1.19); media neta -0.015%, mediana neta -0.166%; pérdida media -2.07%, mediana -1.55%; p5/p10 -4.98%/-3.13%; peor operación -19.66%; pérdidas <-2%: 20.8%; ganancias netas (0,1%]: 17.4%; MAE medio/mediana/p5 -2.22%/-1.53%/-6.56%; MFE medio/mediana +2.39%/+1.58%; DD slots 10% neto/bruto -25.4%/-19.0%; días+ (con señal) 45%, días+ (calendario) 26%; peor día -10.22%; racha negativa máx. 5 días; semanas+ (con señal) 48%, semanas+ (calendario) 47%; peor semana -5.16%; racha perdedora máx. 20 operaciones seguidas

**Riesgo a 8h — TEST-final**: win rate neto 33.7% (bruto 40.0%); PF neto 0.70 (bruto 0.90); media neta -0.284%, mediana neta -0.475%; pérdida media -1.42%, mediana -1.10%; p5/p10 -3.38%/-2.53%; peor operación -10.50%; pérdidas <-2%: 16.1%; ganancias netas (0,1%]: 18.5%; MAE medio/mediana/p5 -1.69%/-1.24%/-4.15%; MFE medio/mediana +1.79%/+0.94%; DD slots 10% neto/bruto -10.5%/-5.7%; días+ (con señal) 36%, días+ (calendario) 22%; peor día -10.50%; racha negativa máx. 4 días; semanas+ (con señal) 39%, semanas+ (calendario) 39%; peor semana -2.27%; racha perdedora máx. 21 operaciones seguidas

**Riesgo a 8h — TEST-PRE**: win rate neto 54.4% (bruto 58.7%); PF neto 1.62 (bruto 2.07); media neta +0.397%, mediana neta +0.115%; pérdida media -1.41%, mediana -0.96%; p5/p10 -3.07%/-2.16%; peor operación -9.21%; pérdidas <-2%: 10.9%; ganancias netas (0,1%]: 27.1%; MAE medio/mediana/p5 -1.39%/-0.83%/-4.48%; MFE medio/mediana +2.25%/+1.35%; DD slots 10% neto/bruto -4.9%/-3.5%; días+ (con señal) 46%, días+ (calendario) 28%; peor día -8.07%; racha negativa máx. 4 días; semanas+ (con señal) 60%, semanas+ (calendario) 58%; peor semana -2.81%; racha perdedora máx. 15 operaciones seguidas

**Por activo (TRAIN+VAL)**

| Activo | n | exceso C 8h | neto medio 8h | win neto | señales/mes |
|---|---|---|---|---|---|
| AAVE | 84 | +0.195% | +0.060% | 46% | 2.86 |
| ADA | 94 | +0.536% | +0.407% | 46% | 3.20 |
| ATOM | 81 | -0.460% | -0.662% | 48% | 2.76 |
| AVAX | 85 | +0.397% | +0.260% | 48% | 2.89 |
| BCH | 116 | -0.078% | -0.182% | 46% | 3.95 |
| BNB | 102 | +0.401% | +0.268% | 52% | 3.47 |
| BTC | 109 | +0.046% | -0.106% | 42% | 3.71 |
| DOGE | 95 | +0.121% | +0.018% | 45% | 3.23 |
| DOT | 78 | +0.300% | +0.121% | 47% | 2.65 |
| ETC | 94 | -0.060% | -0.216% | 46% | 3.20 |
| ETH | 98 | +0.109% | -0.042% | 52% | 3.33 |
| IOTA | 84 | +0.114% | +0.005% | 46% | 2.86 |
| LINK | 93 | +0.129% | -0.030% | 52% | 3.16 |
| LTC | 89 | +0.078% | -0.072% | 46% | 3.03 |
| NEAR | 91 | -0.461% | -0.593% | 47% | 3.10 |
| SOL | 88 | -0.154% | -0.259% | 44% | 2.99 |
| TRX | 88 | -0.062% | -0.185% | 48% | 2.99 |
| UNI | 90 | -0.176% | -0.238% | 40% | 3.06 |
| XLM | 90 | +1.034% | +0.911% | 48% | 3.06 |
| XRP | 104 | +0.277% | +0.201% | 43% | 3.54 |

**Por trimestre (TRAIN+VAL)**

| Trimestre | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| 2023Q4 | 111 | +0.241% | +0.301% | 45% |
| 2024Q1 | 199 | +0.064% | +0.092% | 57% |
| 2024Q2 | 205 | -0.257% | -0.505% | 47% |
| 2024Q3 | 208 | -0.087% | -0.255% | 35% |
| 2024Q4 | 190 | +0.799% | +0.916% | 55% |
| 2025Q1 | 148 | +1.445% | +1.175% | 60% |
| 2025Q2 | 185 | -0.474% | -0.609% | 45% |
| 2025Q3 | 173 | +0.284% | +0.206% | 45% |
| 2025Q4 | 185 | -0.797% | -1.063% | 36% |
| 2026Q1 | 196 | +0.494% | +0.216% | 51% |
| 2026Q2 | 53 | -0.595% | -0.735% | 23% |

**Por régimen de mercado (TRAIN+VAL)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 500 | -0.073% | +0.003% | 46% |
| bajista | 276 | +1.218% | +0.959% | 58% |
| lateral | 1077 | -0.080% | -0.272% | 44% |

**Por régimen (TEST-final)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 129 | -0.639% | -0.620% | 28% |
| bajista | 56 | -0.390% | -0.750% | 25% |
| lateral | 150 | +0.260% | +0.178% | 42% |

**Por régimen (TEST-PRE)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 225 | +1.048% | +0.985% | 54% |
| bajista | 132 | +0.270% | -0.024% | 60% |
| lateral | 476 | +0.417% | +0.235% | 53% |

**Robustez de parámetros (8h)**

| N | n (dev) | exceso C | p | bruto medio | neto medio | TEST-final exceso C | TEST-final neto | TEST-PRE exceso C | TEST-PRE neto |
|---|---|---|---|---|---|---|---|---|---|
| 18 | 1959 | -0.013% | 0.588 | +0.057% | -0.143% | -0.200% | -0.296% | +0.550% | +0.380% |
| 21 | 1909 | +0.095% | 0.050 | +0.164% | -0.036% | -0.200% | -0.289% | +0.524% | +0.355% |
| 24 (primario) | 1853 | +0.115% | 0.026 | +0.185% | -0.015% | -0.195% | -0.284% | +0.564% | +0.397% |
| 27 | 1847 | +0.128% | 0.016 | +0.197% | -0.003% | -0.145% | -0.225% | +0.542% | +0.374% |
| 30 | 1830 | +0.087% | 0.073 | +0.160% | -0.040% | -0.126% | -0.207% | +0.538% | +0.370% |

### D — Momentum cross-sectional (N=24) → clase final **C**

**Oportunidades** (TRAIN+VAL): 1341 señales — 1.50/día en el universo, 0.075 por activo-día, 10.5/semana, 46/mes. TEST-final: 228 (1.43/día). TEST-PRE: 543 (1.50/día).

**Consistencia entre tramos (8h)**

| Tramo | n | bruto medio | neto medio | exceso C | p (C) | PF neto |
|---|---|---|---|---|---|---|
| TRAIN | 1096 | +0.142% | -0.058% | +0.017% | 0.421 | 0.94 |
| VAL | 245 | +0.012% | -0.188% | +0.057% | 0.337 | 0.81 |
| TEST-final | 228 | +0.056% | -0.144% | -0.096% | 0.741 | 0.85 |
| TEST-PRE | 543 | +0.207% | +0.007% | +0.162% | 0.049 | 1.01 |

**Retornos por horizonte — TRAIN+VAL**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 4h | 1341 | +0.091% | -0.070% | -0.109% | +0.018% | +0.030% | +0.072% | +0.061% | +0.043% | 0.200 |
| 8h | 1341 | +0.119% | -0.054% | -0.081% | +0.037% | +0.034% | +0.082% | +0.084% | +0.024% | 0.371 |
| 16h | 1341 | +0.237% | +0.048% | +0.037% | +0.073% | +0.120% | +0.164% | +0.117% | +0.049% | 0.317 |
| 24h | 1341 | +0.085% | -0.179% | -0.115% | +0.109% | +0.072% | -0.025% | +0.013% | -0.196% | 0.939 |
| 48h | 1341 | -0.009% | -0.241% | -0.209% | +0.209% | +0.095% | -0.218% | -0.104% | -0.548% | 0.999 |
| 72h | 1341 | +0.058% | -0.623% | -0.142% | +0.308% | +0.120% | -0.250% | -0.062% | -0.731% | 1.000 |

**Retornos por horizonte — TEST-final (contaminado)**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 4h | 228 | +0.051% | -0.057% | -0.149% | +0.031% | +0.015% | +0.021% | +0.037% | -0.025% | 0.593 |
| 8h | 228 | +0.056% | +0.007% | -0.144% | +0.061% | -0.014% | -0.005% | +0.070% | -0.096% | 0.741 |
| 16h | 228 | +0.476% | +0.000% | +0.276% | +0.118% | +0.148% | +0.358% | +0.329% | +0.177% | 0.203 |
| 24h | 228 | +0.312% | -0.127% | +0.112% | +0.180% | +0.150% | +0.131% | +0.162% | -0.138% | 0.696 |
| 48h | 228 | +0.680% | -0.067% | +0.480% | +0.392% | +0.235% | +0.288% | +0.444% | -0.241% | 0.729 |
| 72h | 228 | +1.438% | -0.258% | +1.238% | +0.606% | +0.828% | +0.832% | +0.610% | +0.035% | 0.472 |

**Retornos por horizonte — TEST-PRE (virgen)**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 4h | 543 | +0.164% | -0.048% | -0.036% | +0.000% | +0.023% | +0.164% | +0.142% | +0.142% | 0.020 |
| 8h | 543 | +0.207% | +0.031% | +0.007% | +0.001% | +0.035% | +0.206% | +0.172% | +0.162% | 0.049 |
| 16h | 543 | +0.043% | -0.114% | -0.157% | +0.003% | -0.090% | +0.040% | +0.133% | -0.048% | 0.636 |
| 24h | 543 | +0.045% | -0.158% | -0.155% | +0.009% | -0.018% | +0.036% | +0.063% | -0.098% | 0.717 |
| 48h | 543 | +0.323% | -0.227% | +0.123% | +0.024% | +0.143% | +0.298% | +0.180% | +0.032% | 0.446 |
| 72h | 543 | +0.347% | -0.332% | +0.147% | +0.043% | +0.113% | +0.304% | +0.234% | -0.088% | 0.622 |

**Riesgo a 8h — TRAIN+VAL**: win rate neto 43.5% (bruto 48.5%); PF neto 0.92 (bruto 1.13); media neta -0.081%, mediana neta -0.254%; pérdida media -1.84%, mediana -1.31%; p5/p10 -4.27%/-3.06%; peor operación -12.12%; pérdidas <-2%: 19.2%; ganancias netas (0,1%]: 18.3%; MAE medio/mediana/p5 -1.92%/-1.31%/-5.54%; MFE medio/mediana +2.21%/+1.31%; DD slots 10% neto/bruto -16.0%/-9.0%; días+ (con señal) 42%, días+ (calendario) 32%; peor día -12.12%; racha negativa máx. 8 días; semanas+ (con señal) 44%, semanas+ (calendario) 44%; peor semana -2.19%; racha perdedora máx. 12 operaciones seguidas

**Riesgo a 8h — TEST-final**: win rate neto 44.7% (bruto 50.0%); PF neto 0.85 (bruto 1.06); media neta -0.144%, mediana neta -0.193%; pérdida media -1.78%, mediana -1.29%; p5/p10 -3.92%/-2.87%; peor operación -10.48%; pérdidas <-2%: 19.3%; ganancias netas (0,1%]: 21.5%; MAE medio/mediana/p5 -1.93%/-1.39%/-5.45%; MFE medio/mediana +1.98%/+1.20%; DD slots 10% neto/bruto -4.5%/-2.4%; días+ (con señal) 48%, días+ (calendario) 36%; peor día -6.87%; racha negativa máx. 4 días; semanas+ (con señal) 39%, semanas+ (calendario) 39%; peor semana -1.20%; racha perdedora máx. 7 operaciones seguidas

**Riesgo a 8h — TEST-PRE**: win rate neto 45.3% (bruto 50.8%); PF neto 1.01 (bruto 1.30); media neta +0.007%, mediana neta -0.169%; pérdida media -1.46%, mediana -1.13%; p5/p10 -3.35%/-2.42%; peor operación -9.19%; pérdidas <-2%: 13.8%; ganancias netas (0,1%]: 25.4%; MAE medio/mediana/p5 -1.62%/-1.12%/-4.76%; MFE medio/mediana +1.99%/+1.08%; DD slots 10% neto/bruto -8.8%/-5.6%; días+ (con señal) 46%, días+ (calendario) 34%; peor día -5.83%; racha negativa máx. 7 días; semanas+ (con señal) 43%, semanas+ (calendario) 43%; peor semana -1.82%; racha perdedora máx. 12 operaciones seguidas

**Por activo (TRAIN+VAL)**

| Activo | n | exceso C 8h | neto medio 8h | win neto | señales/mes |
|---|---|---|---|---|---|
| AAVE | 94 | -0.292% | -0.425% | 45% | 3.20 |
| ADA | 54 | +0.575% | +0.531% | 50% | 1.84 |
| ATOM | 58 | -0.856% | -1.029% | 31% | 1.97 |
| AVAX | 67 | +0.099% | -0.022% | 45% | 2.28 |
| BCH | 79 | -0.194% | -0.310% | 43% | 2.69 |
| BNB | 72 | -0.050% | -0.168% | 40% | 2.45 |
| BTC | 61 | -0.112% | -0.246% | 33% | 2.08 |
| DOGE | 61 | +0.524% | +0.472% | 44% | 2.08 |
| DOT | 49 | -0.095% | -0.221% | 33% | 1.67 |
| ETC | 40 | +0.218% | +0.064% | 42% | 1.36 |
| ETH | 66 | +0.313% | +0.187% | 44% | 2.25 |
| IOTA | 54 | +0.084% | -0.010% | 52% | 1.84 |
| LINK | 69 | -0.084% | -0.213% | 48% | 2.35 |
| LTC | 66 | +0.228% | +0.070% | 41% | 2.25 |
| NEAR | 90 | -0.021% | -0.114% | 46% | 3.06 |
| SOL | 80 | -0.292% | -0.386% | 46% | 2.72 |
| TRX | 97 | +0.108% | -0.019% | 42% | 3.30 |
| UNI | 79 | -0.110% | -0.169% | 47% | 2.69 |
| XLM | 42 | +0.395% | +0.408% | 45% | 1.43 |
| XRP | 63 | +0.537% | +0.503% | 51% | 2.14 |

**Por trimestre (TRAIN+VAL)**

| Trimestre | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| 2023Q4 | 75 | -0.411% | -0.258% | 37% |
| 2024Q1 | 146 | +0.103% | +0.152% | 51% |
| 2024Q2 | 131 | -0.063% | -0.326% | 40% |
| 2024Q3 | 128 | +0.079% | -0.063% | 47% |
| 2024Q4 | 134 | +0.145% | +0.302% | 46% |
| 2025Q1 | 135 | +0.493% | +0.240% | 44% |
| 2025Q2 | 157 | -0.524% | -0.632% | 36% |
| 2025Q3 | 131 | +0.313% | +0.266% | 50% |
| 2025Q4 | 132 | -0.047% | -0.283% | 40% |
| 2026Q1 | 143 | -0.053% | -0.313% | 39% |
| 2026Q2 | 29 | +0.524% | +0.422% | 55% |

**Por régimen de mercado (TRAIN+VAL)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 373 | -0.085% | +0.004% | 43% |
| bajista | 180 | +0.435% | +0.191% | 43% |
| lateral | 788 | -0.019% | -0.184% | 44% |

**Por régimen (TEST-final)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 77 | -0.496% | -0.386% | 39% |
| bajista | 42 | +0.513% | +0.114% | 52% |
| lateral | 109 | -0.050% | -0.073% | 46% |

**Por régimen (TEST-PRE)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 156 | +0.729% | +0.683% | 53% |
| bajista | 89 | -0.115% | -0.406% | 43% |
| lateral | 298 | -0.052% | -0.223% | 42% |

**Robustez de parámetros (8h)**

| N | n (dev) | exceso C | p | bruto medio | neto medio | TEST-final exceso C | TEST-final neto | TEST-PRE exceso C | TEST-PRE neto |
|---|---|---|---|---|---|---|---|---|---|
| 18 | 1578 | -0.020% | 0.620 | +0.070% | -0.130% | +0.011% | -0.062% | +0.032% | -0.132% |
| 21 | 1432 | +0.018% | 0.398 | +0.112% | -0.088% | +0.181% | +0.113% | +0.153% | -0.005% |
| 24 (primario) | 1341 | +0.024% | 0.371 | +0.119% | -0.081% | -0.096% | -0.144% | +0.162% | +0.007% |
| 27 | 1290 | +0.076% | 0.151 | +0.168% | -0.032% | -0.229% | -0.280% | +0.165% | +0.012% |
| 30 | 1228 | -0.045% | 0.723 | +0.053% | -0.147% | -0.061% | -0.113% | +0.243% | +0.096% |

### E — Régimen + entrada (N=200) → clase final **C**

**Oportunidades** (TRAIN+VAL): 1351 señales — 1.51/día en el universo, 0.076 por activo-día, 10.6/semana, 46/mes. TEST-final: 291 (1.83/día). TEST-PRE: 456 (1.26/día).

**Consistencia entre tramos (8h)**

| Tramo | n | bruto medio | neto medio | exceso C | p (C) | PF neto |
|---|---|---|---|---|---|---|
| TRAIN | 1165 | -0.001% | -0.201% | -0.119% | 0.946 | 0.81 |
| VAL | 186 | +0.337% | +0.137% | +0.382% | 0.003 | 1.15 |
| TEST-final | 291 | -0.296% | -0.496% | -0.425% | 1.000 | 0.50 |
| TEST-PRE | 456 | +0.400% | +0.200% | +0.356% | 0.000 | 1.29 |

**Retornos por horizonte — TRAIN+VAL**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 4h | 1351 | +0.071% | -0.098% | -0.129% | +0.018% | +0.075% | +0.053% | -0.004% | +0.023% | 0.314 |
| 8h | 1351 | +0.045% | -0.194% | -0.155% | +0.037% | +0.031% | +0.008% | +0.015% | -0.050% | 0.774 |
| 16h | 1351 | +0.164% | -0.118% | -0.036% | +0.073% | +0.222% | +0.091% | -0.057% | -0.026% | 0.609 |
| 24h | 1351 | +0.033% | -0.352% | -0.167% | +0.109% | +0.151% | -0.076% | -0.118% | -0.254% | 0.985 |
| 48h | 1351 | +0.434% | -0.216% | +0.234% | +0.209% | +0.539% | +0.225% | -0.105% | -0.125% | 0.777 |
| 72h | 1351 | +0.563% | -0.206% | +0.363% | +0.308% | +0.602% | +0.256% | -0.038% | -0.256% | 0.898 |

**Retornos por horizonte — TEST-final (contaminado)**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 4h | 291 | -0.193% | -0.141% | -0.393% | +0.031% | -0.178% | -0.223% | -0.015% | -0.259% | 0.999 |
| 8h | 291 | -0.296% | -0.270% | -0.496% | +0.061% | -0.220% | -0.357% | -0.076% | -0.425% | 1.000 |
| 16h | 291 | -0.152% | -0.247% | -0.352% | +0.118% | -0.068% | -0.271% | -0.084% | -0.398% | 0.994 |
| 24h | 291 | +0.210% | -0.145% | +0.010% | +0.180% | +0.275% | +0.030% | -0.065% | -0.157% | 0.784 |
| 48h | 291 | +0.741% | -0.083% | +0.541% | +0.392% | +0.775% | +0.349% | -0.034% | +0.011% | 0.485 |
| 72h | 291 | +1.386% | +0.000% | +1.186% | +0.606% | +1.268% | +0.779% | +0.118% | +0.349% | 0.168 |

**Retornos por horizonte — TEST-PRE (virgen)**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 4h | 456 | +0.393% | +0.136% | +0.193% | +0.000% | +0.289% | +0.393% | +0.104% | +0.373% | 0.000 |
| 8h | 456 | +0.400% | +0.124% | +0.200% | +0.001% | +0.335% | +0.399% | +0.065% | +0.356% | 0.000 |
| 16h | 456 | +0.826% | +0.272% | +0.626% | +0.003% | +0.631% | +0.823% | +0.195% | +0.736% | 0.000 |
| 24h | 456 | +0.654% | +0.144% | +0.454% | +0.009% | +0.507% | +0.645% | +0.147% | +0.511% | 0.002 |
| 48h | 456 | +0.911% | +0.303% | +0.711% | +0.024% | +0.791% | +0.887% | +0.120% | +0.627% | 0.004 |
| 72h | 456 | +1.045% | -0.076% | +0.845% | +0.043% | +0.971% | +1.001% | +0.073% | +0.631% | 0.014 |

**Riesgo a 8h — TRAIN+VAL**: win rate neto 41.8% (bruto 45.5%); PF neto 0.85 (bruto 1.05); media neta -0.155%, mediana neta -0.394%; pérdida media -1.80%, mediana -1.39%; p5/p10 -4.00%/-2.81%; peor operación -11.69%; pérdidas <-2%: 19.2%; ganancias netas (0,1%]: 16.3%; MAE medio/mediana/p5 -1.92%/-1.41%/-5.55%; MFE medio/mediana +1.99%/+1.22%; DD slots 10% neto/bruto -37.0%/-29.4%; días+ (con señal) 37%, días+ (calendario) 12%; peor día -9.55%; racha negativa máx. 5 días; semanas+ (con señal) 39%, semanas+ (calendario) 24%; peor semana -5.04%; racha perdedora máx. 20 operaciones seguidas

**Riesgo a 8h — TEST-final**: win rate neto 32.6% (bruto 38.8%); PF neto 0.50 (bruto 0.65); media neta -0.496%, mediana neta -0.470%; pérdida media -1.46%, mediana -1.10%; p5/p10 -3.64%/-2.76%; peor operación -8.45%; pérdidas <-2%: 17.9%; ganancias netas (0,1%]: 17.5%; MAE medio/mediana/p5 -1.52%/-1.09%/-4.41%; MFE medio/mediana +1.37%/+0.88%; DD slots 10% neto/bruto -15.6%/-11.3%; días+ (con señal) 30%, días+ (calendario) 12%; peor día -3.73%; racha negativa máx. 5 días; semanas+ (con señal) 18%, semanas+ (calendario) 13%; peor semana -1.79%; racha perdedora máx. 27 operaciones seguidas

**Riesgo a 8h — TEST-PRE**: win rate neto 48.0% (bruto 54.2%); PF neto 1.29 (bruto 1.67); media neta +0.200%, mediana neta -0.076%; pérdida media -1.34%, mediana -0.96%; p5/p10 -2.91%/-2.20%; peor operación -13.75%; pérdidas <-2%: 11.6%; ganancias netas (0,1%]: 22.4%; MAE medio/mediana/p5 -1.35%/-0.90%/-3.87%; MFE medio/mediana +2.15%/+1.30%; DD slots 10% neto/bruto -5.0%/-3.9%; días+ (con señal) 40%, días+ (calendario) 10%; peor día -13.75%; racha negativa máx. 4 días; semanas+ (con señal) 44%, semanas+ (calendario) 26%; peor semana -3.29%; racha perdedora máx. 17 operaciones seguidas

**Por activo (TRAIN+VAL)**

| Activo | n | exceso C 8h | neto medio 8h | win neto | señales/mes |
|---|---|---|---|---|---|
| AAVE | 70 | -0.673% | -0.776% | 34% | 2.38 |
| ADA | 71 | -0.201% | -0.312% | 35% | 2.42 |
| ATOM | 66 | +0.102% | -0.075% | 47% | 2.25 |
| AVAX | 66 | -0.704% | -0.790% | 39% | 2.25 |
| BCH | 74 | -0.081% | -0.182% | 36% | 2.52 |
| BNB | 70 | +0.158% | +0.050% | 43% | 2.38 |
| BTC | 72 | +0.044% | -0.063% | 42% | 2.45 |
| DOGE | 71 | +0.320% | +0.266% | 48% | 2.42 |
| DOT | 67 | -0.048% | -0.195% | 40% | 2.28 |
| ETC | 63 | -0.030% | -0.174% | 43% | 2.14 |
| ETH | 69 | -0.030% | -0.135% | 43% | 2.35 |
| IOTA | 62 | +0.709% | +0.610% | 47% | 2.11 |
| LINK | 73 | -0.254% | -0.375% | 37% | 2.48 |
| LTC | 61 | +0.025% | -0.094% | 41% | 2.08 |
| NEAR | 68 | -0.351% | -0.488% | 43% | 2.31 |
| SOL | 71 | +0.160% | +0.098% | 54% | 2.42 |
| TRX | 59 | -0.010% | -0.125% | 53% | 2.01 |
| UNI | 63 | -0.609% | -0.709% | 37% | 2.14 |
| XLM | 66 | +0.380% | +0.341% | 38% | 2.25 |
| XRP | 69 | +0.130% | +0.063% | 39% | 2.35 |

**Por trimestre (TRAIN+VAL)**

| Trimestre | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| 2023Q4 | 66 | -0.246% | -0.195% | 36% |
| 2024Q1 | 136 | +0.334% | +0.389% | 51% |
| 2024Q2 | 121 | +0.286% | +0.068% | 51% |
| 2024Q3 | 114 | -0.214% | -0.344% | 35% |
| 2024Q4 | 220 | +0.763% | +0.778% | 55% |
| 2025Q1 | 64 | -1.962% | -2.132% | 17% |
| 2025Q2 | 246 | -0.980% | -1.120% | 24% |
| 2025Q3 | 162 | +0.083% | +0.013% | 46% |
| 2025Q4 | 47 | -0.481% | -0.787% | 32% |
| 2026Q1 | 128 | +0.391% | +0.104% | 47% |
| 2026Q2 | 47 | +1.077% | +0.943% | 62% |

**Por régimen de mercado (TRAIN+VAL)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 522 | -0.202% | -0.210% | 39% |
| lateral | 829 | +0.045% | -0.120% | 43% |

**Por régimen (TEST-final)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 154 | -0.540% | -0.587% | 32% |
| lateral | 137 | -0.297% | -0.393% | 33% |

**Por régimen (TEST-PRE)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 167 | +0.815% | +0.683% | 50% |
| lateral | 289 | +0.090% | -0.079% | 47% |

**Robustez de parámetros (8h)**

| N | n (dev) | exceso C | p | bruto medio | neto medio | TEST-final exceso C | TEST-final neto | TEST-PRE exceso C | TEST-PRE neto |
|---|---|---|---|---|---|---|---|---|---|
| 160 | 1335 | -0.074% | 0.865 | +0.022% | -0.178% | -0.601% | -0.689% | +0.382% | +0.214% |
| 180 | 1341 | -0.033% | 0.691 | +0.062% | -0.138% | -0.494% | -0.568% | +0.385% | +0.217% |
| 200 (primario) | 1351 | -0.050% | 0.774 | +0.045% | -0.155% | -0.425% | -0.496% | +0.356% | +0.200% |
| 220 | 1358 | -0.095% | 0.922 | -0.001% | -0.201% | -0.411% | -0.476% | +0.380% | +0.221% |
| 240 | 1349 | -0.091% | 0.915 | +0.004% | -0.196% | -0.397% | -0.457% | +0.408% | +0.262% |

### Referencia adicional: familia E con solo el régimen (sin entrada), 8h

TRAIN+VAL: bruto +0.018%, exceso C -0.079% (p=0.954). TEST-final: bruto +0.028%, exceso C -0.089%. TEST-PRE: bruto +0.004%, exceso C -0.020%.

## 6. Comparación 1h vs 4h (parámetro primario, horizonte 8h = misma duración)

Desarrollo = TRAIN+VAL. Las filas de 1h salen de `resultados_dev.json` y `resultados_test.json` (experimento previo, sin tocar).

| Familia | TF | n dev | señales/día | bruto medio | exceso C | neto medio | win neto | PF neto | p5 neto | peor op. | DD bruto | trim.+ (G4) | TEST exceso C | TEST neto | clase |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| A | 1h | 1062 | 1.18 | +0.127% | +0.027% | -0.073% | 45.7% | 0.91 | -3.56% | -8.18% | -8.8% | 55% (11) | +0.175% | +0.096% | C |
| A | 4h | 258 | 0.29 | -0.019% | -0.131% | -0.219% | 47.7% | 0.80 | -4.68% | -12.67% | -9.3% | 50% (8) | -0.279% | -0.363% | C |
| B | 1h | 6778 | 7.53 | +0.089% | +0.029% | -0.111% | 44.8% | 0.88 | -4.09% | -16.50% | -51.4% | 73% (11) | +0.031% | -0.072% | C |
| B | 4h | 1848 | 2.07 | +0.221% | +0.121% | +0.021% | 45.6% | 1.02 | -3.90% | -19.66% | -12.8% | 55% (11) | -0.105% | -0.152% | C |
| C | 1h | 6795 | 7.55 | +0.157% | +0.115% | -0.043% | 47.8% | 0.96 | -4.52% | -19.66% | -23.7% | 73% (11) | -0.171% | -0.299% | E |
| C | 4h | 1853 | 2.07 | +0.185% | +0.115% | -0.015% | 46.6% | 0.99 | -4.98% | -19.66% | -19.0% | 55% (11) | -0.195% | -0.284% | C |
| D | 1h | 4967 | 5.52 | +0.009% | -0.062% | -0.191% | 43.4% | 0.82 | -4.21% | -31.59% | -41.5% | 36% (11) | -0.145% | -0.244% | C |
| D | 4h | 1341 | 1.50 | +0.119% | +0.024% | -0.081% | 43.5% | 0.92 | -4.27% | -12.12% | -9.0% | 55% (11) | -0.096% | -0.144% | C |
| E | 1h | 4461 | 4.95 | +0.078% | +0.019% | -0.122% | 44.0% | 0.87 | -3.88% | -14.50% | -33.0% | 64% (11) | -0.159% | -0.268% | C |
| E | 4h | 1351 | 1.51 | +0.045% | -0.050% | -0.155% | 41.8% | 0.85 | -4.00% | -11.69% | -29.4% | 55% (11) | -0.425% | -0.496% | C |

### Exceso C vs aleatoria por horizonte (TRAIN+VAL), 1h vs 4h

| Familia | TF | 4h | 8h | 16h | 24h | 48h | 72h |
|---|---|---|---|---|---|---|---|
| A | 1h | +0.066% | +0.027% | -0.185% | -0.056% | — | — |
| A | 4h | -0.335% | -0.131% | +0.106% | -0.311% | -0.147% | +0.127% |
| B | 1h | +0.027% | +0.029% | -0.008% | -0.159% | — | — |
| B | 4h | +0.129% | +0.121% | +0.119% | -0.123% | -0.254% | -0.561% |
| C | 1h | +0.085% | +0.115% | +0.072% | -0.062% | — | — |
| C | 4h | +0.075% | +0.115% | +0.333% | +0.115% | +0.726% | +0.743% |
| D | 1h | -0.063% | -0.062% | -0.103% | -0.166% | — | — |
| D | 4h | +0.043% | +0.024% | +0.049% | -0.196% | -0.548% | -0.731% |
| E | 1h | +0.035% | +0.019% | -0.044% | -0.156% | — | — |
| E | 4h | +0.023% | -0.050% | -0.026% | -0.254% | -0.125% | -0.256% |
