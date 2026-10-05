# Apéndice de datos — auditoría de familias de señales

Estudio offline, read-only. No se modificó código del bot ni BD. Protocolo pre-registrado en [PROTOCOLO.md](PROTOCOLO.md) (con tres enmiendas metodológicas declaradas antes de abrir el TEST).

> **Regla madre:** El Trading Bot busca obtener pequeñas ganancias de manera frecuente y consistente, priorizando la preservación del capital y un riesgo bajo sobre la maximización del rendimiento.

## 1. Resumen ejecutivo

| Familia | Parámetro primario | Clase (pre-TEST) | Clase final |
|---|---|---|---|
| A — Retroceso en tendencia | 14 | C | **C** |
| B — Ruptura | 24 | C | **C** |
| C — Expansión de volatilidad | 24 | E | **E** |
| D — Momentum cross-sectional | 24 | C | **C** |
| E — Régimen + entrada | 200 | C | **C** |

## 2. Datos, período y separación

- Velas 1h spot de Binance (API pública), 20 pares USDT líquidos: BTC, ETH, BNB, XRP, SOL, ADA, DOGE, TRX, LINK, LTC, AVAX, DOT, ETC, BCH, ATOM, NEAR, XLM, UNI, AAVE, IOTA. Sesgo de supervivencia reconocido (pares vigentes hoy).
- Período descargado: 2023-10-01 → 2026-10-01 UTC (26.304 velas por activo). Barras elegibles: ≥800 velas de historia y ≥24 futuras.
- TRAIN: 2023-11-03T08:00:00.000 → 2025-11-14T10:00:00.000
- VAL: 2025-11-15T11:00:00.000 → 2026-04-22T16:00:00.000
- TEST: 2026-04-23T17:00:00.000 → 2026-09-29T22:00:00.000 (leído una sola vez, con parámetros congelados). Embargo de 24 velas entre tramos.
- Capital de referencia 20 USDT **no interviene** en la medición de la señal: todo está en retorno porcentual por operación (1 unidad nocional). Capital y riesgo se diseñan después.
- Comisión: bruto = 0; neto = −0,20% round trip. Entrada en open[t+1] tras la señal en el cierre de t; salida en close[t+h]. Sin stops, SELL, trailing ni time exit.

### Referencia A (cualquier barra elegible), horizonte 8h

| Tramo | n | bruto medio | neto medio | win neto | PF neto | p5 neto |
|---|---|---|---|---|---|---|
| TRAIN | 356220 | +0.058% | -0.142% | 45.5% | 0.85 | -4.00% |
| VAL | 75960 | -0.078% | -0.278% | 41.7% | 0.69 | -3.61% |
| TRAIN+VAL | 432180 | +0.034% | -0.166% | 44.9% | 0.82 | -3.94% |
| TEST | 76440 | +0.062% | -0.138% | 42.8% | 0.81 | -3.08% |

Entrar en cualquier vela cuesta, en promedio, ~0,17–0,28 pp por operación después de comisión: **la vara base es negativa**.

## 3. Definición exacta de las señales

- **A Retroceso en tendencia**: close>SMA168 y SMA168[t]>SMA168[t−24] y RSI Wilder(N) cruza al alza el nivel 35. N∈{10,12,14,16,18}.
- **B Ruptura**: primer cierre > máximo de los N máximos previos. N∈{18,21,24,27,30}.
- **C Expansión de volatilidad**: rango verdadero > 2× media de las N velas previas y vela alcista (close>open). N∈{18,21,24,27,30}.
- **D Momentum cross-sectional**: el activo entra al top-3 del universo por ROC(N) con ROC>0 (no estaba en t−1). N∈{18,21,24,27,30}.
- **E Régimen + entrada**: BTC>SMA(N) y vol 24h de BTC ≤ mediana de 720h (régimen); entrada = vela alcista con volumen>1,5× media 24h. N∈{160,…,240}.
- Cooldown de 24 velas por activo en todas.

## 4. Compuertas (desarrollo = TRAIN+VAL, 8h) y resultado fuera de muestra

### A — Retroceso en tendencia

| Compuerta | ¿Pasa? | Detalle |
|---|---|---|
| G1 edge bruto vs aleatoria | **no** | exceso C 8h +0.027% (p=0.366); TRAIN +0.009%, VAL +0.128%; horizontes con exceso>0: 2/6 |
| G2 neto/PF | **no** | neto medio -0.073%, exceso A +0.093%, PF neto 0.91; celdas con neto>0: 1/5 |
| G3 riesgo | sí | p5 neto -3.56%, peor -8.18%, DD bruto (slots 10%) -8.8% |
| G4 temporal/régimen | **no** | trimestres+ 55% (11), sin mejor trim. ≤0; regímenes+ 2/3 |
| G5 activos | **no** | activos+ 65% (20), sin mejor activo ≤0 |
| G6 frecuencia | sí | 1.18 señales/día |
| G7 robustez de parámetros | **no** | celdas con exceso bruto>0: 3/5 |
| G8 TEST | sí | n=177, exceso C 8h +0.175% (p=0.122), neto medio +0.096% |

### B — Ruptura

| Compuerta | ¿Pasa? | Detalle |
|---|---|---|
| G1 edge bruto vs aleatoria | **no** | exceso C 8h +0.029% (p=0.173); TRAIN +0.014%, VAL +0.113%; horizontes con exceso>0: 4/6 |
| G2 neto/PF | **no** | neto medio -0.111%, exceso A +0.055%, PF neto 0.88; celdas con neto>0: 0/5 |
| G3 riesgo | **no** | p5 neto -4.09%, peor -16.50%, DD bruto (slots 10%) -51.4% |
| G4 temporal/régimen | sí | trimestres+ 73% (11), sin mejor trim. >0; regímenes+ 2/3 |
| G5 activos | sí | activos+ 60% (20), sin mejor activo >0 |
| G6 frecuencia | sí | 7.53 señales/día |
| G7 robustez de parámetros | sí | celdas con exceso bruto>0: 5/5 |
| G8 TEST | **no** | n=1131, exceso C 8h +0.031% (p=0.308), neto medio -0.072% |

