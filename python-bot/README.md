# python-bot — Freqtrade (motor nuevo, solo simulación)

Proyecto independiente de `api/` (Laravel). No comparte base de datos, dependencias ni credenciales.
**Solo `dry_run` y backtesting. Sin claves. No habilitar operaciones reales** (ver `../reglas.md`).

## Instalación (PowerShell, desde `python-bot/`)
```powershell
python -m venv .venv
.\.venv\Scripts\python -m pip install --upgrade pip
.\.venv\Scripts\python -m pip install -r requirements.txt
.\.venv\Scripts\freqtrade create-userdir --userdir user_data   # solo si no existe
```
Requisitos: Python >= 3.11 (probado 3.14.7). TA-Lib se instala desde wheel binario; no requiere compilar.

## Verificación
```powershell
.\.venv\Scripts\freqtrade --version
.\.venv\Scripts\freqtrade list-strategies --userdir user_data -c config.json
.\.venv\Scripts\freqtrade test-pairlist -c config.json --userdir user_data
```

## Datos históricos (API pública de Binance, sin claves)
```powershell
.\.venv\Scripts\freqtrade download-data -c config.json --userdir user_data --timerange 20240101- -t 1h
```
Los `.npy` de `research/` NO son compatibles (Freqtrade usa feather en `user_data/data/binance/`); se descargan datos propios y no se tocan los originales.

## Backtest
```powershell
.\.venv\Scripts\freqtrade backtesting -c config.json --userdir user_data -s ReferenceSmaCross --timerange 20240101- --cache none
```
Costes: `fee` 0.1% por operación en `config.json` (taker spot estándar, explícito). Sin slippage modelado.

## Iniciar / detener dry-run
```powershell
.\.venv\Scripts\freqtrade trade -c config.json --userdir user_data -s ReferenceSmaCross   # iniciar (dry_run=true)
# detener: Ctrl+C en esa terminal
```
Nota: `initial_state` es `stopped`; el bot arranca pausado. Sin API/Telegram configurados no hay forma de pasarlo a `running` salvo cambiar ese valor; hazlo conscientemente. No se ha ejecutado el dry-run en vivo.

## Estrategia inicial
`user_data/strategies/ReferenceSmaCross.py`: cruce SMA 20/50 en 1h, stoploss -5%. Es solo de referencia para validar la infraestructura. Sin parámetros optimizados.

## Resultado de la prueba de infraestructura
Backtest 2024-01-03 → 2026-10-11, BTC/ETH/BNB, 830 trades: -4.71%, drawdown 16.8%. **No es rentable ni candidata**; confirma que el pipeline funciona.

## Limitaciones y próximos pasos
- Un solo backtest no valida nada: usar walk-forward / out-of-sample y comparar con buy&hold (mercado +77.7%).
- Sin slippage ni profundidad de libro; revisar `StaticPairList` vs. pairlists dinámicas.
- Evaluar estrategias según la regla madre de `../reglas.md` (ganancias pequeñas y consistentes, bajo riesgo).
- Para paper trading extendido: definir API server/Telegram sin secretos en git (usar `config.local.json`, ignorado).
