# Shortlist: 3 estrategias existentes (Freqtrade)

Fuente común: repositorio **freqtrade/freqtrade-strategies** — https://github.com/freqtrade/freqtrade-strategies
Licencia: **GPL-3.0** (verificada vía API de GitHub). Último push: 2026-09-08. El README del repo advierte que las estrategias son puntos de partida, no garantía de rentabilidad, y exige backtest + dry-run antes de arriesgar capital.
Condición de uso GPL-3.0: uso interno/privado sin problema; si se redistribuye código derivado, debe mantenerse GPL-3.0. Los archivos se copiaron **sin modificar** (descargados con curl desde `main`, revisados antes de usarlos; solo importan freqtrade, pandas, talib, qtpylib y `technical`).

Selección: una por familia (tendencia / reversión / momentum), diseñadas para spot long-only en 1h, sin futuros, apalancamiento ni shorting. No se eligieron por rentabilidad publicada (ninguna incluye resultados verificables del autor).

| | Supertrend | BbandRsi | ADXMomentum |
|---|---|---|---|
| Familia | Tendencia | Reversión a la media | Momentum / fuerza de tendencia |
| Autor | Juan Carlos Soriano (@juankysoriano) | Gert Wohlgemuth (@berlinguyinca), portada de Mynt (sthewissen/Mynt, `BbandRsi.cs`) | Gert Wohlgemuth, portada de Mynt (`AdxMomentum.cs`) |
| Fuente | [Supertrend.py](https://github.com/freqtrade/freqtrade-strategies/blob/main/user_data/strategies/Supertrend.py) | [BbandRsi.py](https://github.com/freqtrade/freqtrade-strategies/blob/main/user_data/strategies/berlinguyinca/BbandRsi.py) | [ADXMomentum.py](https://github.com/freqtrade/freqtrade-strategies/blob/main/user_data/strategies/berlinguyinca/ADXMomentum.py) |
| Entrada | 3 Supertrend (mult/periodo 4/8, 7/9, 1/8) todos "up" | RSI(14) < 30 y close < banda inferior BB(20,2) | ADX(14) > 25, MOM(14) > 0, +DI(25) > 25 y +DI > −DI |
| Salida | 3 Supertrend (1/16, 3/18, 6/18) todos "down"; ROI 8.7%→0 escalonado; stoploss −26.5%; trailing | RSI > 70; ROI 10%; stoploss −25% | ADX>25, MOM<0, −DI>25, +DI<−DI; ROI 1%; stoploss −25% |
| Timeframe | 1h | 1h | 1h |
| Diseñada para Freqtrade | Sí (INTERFACE_VERSION 3) | Sí (v3) | Sí (v3) |
| Futuros/shorts | No | No | No |
| Dependencias | `technical` (ya instalado, 1.7.0) | ninguna extra | ninguna extra |
| Adaptación | **Ninguna** | **Ninguna** | **Ninguna** |

## Riesgos y limitaciones conocidas
- **Supertrend**: el propio autor avisa de que su implementación del indicador no está validada contra la referencia original. Los parámetros/ROI/stoploss vienen de un hyperopt del autor (comentario en el código: timerange 20210101-); sobreajuste potencial sobre datos que no controlamos. Stoploss de −26.5% es incompatible con "preservar capital".
- **BbandRsi**: stoploss −25% (colas grandes); pocas señales; sin `startup_candle_count` (el análisis recursivo de Freqtrade se niega a ejecutarse).
- **ADXMomentum**: ROI 1% fijo con stoploss −25%: perfil "muchas ganancias pequeñas, pocas pérdidas grandes". Con 0.2% de comisión ida y vuelta, el 1% bruto deja ~0.8%.

## Evidencia independiente
No encontré backtests independientes verificables de estas tres en las fuentes consultadas (solo el README del repo, que no afirma rentabilidad). Todo resultado en `RESULTS.md` es **nuestro**; no hay afirmaciones de autor que contrastar.