### C — Expansión de volatilidad

| Compuerta | ¿Pasa? | Detalle |
|---|---|---|
| G1 edge bruto vs aleatoria | sí | exceso C 8h +0.115% (p=0.000); TRAIN +0.126%, VAL +0.068%; horizontes con exceso>0: 5/6 |
| G2 neto/PF | **no** | neto medio -0.043%, exceso A +0.124%, PF neto 0.96; celdas con neto>0: 0/5 |
| G3 riesgo | **no** | p5 neto -4.52%, peor -19.66%, DD bruto (slots 10%) -23.7% |
| G4 temporal/régimen | sí | trimestres+ 73% (11), sin mejor trim. >0; regímenes+ 2/3 |
| G5 activos | sí | activos+ 90% (20), sin mejor activo >0 |
| G6 frecuencia | sí | 7.55 señales/día |
| G7 robustez de parámetros | sí | celdas con exceso bruto>0: 5/5 |
| G8 TEST | **no** | n=1255, exceso C 8h -0.171% (p=0.998), neto medio -0.299% |

### D — Momentum cross-sectional

| Compuerta | ¿Pasa? | Detalle |
|---|---|---|
| G1 edge bruto vs aleatoria | **no** | exceso C 8h -0.062% (p=0.949); TRAIN -0.051%, VAL -0.109%; horizontes con exceso>0: 0/6 |
| G2 neto/PF | **no** | neto medio -0.191%, exceso A -0.025%, PF neto 0.82; celdas con neto>0: 0/5 |
| G3 riesgo | **no** | p5 neto -4.21%, peor -31.59%, DD bruto (slots 10%) -41.5% |
| G4 temporal/régimen | **no** | trimestres+ 36% (11), sin mejor trim. ≤0; regímenes+ 1/3 |
| G5 activos | **no** | activos+ 40% (20), sin mejor activo ≤0 |
| G6 frecuencia | sí | 5.52 señales/día |
| G7 robustez de parámetros | **no** | celdas con exceso bruto>0: 0/5 |
| G8 TEST | **no** | n=884, exceso C 8h -0.145% (p=0.977), neto medio -0.244% |

### E — Régimen + entrada

| Compuerta | ¿Pasa? | Detalle |
|---|---|---|
| G1 edge bruto vs aleatoria | **no** | exceso C 8h +0.019% (p=0.301); TRAIN +0.065%, VAL -0.208%; horizontes con exceso>0: 3/6 |
| G2 neto/PF | **no** | neto medio -0.122%, exceso A +0.044%, PF neto 0.87; celdas con neto>0: 0/5 |
| G3 riesgo | **no** | p5 neto -3.88%, peor -14.50%, DD bruto (slots 10%) -33.0% |
| G4 temporal/régimen | **no** | trimestres+ 64% (11), sin mejor trim. ≤0; regímenes+ 1/3 |
| G5 activos | **no** | activos+ 55% (20), sin mejor activo >0 |
| G6 frecuencia | sí | 4.95 señales/día |
| G7 robustez de parámetros | sí | celdas con exceso bruto>0: 4/5 |
| G8 TEST | **no** | n=780, exceso C 8h -0.159% (p=0.989), neto medio -0.268% |

## 5. Resultados por familia (parámetro primario)

### A — Retroceso en tendencia (N=14) → clase final **C**

**Oportunidades** (TRAIN+VAL): 1062 — 1.18/día en el universo, 0.059 por activo-día, 8.3/semana, 36/mes. TEST: 177 (1.11/día).

**Retornos por horizonte — TRAIN+VAL**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 1h | 1062 | -0.021% | +0.000% | -0.221% | +0.004% | -0.029% | -0.025% | +0.008% | -0.034% | 0.884 |
| 2h | 1062 | -0.018% | +0.001% | -0.218% | +0.008% | -0.015% | -0.027% | -0.003% | -0.044% | 0.865 |
| 4h | 1062 | +0.116% | +0.047% | -0.084% | +0.017% | +0.081% | +0.099% | +0.034% | +0.066% | 0.118 |
| 8h | 1062 | +0.127% | +0.006% | -0.073% | +0.034% | +0.115% | +0.093% | +0.013% | +0.027% | 0.366 |
| 16h | 1062 | +0.014% | -0.051% | -0.186% | +0.067% | +0.030% | -0.053% | -0.017% | -0.185% | 0.954 |
| 24h | 1062 | +0.239% | +0.091% | +0.039% | +0.100% | +0.284% | +0.139% | -0.045% | -0.056% | 0.660 |

**Retornos por horizonte — TEST (fuera de muestra)**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 1h | 177 | -0.024% | +0.000% | -0.224% | +0.007% | +0.012% | -0.031% | -0.036% | -0.039% | 0.759 |
| 2h | 177 | +0.028% | -0.040% | -0.172% | +0.015% | +0.028% | +0.013% | -0.000% | -0.003% | 0.515 |
| 4h | 177 | +0.087% | +0.064% | -0.113% | +0.031% | +0.104% | +0.056% | -0.017% | +0.026% | 0.405 |
| 8h | 177 | +0.296% | +0.190% | +0.096% | +0.062% | +0.327% | +0.235% | -0.031% | +0.175% | 0.122 |
| 16h | 177 | +0.444% | +0.194% | +0.244% | +0.127% | +0.356% | +0.318% | +0.089% | +0.200% | 0.175 |
| 24h | 177 | +0.661% | +0.285% | +0.461% | +0.194% | +0.484% | +0.466% | +0.177% | +0.293% | 0.138 |

