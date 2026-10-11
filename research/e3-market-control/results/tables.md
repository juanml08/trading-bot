# Tablas E3 (generado por `e3.py decide`)

**Veredicto:** INCONCLUSO  ['comparación primaria no válida: V1_muestra']

## Primaria (±14 d, calipers 0,25 DE, zona > 18 velas)

n incluidas 540/3397; C -0.341 %; ref +0.145 %; D -0.486 pp; n_eff 190

| bloque | IC95 D | p unilateral |
|---|---|---|
| 7 d | [-1.527; +0.496] pp | 0.8257 |
| 14 d | [-1.473; +0.419] pp | 0.8470 |
| 28 d | [-1.543; +0.399] pp | 0.8501 |

## Equilibrio (SMD medio; p5–p95 por sorteo)

| covariable | señal | control | SMD | p5..p95 | puerta |
|---|---|---|---|---|---|
| btc_ret180 | +0.0362 | +0.0308 | +0.039 | +0.021..+0.058 | True |
| btc_vol42 | +0.0072 | +0.0075 | -0.074 | -0.096..-0.051 | True |
| btc_ret6 | +0.0028 | +0.0026 | +0.020 | -0.002..+0.043 | True |
| mkt_ret6 | +0.0054 | +0.0051 | +0.024 | +0.003..+0.044 | True |
| mkt_ret6_ex_own | +0.0043 | +0.0050 | -0.056 | -0.078..-0.035 | None |
| asset_ret6 | +0.0267 | +0.0054 | +0.771 | +0.709..+0.831 | None |
| asset_ret180 | +0.0405 | +0.0233 | +0.077 | +0.049..+0.106 | None |

## Puertas

```
{
 "V1_muestra": false,
 "V2_candidatos": true,
 "V3_equilibrio": true,
 "V4_reutilizacion": true,
 "valida": false
}
```

## Variantes

| variante | n | C | ref | D | IC95 14 d | válida | controles ≤18 velas |
|---|---|---|---|---|---|---|---|
| PRIMARIA | 540 | -0.341 % | +0.145 % | -0.486 pp | [-1.473; +0.419] | False | 0.0 % |
| W28 | 923 | +0.198 % | -0.186 % | +0.384 pp | [-0.430; +1.204] | False | 0.0 % |
| AB0 | 3361 | +0.572 % | +0.376 % | +0.196 pp | [-0.172; +0.575] | False | 40.4 % |
| AB1 | 3150 | +0.548 % | -0.005 % | +0.554 pp | [+0.079; +1.055] | False | 0.0 % |
| AB2 | 1036 | -0.113 % | +0.354 % | -0.467 pp | [-1.007; +0.034] | False | 44.6 % |

## Subgrupos (primaria)

| grupo | n | D | IC95 14 d |
|---|---|---|---|
| mitad primera | 323 | -0.102 pp | [-0.89; +0.66] |
| mitad segunda | 217 | -1.058 pp | [-3.20; +0.92] |
| régimen bajista | 64 | -0.592 pp | [-6.02; +3.13] |
| régimen lateral | 365 | -0.373 pp | [-1.60; +0.71] |
| régimen alcista | 111 | -0.797 pp | [-1.95; +0.29] |
| volatilidad baja | 322 | -0.419 pp |  |
| volatilidad media | 89 | -0.123 pp |  |
| volatilidad alta | 129 | -0.904 pp |  |
| año 2022 | 67 | -0.105 pp | [-3.05; +2.20] |
| año 2023 | 217 | +0.045 pp | [-0.72; +0.88] |
| año 2024 | 66 | -0.399 pp | [-2.33; +1.45] |
| año 2025 | 83 | -4.385 pp | [-7.43; -1.04] |
| año 2026 | 107 | +1.167 pp | [-0.58; +3.65] |

## Holm (F2, secundaria)

| prueba | p | p Holm |
|---|---|---|
| mitad_primera | 0.6134 | 1.0000 |
| mitad_segunda | 0.8478 | 1.0000 |
| regimen_bajista | 0.6182 | 1.0000 |
| regimen_lateral | 0.7288 | 1.0000 |
| regimen_alcista | 0.9289 | 1.0000 |
| anio_2022 | 0.5569 | 1.0000 |
| anio_2023 | 0.4582 | 1.0000 |
| anio_2024 | 0.6707 | 1.0000 |
| anio_2025 | 0.9966 | 1.0000 |
| anio_2026 | 0.1159 | 1.0000 |

## Placebo

rechazo 3.5 %, cobertura 97.0 %, media D -0.107 pp, sd 0.397 pp, calibrado: False


## Riesgo (aparte)

```
{
 "portfolio_C_all_signals": {
  "ret": 1.3166053782339615,
  "dd": 0.3495927376376132,
  "exposure": 0.30830927095495747,
  "skipped": 718
 },
 "portfolio_C_included": {
  "ret": -0.2046894309414924,
  "dd": 0.35374162512741036,
  "exposure": 0.06199052565711397,
  "skipped": 33
 },
 "C_included_metrics": {
  "n": 540,
  "win_rate": 0.45185185185185184,
  "avg_win": 0.03849919310508382,
  "avg_loss": -0.03796445154083083,
  "expectancy": -0.003414212108232355,
  "sum": -1.8436745384454718,
  "pf": 0.8359351987278936,
  "worst": -0.26083950373240705,
  "p5": -0.08156076958117328,
  "max_loss_streak": 20
 },
 "ref_metrics_per_draw": {
  "n": {
   "median": 539.0,
   "p5_p95": [
    537.0,
    540.0
   ]
  },
  "win_rate": {
   "median": 0.46111111111111114,
   "p5_p95": [
    0.4349442379182156,
    0.4890726972325485
   ]
  },
  "avg_win": {
   "median": 0.03765003545736725,
   "p5_p95": [
    0.033048781613327104,
    0.04176731609148243
   ]
  },
  "avg_loss": {
   "median": -0.028908762872736523,
   "p5_p95": [
    -0.030947877453251626,
    -0.02698256281769003
   ]
  },
  "expectancy": {
   "median": 0.0018323073053900622,
   "p5_p95": [
    -0.0010968314594137726,
    0.004521151597999026
   ]
  },
  "sum": {
   "median": 0.9857813302998535,
   "p5_p95": [
    -0.5921545755811259,
    2.428088026703104
   ]
  },
  "pf": {
   "median": 1.1168659133012286,
   "p5_p95": [
    0.9315480122252121,
    1.2997471576426856
   ]
  },
  "worst": {
   "median": -0.1902190055545686,
   "p5_p95": [
    -0.25347109670245704,
    -0.1366638866589659
   ]
  },
  "p5": {
   "median": -0.06403694506654088,
   "p5_p95": [
    -0.06992986658993033,
    -0.05854727065905741
   ]
  },
  "max_loss_streak": {
   "median": 14.0,
   "p5_p95": [
    10.0,
    21.0
   ]
  }
 },
 "ref_portfolio_100_draws": {
  "ret": {
   "median": 0.11734220010177177,
   "range": [
    -0.11939071573507443,
    0.3504763680858829
   ]
  },
  "dd": {
   "median": 0.14813945669595535,
   "range": [
    0.07880957515004783,
    0.24804539847630638
   ]
  },
  "exposure": {
   "median": 0.0647651076536283,
   "range": [
    0.0636727401820012,
    0.06546857030602792
   ]
  },
  "skipped": {
   "median": 10.0,
   "range": [
    7.0,
    15.0
   ]
  }
 },
 "dd_limit_E1": 0.15,
 "C_dd_meets_limit": false
}
```