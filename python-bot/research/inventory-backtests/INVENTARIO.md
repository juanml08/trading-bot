# Inventario de backtests (Fase 0) — solo lectura

Generado por `build_inventory.py` (reproducible: `.venv/Scripts/python -I research/inventory-backtests/build_inventory.py`, desde `python-bot/`).
Lee `user_data/backtest_results/*.zip`; no los modifica ni copia. No se ejecutó ningún backtest.
Datos completos: [inventario.csv](inventario.csv) (una fila por resultado, con hashes). Hashes actuales: [strategy_hashes.csv](strategy_hashes.csv), [data_hashes.csv](data_hashes.csv).
Git HEAD al generar: `ef3bd3b19c08a4560d273a148cf4d14cfd94a0a4` (el CSV de estrategias lo repite).

## Recuento
- **56 zip, 56 interpretados completamente** (JSON de resultado, config y snapshot de la estrategia presentes en todos).
- Origen (**inferido** por orden de ejecución y configuración; no consta en los archivos): 21 originales (shortlist, `run_all.sh`) · 34 de auditoría (exposición/capital/posiciones, SupertrendRegime) · 1 posterior (2026-10-13, ReferenceSmaCross, 1 posición, stake 30, wallet 100, 1 operación; no es parte de ninguna investigación documentada).
- Constantes en los 56: 1h, BTC/USDT+ETH/USDT+BNB/USDT, spot, `fee` 0,001 en el config guardado, sin protecciones, `dry_run` true.

## Qué se puede demostrar y qué no
| Aspecto | Estado |
|---|---|
| Versión de la estrategia usada | **Demostrado** para los 56: el zip guarda una copia del `.py` y su SHA-256 coincide con el archivo actual (`IGUAL` ×56). Excepción de alcance: `SupertrendRegime` hereda de `Supertrend`; el zip solo guarda la clase hija, y la coincidencia de `Supertrend.py` se verifica en los zip de `Supertrend`, no dentro de los de Regime. |
| Configuración efectiva | **Parcial.** El `_config.json` del zip es el archivo de config, **sin** las sobrescrituras de línea de comandos. Ej.: los zip de 20 USDT guardan wallet 1000 / stake 100 / 3 posiciones, pero el resultado real usó 20 / 19,8 / 1. Los valores efectivos del CSV vienen del JSON de resultado; las sobrescrituras exactas solo constan en los scripts (`run_all.sh`, `run_phase2.sh`). |
| Comisión | `fee` 0,001 viene del config guardado; el resultado no la repite. Si hubo `--fee` por CLI no queda registrado (los scripts no lo usan). |
| Versión de Freqtrade | **No registrada** en ningún zip ni meta. Solo se sabe que hoy está instalada la 2026.9 (`requirements.txt`). |
| Datos de mercado | **No demostrable.** No hay hash de los `.feather` usados. `data_hashes.csv` es el estado **actual**; los datos se descargaron antes y podrían haberse ampliado después (el JSON de resultado guarda el rango de fechas, no el contenido). |
| Semilla / determinismo | Backtest determinista dado datos y config; no verificado re-ejecutando (prohibido en esta fase). |

## Duplicados e inconsistencias
- **Duplicados exactos (mismo hash de trades y mismos parámetros):** #4 (`21-20-31`) = #1 (`21-08-05`), ReferenceSmaCross FULL; #23 (`21-43-28`) = #8 (`21-21-30`), Supertrend OOS. Cuentan como una sola evidencia.
- **Ventanas distintas para la misma etiqueta:** el inicio efectivo difiere por warm-up (Supertrend 2024-01-09; SupertrendRegime 2024-02-08; ReferenceSmaCross 2024-01-03; BbandRsi/ADX 2024-01-01; el barrido de la auditoría parte de 2024-02-01). Sus "FULL" no cubren exactamente el mismo periodo.
- **Grupos de configuración** (columna `grupo_config`): solo son comparables entre estrategias los resultados del mismo grupo y ventana. 17 de los 56 son `cap1000|stake100|pos3` (la comparación válida de la shortlist); los de capital 100 forman un barrido de **exposición** (stake 99/49,5/33/16,5/11 × 1–3 posiciones, 5 estrategias), no de estrategia; los 8 de capital 20 no son comparables con nada (dimensionamiento y señales rechazadas distintas).
- **Resultados con 1 posición**: dependen de qué señal ocupa el hueco (columna `senales_rechazadas`); varios tienen 1–2 operaciones (stake 99 con wallet 100 se bloquea tras la primera pérdida). No son evidencia de la estrategia.
- **Resultado #56** (2026-10-13): ventana 2026-10-10→13, 1 operación; no interpretable estadísticamente.
- El README antiguo y la tarea hablaban de "21 resultados": son solo los originales; hay 56.

## Limitaciones de este inventario
- La asignación de origen (original/auditoría) es una inferencia; verificada contra los scripts y los informes, pero no escrita en los zip.
- No se comprobó que los `.txt` de `out/` correspondan a cada zip (no hay vínculo explícito); se ignoran aquí.
- No se verificó la integridad de los `.feather` internos de cada zip.