**Riesgo a 8h — TRAIN+VAL**: win rate neto 45.7% (bruto 50.0%); PF neto 0.91 (bruto 1.18); media neta -0.073%, mediana neta -0.194%; pérdida media -1.51%, mediana -1.03%; p5/p10 -3.56%/-2.52%; peor -8.18%; pérdidas <-2%: 14.6%; ganancias netas (0,1%]: 23.1%; MAE medio/mediana/p5 -1.71%/-1.22%/-4.80%; MFE medio/mediana +1.77%/+1.13%; DD slots 10% neto/bruto -13.3%/-8.8%; días+ (con señal) 48%, días+ (calendario) 19%; peor día -7.64%; racha negativa máx. 4 días

**Riesgo a 8h — TEST**: win rate neto 49.7% (bruto 55.4%); PF neto 1.17 (bruto 1.62); media neta +0.096%, mediana neta -0.010%; pérdida media -1.14%, mediana -0.79%; p5/p10 -2.54%/-1.90%; peor -5.59%; pérdidas <-2%: 7.9%; ganancias netas (0,1%]: 29.4%; MAE medio/mediana/p5 -1.18%/-0.83%/-3.61%; MFE medio/mediana +1.44%/+0.98%; DD slots 10% neto/bruto -2.8%/-2.1%; días+ (con señal) 47%, días+ (calendario) 20%; peor día -3.85%; racha negativa máx. 3 días

**Por activo (TRAIN+VAL)**

| Activo | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| AAVE | 50 | -0.157% | -0.307% | 44% |
| ADA | 53 | +0.227% | +0.132% | 43% |
| ATOM | 46 | -0.446% | -0.574% | 35% |
| AVAX | 52 | -0.397% | -0.423% | 46% |
| BCH | 62 | +0.233% | +0.135% | 50% |
| BNB | 57 | +0.059% | -0.036% | 42% |
| BTC | 51 | +0.029% | -0.077% | 39% |
| DOGE | 47 | +0.159% | +0.107% | 47% |
| DOT | 66 | +0.125% | -0.005% | 47% |
| ETC | 58 | +0.612% | +0.477% | 48% |
| ETH | 62 | -0.035% | -0.144% | 42% |
| IOTA | 47 | +0.234% | +0.166% | 62% |
| LINK | 46 | +0.010% | -0.078% | 35% |
| LTC | 47 | -0.138% | -0.253% | 45% |
| NEAR | 41 | -0.370% | -0.509% | 39% |
| SOL | 59 | -0.028% | -0.054% | 49% |
| TRX | 85 | +0.067% | -0.060% | 49% |
| UNI | 48 | +0.015% | -0.087% | 40% |
| XLM | 38 | +0.042% | -0.079% | 47% |
| XRP | 47 | +0.021% | -0.047% | 60% |

**Por trimestre (TRAIN+VAL)**

| Trimestre | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| 2023Q4 | 80 | +0.132% | +0.242% | 55% |
| 2024Q1 | 134 | +0.454% | +0.439% | 54% |
| 2024Q2 | 95 | -0.149% | -0.374% | 43% |
| 2024Q3 | 123 | -0.472% | -0.635% | 41% |
| 2024Q4 | 105 | +0.341% | +0.480% | 51% |
| 2025Q1 | 80 | +0.612% | +0.421% | 52% |
| 2025Q2 | 104 | -0.254% | -0.378% | 39% |
| 2025Q3 | 135 | -0.449% | -0.503% | 37% |
| 2025Q4 | 79 | +0.143% | -0.134% | 42% |
| 2026Q1 | 93 | +0.341% | +0.075% | 53% |
| 2026Q2 | 34 | -0.339% | -0.438% | 21% |

**Por régimen de mercado (TRAIN+VAL)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 386 | +0.391% | +0.434% | 54% |
| bajista | 101 | +0.237% | -0.002% | 50% |
| lateral | 575 | -0.255% | -0.425% | 39% |

**Por régimen (TEST)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 57 | +0.039% | -0.008% | 54% |
| bajista | 19 | +1.120% | +0.711% | 63% |
| lateral | 101 | +0.074% | +0.040% | 45% |

**Robustez de parámetros (TRAIN+VAL, 8h)**

| N | n | exceso C | p | bruto medio | neto medio | TEST exceso C | TEST neto |
|---|---|---|---|---|---|---|---|
| 10 | 2067 | -0.131% | 0.990 | -0.028% | -0.228% | +0.041% | -0.032% |
| 12 | 1492 | -0.060% | 0.817 | +0.046% | -0.154% | +0.155% | +0.072% |
| 14 (primario) | 1062 | +0.027% | 0.366 | +0.127% | -0.073% | +0.175% | +0.096% |
| 16 | 683 | +0.069% | 0.237 | +0.159% | -0.041% | +0.076% | -0.011% |
| 18 | 423 | +0.158% | 0.094 | +0.247% | +0.047% | +0.198% | +0.087% |

### B — Ruptura (N=24) → clase final **C**

**Oportunidades** (TRAIN+VAL): 6778 — 7.53/día en el universo, 0.376 por activo-día, 52.7/semana, 229/mes. TEST: 1131 (7.10/día).

**Retornos por horizonte — TRAIN+VAL**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 1h | 6778 | +0.061% | -0.033% | -0.139% | +0.004% | +0.047% | +0.057% | +0.013% | +0.054% | 0.000 |
| 2h | 6778 | +0.048% | -0.064% | -0.152% | +0.008% | +0.033% | +0.039% | +0.015% | +0.033% | 0.018 |
| 4h | 6778 | +0.057% | -0.056% | -0.143% | +0.017% | +0.052% | +0.040% | +0.005% | +0.027% | 0.106 |
| 8h | 6778 | +0.089% | -0.005% | -0.111% | +0.034% | +0.075% | +0.055% | +0.014% | +0.029% | 0.173 |
| 16h | 6778 | +0.110% | -0.040% | -0.090% | +0.067% | +0.100% | +0.043% | +0.010% | -0.008% | 0.570 |
| 24h | 6778 | +0.017% | -0.201% | -0.183% | +0.100% | +0.031% | -0.084% | -0.015% | -0.159% | 0.999 |

**Retornos por horizonte — TEST (fuera de muestra)**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 1h | 1131 | +0.029% | -0.052% | -0.171% | +0.007% | -0.004% | +0.022% | +0.033% | +0.017% | 0.221 |
| 2h | 1131 | +0.073% | -0.022% | -0.127% | +0.015% | +0.031% | +0.058% | +0.042% | +0.048% | 0.059 |
| 4h | 1131 | +0.046% | -0.137% | -0.154% | +0.031% | +0.010% | +0.015% | +0.036% | -0.003% | 0.529 |
| 8h | 1131 | +0.128% | -0.092% | -0.072% | +0.062% | +0.030% | +0.067% | +0.098% | +0.031% | 0.308 |
| 16h | 1131 | +0.381% | -0.040% | +0.181% | +0.127% | +0.205% | +0.254% | +0.176% | +0.184% | 0.017 |
| 24h | 1131 | +0.430% | -0.030% | +0.230% | +0.194% | +0.269% | +0.236% | +0.161% | +0.131% | 0.113 |

**Riesgo a 8h — TRAIN+VAL**: win rate neto 44.8% (bruto 49.3%); PF neto 0.88 (bruto 1.10); media neta -0.111%, mediana neta -0.205%; pérdida media -1.74%, mediana -1.19%; p5/p10 -4.09%/-2.91%; peor -16.50%; pérdidas <-2%: 17.3%; ganancias netas (0,1%]: 19.6%; MAE medio/mediana/p5 -1.79%/-1.21%/-5.33%; MFE medio/mediana +1.96%/+1.27%; DD slots 10% neto/bruto -80.4%/-51.4%; días+ (con señal) 36%, días+ (calendario) 33%; peor día -13.51%; racha negativa máx. 11 días

**Riesgo a 8h — TEST**: win rate neto 40.8% (bruto 47.5%); PF neto 0.91 (bruto 1.18); media neta -0.072%, mediana neta -0.292%; pérdida media -1.43%, mediana -1.08%; p5/p10 -3.35%/-2.47%; peor -9.80%; pérdidas <-2%: 15.5%; ganancias netas (0,1%]: 20.1%; MAE medio/mediana/p5 -1.55%/-1.07%/-4.57%; MFE medio/mediana +1.81%/+1.00%; DD slots 10% neto/bruto -20.8%/-11.7%; días+ (con señal) 39%, días+ (calendario) 37%; peor día -8.24%; racha negativa máx. 7 días

**Por activo (TRAIN+VAL)**

| Activo | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| AAVE | 348 | -0.145% | -0.292% | 43% |
| ADA | 331 | +0.207% | +0.080% | 47% |
| ATOM | 341 | +0.178% | -0.028% | 49% |
| AVAX | 328 | +0.223% | +0.091% | 52% |
| BCH | 312 | -0.039% | -0.157% | 42% |
| BNB | 353 | +0.130% | -0.003% | 45% |
| BTC | 342 | +0.068% | -0.079% | 43% |
| DOGE | 340 | -0.037% | -0.139% | 45% |
| DOT | 337 | +0.019% | -0.150% | 43% |
| ETC | 326 | +0.126% | -0.051% | 45% |
| ETH | 347 | +0.009% | -0.134% | 42% |
| IOTA | 330 | -0.055% | -0.224% | 42% |
| LINK | 345 | -0.028% | -0.177% | 45% |
| LTC | 359 | -0.196% | -0.366% | 40% |
| NEAR | 348 | +0.133% | +0.008% | 50% |
| SOL | 343 | +0.121% | +0.015% | 49% |
| TRX | 378 | +0.078% | -0.059% | 47% |
| UNI | 334 | -0.170% | -0.303% | 42% |
| XLM | 321 | -0.162% | -0.269% | 43% |
| XRP | 315 | +0.128% | +0.022% | 42% |

**Por trimestre (TRAIN+VAL)**

| Trimestre | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| 2023Q4 | 499 | -0.101% | -0.041% | 45% |
| 2024Q1 | 753 | +0.026% | +0.034% | 45% |
| 2024Q2 | 656 | +0.161% | -0.123% | 43% |
| 2024Q3 | 690 | +0.035% | -0.141% | 45% |
| 2024Q4 | 707 | -0.266% | -0.189% | 44% |
| 2025Q1 | 644 | +0.020% | -0.263% | 44% |
| 2025Q2 | 675 | +0.122% | -0.019% | 48% |
| 2025Q3 | 763 | +0.127% | +0.042% | 48% |
| 2025Q4 | 639 | -0.148% | -0.483% | 37% |
| 2026Q1 | 565 | +0.303% | +0.025% | 49% |
| 2026Q2 | 187 | +0.093% | -0.014% | 47% |

**Por régimen de mercado (TRAIN+VAL)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 2078 | -0.101% | -0.055% | 45% |
| bajista | 794 | +0.366% | +0.061% | 44% |
| lateral | 3906 | +0.030% | -0.176% | 45% |

**Por régimen (TEST)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 443 | +0.000% | -0.027% | 42% |
| bajista | 175 | +0.188% | -0.209% | 41% |
| lateral | 513 | +0.003% | -0.064% | 40% |

**Robustez de parámetros (TRAIN+VAL, 8h)**

| N | n | exceso C | p | bruto medio | neto medio | TEST exceso C | TEST neto |
|---|---|---|---|---|---|---|---|
| 18 | 7808 | +0.072% | 0.006 | +0.126% | -0.074% | +0.036% | -0.069% |
| 21 | 7279 | +0.057% | 0.029 | +0.112% | -0.088% | +0.074% | -0.026% |
| 24 (primario) | 6778 | +0.029% | 0.173 | +0.089% | -0.111% | +0.031% | -0.072% |
| 27 | 6280 | +0.001% | 0.487 | +0.065% | -0.135% | +0.018% | -0.081% |
| 30 | 5878 | +0.018% | 0.289 | +0.085% | -0.115% | -0.009% | -0.108% |

### C — Expansión de volatilidad (N=24) → clase final **E**

**Oportunidades** (TRAIN+VAL): 6795 — 7.55/día en el universo, 0.377 por activo-día, 52.8/semana, 230/mes. TEST: 1255 (7.88/día).

**Retornos por horizonte — TRAIN+VAL**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 1h | 6795 | +0.094% | +0.000% | -0.106% | +0.004% | +0.096% | +0.090% | -0.002% | +0.089% | 0.000 |
| 2h | 6795 | +0.086% | +0.020% | -0.114% | +0.008% | +0.073% | +0.078% | +0.014% | +0.076% | 0.000 |
| 4h | 6795 | +0.106% | +0.013% | -0.094% | +0.017% | +0.082% | +0.089% | +0.024% | +0.085% | 0.000 |
| 8h | 6795 | +0.157% | +0.099% | -0.043% | +0.034% | +0.113% | +0.124% | +0.044% | +0.115% | 0.000 |
| 16h | 6795 | +0.156% | +0.026% | -0.044% | +0.067% | +0.123% | +0.089% | +0.033% | +0.072% | 0.048 |
| 24h | 6795 | +0.062% | -0.146% | -0.138% | +0.100% | +0.041% | -0.038% | +0.021% | -0.062% | 0.881 |

**Retornos por horizonte — TEST (fuera de muestra)**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 1h | 1255 | +0.069% | +0.028% | -0.131% | +0.007% | +0.058% | +0.062% | +0.010% | +0.061% | 0.002 |
| 2h | 1255 | +0.042% | -0.020% | -0.158% | +0.015% | +0.006% | +0.027% | +0.035% | +0.024% | 0.207 |
| 4h | 1255 | -0.052% | -0.061% | -0.252% | +0.031% | -0.076% | -0.083% | +0.024% | -0.088% | 0.984 |
| 8h | 1255 | -0.099% | -0.125% | -0.299% | +0.062% | -0.109% | -0.160% | +0.010% | -0.171% | 0.998 |
| 16h | 1255 | +0.313% | -0.029% | +0.113% | +0.127% | +0.226% | +0.186% | +0.087% | +0.166% | 0.021 |
| 24h | 1255 | +0.227% | -0.116% | +0.027% | +0.194% | +0.143% | +0.033% | +0.084% | +0.001% | 0.494 |

**Riesgo a 8h — TRAIN+VAL**: win rate neto 47.8% (bruto 52.0%); PF neto 0.96 (bruto 1.17); media neta -0.043%, mediana neta -0.101%; pérdida media -2.01%, mediana -1.38%; p5/p10 -4.52%/-3.16%; peor -19.66%; pérdidas <-2%: 19.3%; ganancias netas (0,1%]: 18.9%; MAE medio/mediana/p5 -2.03%/-1.32%/-6.08%; MFE medio/mediana +2.18%/+1.43%; DD slots 10% neto/bruto -55.5%/-23.7%; días+ (con señal) 45%, días+ (calendario) 43%; peor día -13.38%; racha negativa máx. 8 días

**Riesgo a 8h — TEST**: win rate neto 40.2% (bruto 45.7%); PF neto 0.69 (bruto 0.89); media neta -0.299%, mediana neta -0.325%; pérdida media -1.63%, mediana -1.12%; p5/p10 -3.81%/-3.00%; peor -10.94%; pérdidas <-2%: 18.2%; ganancias netas (0,1%]: 22.5%; MAE medio/mediana/p5 -1.74%/-1.16%/-5.30%; MFE medio/mediana +1.74%/+1.03%; DD slots 10% neto/bruto -33.0%/-21.1%; días+ (con señal) 39%, días+ (calendario) 36%; peor día -5.66%; racha negativa máx. 7 días

**Por activo (TRAIN+VAL)**

| Activo | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| AAVE | 342 | +0.083% | -0.070% | 48% |
| ADA | 340 | +0.218% | +0.050% | 49% |
| ATOM | 300 | +0.051% | -0.169% | 45% |
| AVAX | 347 | +0.205% | +0.053% | 50% |
| BCH | 375 | +0.135% | +0.003% | 46% |
| BNB | 339 | +0.145% | -0.004% | 50% |
| BTC | 402 | +0.025% | -0.131% | 45% |
| DOGE | 364 | +0.182% | +0.052% | 51% |
| DOT | 322 | +0.193% | -0.013% | 47% |
| ETC | 343 | +0.089% | -0.098% | 45% |
| ETH | 373 | +0.150% | -0.012% | 49% |
| IOTA | 305 | -0.244% | -0.427% | 47% |
| LINK | 318 | +0.123% | -0.037% | 50% |
| LTC | 324 | +0.082% | -0.095% | 49% |
| NEAR | 327 | +0.141% | +0.007% | 50% |
| SOL | 349 | -0.047% | -0.182% | 47% |
| TRX | 328 | +0.342% | +0.205% | 51% |
| UNI | 340 | +0.148% | -0.020% | 46% |
| XLM | 304 | +0.232% | +0.104% | 46% |
| XRP | 353 | +0.040% | -0.090% | 45% |

**Por trimestre (TRAIN+VAL)**

| Trimestre | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| 2023Q4 | 409 | -0.205% | -0.150% | 47% |
| 2024Q1 | 649 | +0.240% | +0.232% | 51% |
| 2024Q2 | 698 | +0.459% | +0.169% | 48% |
| 2024Q3 | 715 | +0.012% | -0.172% | 47% |
| 2024Q4 | 643 | +0.123% | +0.204% | 47% |
| 2025Q1 | 632 | -0.012% | -0.273% | 49% |
| 2025Q2 | 661 | -0.142% | -0.280% | 49% |
| 2025Q3 | 758 | +0.120% | +0.026% | 49% |
| 2025Q4 | 739 | +0.133% | -0.216% | 45% |
| 2026Q1 | 714 | +0.203% | -0.080% | 45% |
| 2026Q2 | 177 | +0.395% | +0.285% | 54% |

**Por régimen de mercado (TRAIN+VAL)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 1830 | -0.030% | +0.002% | 47% |
| bajista | 990 | +0.784% | +0.466% | 55% |
| lateral | 3975 | +0.015% | -0.190% | 46% |

**Por régimen (TEST)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 405 | -0.188% | -0.205% | 43% |
| bajista | 244 | -0.452% | -0.870% | 37% |
| lateral | 606 | -0.047% | -0.132% | 39% |

**Robustez de parámetros (TRAIN+VAL, 8h)**

| N | n | exceso C | p | bruto medio | neto medio | TEST exceso C | TEST neto |
|---|---|---|---|---|---|---|---|
| 18 | 7143 | +0.054% | 0.035 | +0.098% | -0.102% | -0.169% | -0.300% |
| 21 | 7028 | +0.052% | 0.044 | +0.095% | -0.105% | -0.140% | -0.268% |
| 24 (primario) | 6795 | +0.115% | 0.000 | +0.157% | -0.043% | -0.171% | -0.299% |
| 27 | 6656 | +0.127% | 0.000 | +0.171% | -0.029% | -0.164% | -0.293% |
| 30 | 6668 | +0.117% | 0.000 | +0.160% | -0.040% | -0.161% | -0.289% |

### D — Momentum cross-sectional (N=24) → clase final **C**

**Oportunidades** (TRAIN+VAL): 4967 — 5.52/día en el universo, 0.276 por activo-día, 38.6/semana, 168/mes. TEST: 884 (5.55/día).

**Retornos por horizonte — TRAIN+VAL**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 1h | 4967 | -0.009% | -0.038% | -0.209% | +0.004% | -0.001% | -0.013% | -0.009% | -0.018% | 0.910 |
| 2h | 4967 | -0.026% | -0.058% | -0.226% | +0.008% | -0.016% | -0.034% | -0.010% | -0.044% | 0.989 |
| 4h | 4967 | -0.028% | -0.062% | -0.228% | +0.017% | -0.024% | -0.045% | -0.004% | -0.063% | 0.991 |
| 8h | 4967 | +0.009% | -0.060% | -0.191% | +0.034% | -0.000% | -0.025% | +0.009% | -0.062% | 0.949 |
| 16h | 4967 | +0.038% | -0.091% | -0.162% | +0.067% | +0.051% | -0.029% | -0.014% | -0.103% | 0.973 |
| 24h | 4967 | +0.044% | -0.099% | -0.156% | +0.100% | +0.074% | -0.056% | -0.030% | -0.166% | 0.995 |

**Retornos por horizonte — TEST (fuera de muestra)**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 1h | 884 | -0.011% | -0.033% | -0.211% | +0.007% | -0.018% | -0.018% | +0.006% | -0.023% | 0.809 |
| 2h | 884 | -0.050% | -0.058% | -0.250% | +0.015% | -0.042% | -0.065% | -0.008% | -0.075% | 0.979 |
| 4h | 884 | -0.059% | -0.089% | -0.259% | +0.031% | -0.041% | -0.089% | -0.018% | -0.109% | 0.983 |
| 8h | 884 | -0.044% | -0.069% | -0.244% | +0.062% | -0.006% | -0.105% | -0.037% | -0.145% | 0.977 |
| 16h | 884 | +0.275% | -0.040% | +0.075% | +0.127% | +0.205% | +0.149% | +0.070% | +0.068% | 0.253 |
| 24h | 884 | +0.408% | -0.044% | +0.208% | +0.194% | +0.283% | +0.214% | +0.125% | +0.093% | 0.237 |

**Riesgo a 8h — TRAIN+VAL**: win rate neto 43.4% (bruto 48.0%); PF neto 0.82 (bruto 1.01); media neta -0.191%, mediana neta -0.260%; pérdida media -1.85%, mediana -1.31%; p5/p10 -4.21%/-3.08%; peor -31.59%; pérdidas <-2%: 18.9%; ganancias netas (0,1%]: 18.9%; MAE medio/mediana/p5 -1.97%/-1.35%/-5.75%; MFE medio/mediana +2.02%/+1.26%; DD slots 10% neto/bruto -65.2%/-41.5%; días+ (con señal) 42%, días+ (calendario) 41%; peor día -8.93%; racha negativa máx. 21 días

**Riesgo a 8h — TEST**: win rate neto 39.3% (bruto 46.0%); PF neto 0.73 (bruto 0.94); media neta -0.244%, mediana neta -0.269%; pérdida media -1.47%, mediana -1.06%; p5/p10 -3.57%/-2.58%; peor -10.83%; pérdidas <-2%: 15.3%; ganancias netas (0,1%]: 20.7%; MAE medio/mediana/p5 -1.69%/-1.10%/-4.90%; MFE medio/mediana +1.69%/+1.00%; DD slots 10% neto/bruto -19.9%/-9.6%; días+ (con señal) 42%, días+ (calendario) 41%; peor día -5.15%; racha negativa máx. 11 días

**Por activo (TRAIN+VAL)**

| Activo | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| AAVE | 322 | -0.112% | -0.254% | 43% |
| ADA | 216 | -0.172% | -0.271% | 39% |
| ATOM | 235 | +0.045% | -0.147% | 47% |
| AVAX | 261 | -0.170% | -0.287% | 42% |
| BCH | 279 | -0.138% | -0.268% | 38% |
| BNB | 225 | +0.169% | +0.033% | 48% |
| BTC | 202 | +0.009% | -0.138% | 40% |
| DOGE | 244 | +0.125% | +0.045% | 46% |
| DOT | 209 | -0.174% | -0.351% | 45% |
| ETC | 168 | -0.143% | -0.307% | 41% |
| ETH | 207 | +0.106% | -0.044% | 48% |
| IOTA | 264 | -0.226% | -0.379% | 40% |
| LINK | 256 | -0.169% | -0.316% | 43% |
| LTC | 259 | -0.281% | -0.458% | 39% |
| NEAR | 318 | +0.238% | +0.118% | 46% |
| SOL | 301 | -0.098% | -0.201% | 46% |
| TRX | 308 | +0.026% | -0.109% | 45% |
| UNI | 282 | +0.318% | +0.205% | 46% |
| XLM | 212 | -0.613% | -0.656% | 41% |
| XRP | 199 | -0.155% | -0.217% | 43% |

**Por trimestre (TRAIN+VAL)**

| Trimestre | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| 2023Q4 | 307 | -0.163% | -0.039% | 47% |
| 2024Q1 | 503 | +0.054% | +0.072% | 45% |
| 2024Q2 | 496 | +0.195% | -0.069% | 45% |
| 2024Q3 | 461 | -0.124% | -0.286% | 47% |
| 2024Q4 | 491 | +0.137% | +0.250% | 50% |
| 2025Q1 | 473 | -0.297% | -0.559% | 43% |
| 2025Q2 | 546 | -0.121% | -0.241% | 42% |
| 2025Q3 | 515 | +0.015% | -0.044% | 46% |
| 2025Q4 | 525 | -0.309% | -0.625% | 35% |
| 2026Q1 | 513 | -0.027% | -0.297% | 38% |
| 2026Q2 | 137 | -0.114% | -0.219% | 40% |

**Por régimen de mercado (TRAIN+VAL)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 1354 | -0.018% | +0.065% | 45% |
| bajista | 656 | +0.163% | -0.122% | 44% |
| lateral | 2957 | -0.132% | -0.323% | 43% |

**Por régimen (TEST)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 291 | -0.206% | -0.205% | 41% |
| bajista | 162 | -0.011% | -0.419% | 43% |
| lateral | 431 | -0.154% | -0.204% | 37% |

**Robustez de parámetros (TRAIN+VAL, 8h)**

| N | n | exceso C | p | bruto medio | neto medio | TEST exceso C | TEST neto |
|---|---|---|---|---|---|---|---|
| 18 | 5907 | -0.066% | 0.972 | -0.005% | -0.205% | -0.066% | -0.169% |
| 21 | 5430 | -0.030% | 0.798 | +0.034% | -0.166% | -0.002% | -0.105% |
| 24 (primario) | 4967 | -0.062% | 0.949 | +0.009% | -0.191% | -0.145% | -0.244% |
| 27 | 4682 | -0.067% | 0.957 | +0.002% | -0.198% | +0.007% | -0.091% |
| 30 | 4507 | -0.072% | 0.965 | -0.001% | -0.201% | +0.148% | +0.049% |

### E — Régimen + entrada (N=200) → clase final **C**

**Oportunidades** (TRAIN+VAL): 4461 — 4.95/día en el universo, 0.248 por activo-día, 34.7/semana, 151/mes. TEST: 780 (4.90/día).

**Retornos por horizonte — TRAIN+VAL**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 1h | 4461 | +0.002% | -0.065% | -0.198% | +0.004% | -0.005% | -0.002% | +0.007% | -0.005% | 0.638 |
| 2h | 4461 | +0.036% | -0.048% | -0.164% | +0.008% | +0.019% | +0.027% | +0.017% | +0.021% | 0.135 |
| 4h | 4461 | +0.065% | +0.000% | -0.135% | +0.017% | +0.043% | +0.048% | +0.021% | +0.035% | 0.091 |
| 8h | 4461 | +0.078% | -0.029% | -0.122% | +0.034% | +0.047% | +0.044% | +0.031% | +0.019% | 0.301 |
| 16h | 4461 | +0.073% | -0.020% | -0.127% | +0.067% | +0.025% | +0.006% | +0.048% | -0.044% | 0.796 |
| 24h | 4461 | +0.017% | -0.149% | -0.183% | +0.100% | +0.003% | -0.083% | +0.014% | -0.156% | 0.993 |

**Retornos por horizonte — TEST (fuera de muestra)**

| h | n | bruto medio | bruto mediana | neto medio | Ref A | Ref B (mercado) | exceso A | exceso B | exceso C (aleat.) | p (C) |
|---|---|---|---|---|---|---|---|---|---|---|
| 1h | 780 | -0.062% | -0.088% | -0.262% | +0.007% | -0.054% | -0.068% | -0.008% | -0.073% | 0.998 |
| 2h | 780 | -0.087% | -0.147% | -0.287% | +0.015% | -0.075% | -0.102% | -0.011% | -0.110% | 0.999 |
| 4h | 780 | -0.039% | -0.063% | -0.239% | +0.031% | -0.023% | -0.070% | -0.016% | -0.086% | 0.959 |
| 8h | 780 | -0.068% | -0.150% | -0.268% | +0.062% | -0.035% | -0.129% | -0.033% | -0.159% | 0.989 |
| 16h | 780 | -0.123% | -0.276% | -0.323% | +0.127% | -0.111% | -0.249% | -0.012% | -0.305% | 0.999 |
| 24h | 780 | -0.222% | -0.406% | -0.422% | +0.194% | -0.152% | -0.416% | -0.070% | -0.494% | 1.000 |

**Riesgo a 8h — TRAIN+VAL**: win rate neto 44.0% (bruto 48.9%); PF neto 0.87 (bruto 1.09); media neta -0.122%, mediana neta -0.229%; pérdida media -1.66%, mediana -1.16%; p5/p10 -3.88%/-2.81%; peor -14.50%; pérdidas <-2%: 16.8%; ganancias netas (0,1%]: 19.4%; MAE medio/mediana/p5 -1.73%/-1.20%/-5.05%; MFE medio/mediana +1.82%/+1.16%; DD slots 10% neto/bruto -51.8%/-33.0%; días+ (con señal) 43%, días+ (calendario) 22%; peor día -9.05%; racha negativa máx. 8 días

**Riesgo a 8h — TEST**: win rate neto 38.2% (bruto 43.2%); PF neto 0.65 (bruto 0.90); media neta -0.268%, mediana neta -0.350%; pérdida media -1.24%, mediana -0.99%; p5/p10 -2.83%/-2.12%; peor -6.80%; pérdidas <-2%: 12.2%; ganancias netas (0,1%]: 21.8%; MAE medio/mediana/p5 -1.36%/-1.04%/-3.75%; MFE medio/mediana +1.39%/+0.86%; DD slots 10% neto/bruto -16.8%/-8.6%; días+ (con señal) 35%, días+ (calendario) 17%; peor día -2.98%; racha negativa máx. 5 días

**Por activo (TRAIN+VAL)**

| Activo | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| AAVE | 239 | -0.203% | -0.343% | 43% |
| ADA | 215 | +0.024% | -0.109% | 41% |
| ATOM | 227 | +0.198% | +0.002% | 48% |
| AVAX | 228 | +0.294% | +0.174% | 49% |
| BCH | 232 | -0.067% | -0.186% | 44% |
| BNB | 215 | +0.076% | -0.051% | 46% |
| BTC | 241 | -0.034% | -0.174% | 40% |
| DOGE | 226 | +0.201% | +0.077% | 50% |
| DOT | 215 | +0.253% | +0.054% | 46% |
| ETC | 231 | -0.083% | -0.257% | 44% |
| ETH | 227 | +0.048% | -0.093% | 43% |
| IOTA | 220 | -0.123% | -0.284% | 45% |
| LINK | 223 | +0.221% | +0.078% | 46% |
| LTC | 213 | +0.027% | -0.148% | 44% |
| NEAR | 224 | +0.182% | +0.049% | 47% |
| SOL | 227 | +0.067% | -0.038% | 46% |
| TRX | 204 | -0.010% | -0.143% | 46% |
| UNI | 228 | -0.349% | -0.497% | 37% |
| XLM | 213 | -0.188% | -0.305% | 35% |
| XRP | 213 | -0.132% | -0.241% | 40% |

**Por trimestre (TRAIN+VAL)**

| Trimestre | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| 2023Q4 | 327 | -0.263% | -0.209% | 45% |
| 2024Q1 | 421 | -0.020% | +0.020% | 47% |
| 2024Q2 | 470 | +0.264% | +0.004% | 45% |
| 2024Q3 | 497 | +0.109% | -0.060% | 44% |
| 2024Q4 | 440 | +0.169% | +0.186% | 45% |
| 2025Q1 | 440 | +0.343% | +0.073% | 49% |
| 2025Q2 | 557 | -0.301% | -0.452% | 41% |
| 2025Q3 | 468 | +0.293% | +0.203% | 48% |
| 2025Q4 | 327 | -0.655% | -0.981% | 31% |
| 2026Q1 | 332 | +0.065% | -0.224% | 44% |
| 2026Q2 | 182 | +0.004% | -0.111% | 42% |

**Por régimen de mercado (TRAIN+VAL)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 1420 | -0.118% | -0.116% | 44% |
| bajista | 364 | -0.112% | -0.401% | 40% |
| lateral | 2677 | +0.111% | -0.088% | 45% |

**Por régimen (TEST)**

| Régimen | n | exceso C 8h | neto medio 8h | win neto |
|---|---|---|---|---|
| alcista | 305 | -0.086% | -0.172% | 42% |
| bajista | 57 | -0.175% | -0.610% | 32% |
| lateral | 418 | -0.210% | -0.291% | 37% |

**Robustez de parámetros (TRAIN+VAL, 8h)**

| N | n | exceso C | p | bruto medio | neto medio | TEST exceso C | TEST neto |
|---|---|---|---|---|---|---|---|
| 160 | 4469 | +0.021% | 0.288 | +0.079% | -0.121% | -0.144% | -0.253% |
| 180 | 4476 | -0.004% | 0.541 | +0.053% | -0.147% | -0.126% | -0.235% |
| 200 (primario) | 4461 | +0.019% | 0.301 | +0.078% | -0.122% | -0.159% | -0.268% |
| 220 | 4424 | +0.010% | 0.390 | +0.071% | -0.129% | -0.135% | -0.243% |
| 240 | 4390 | +0.035% | 0.176 | +0.101% | -0.099% | -0.125% | -0.233% |

### Referencia adicional: familia E con solo el régimen (sin entrada), 8h

TRAIN+VAL: bruto -0.016%, exceso C -0.075% (p=0.996). TEST: bruto +0.019%, exceso C -0.055%.
