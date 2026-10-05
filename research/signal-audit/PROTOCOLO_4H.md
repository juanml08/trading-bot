# Protocolo pre-registrado — experimento 4h (escrito ANTES de descargar datos y de ver cualquier resultado)

Fecha: 2026-10-05. Estudio offline, read-only: no toca código del bot ni BD. Scripts en esta carpeta (`fetch_data_4h.py`, `audit_4h.py`, `report_4h.py`); los scripts de 1h no se modifican.

**Decisión de reutilización:** se reutiliza el [PROTOCOLO.md](PROTOCOLO.md) de 1h *íntegro* (familias, compuertas G1–G7, clasificación, referencias A/B/C, costos, enmiendas 1–3 ya incorporadas desde el inicio). Este documento solo lista (1) las diferencias inevitables por pasar a 4h, (2) la política de TEST y (3) las pocas adiciones pedidas. Todo lo no mencionado aquí es idéntico a 1h.

## 1. Datos
- Velas **4h** spot de Binance (API pública `data-api.binance.vision`, sin autenticación), mismos 20 pares USDT: BTC ETH BNB XRP SOL ADA DOGE TRX LINK LTC AVAX DOT ETC BCH ATOM NEAR XLM UNI AAVE IOTA.
- Período principal: 2023-10-01 → 2026-10-01 00:00 UTC (igual que 1h). Se descarga además desde 2022-01-01 (ver §3: calentamiento de indicadores y TEST-PRE).
- Solo velas cerradas (el rango termina en una frontera de vela ya pasada). Limpieza: igual que 1h (los 20 activos deben compartir exactamente el mismo índice temporal; si no, se documenta y se intersecta).
- Sesgo de supervivencia: misma limitación (pares vigentes hoy). No se intenta corregir.

## 2. Diferencias metodológicas inevitables 1h → 4h (fijadas antes de ver resultados)
Regla única: **todo parámetro expresado en número de velas conserva su número de velas** (es lo que ejecutaría el mismo código del bot con velas de 4h): N de las cinco familias y sus grillas, SMA168 y pendiente de 24 velas (A), media de TR, ROC, SMA(N) de BTC, ventana de volatilidad de BTC (24), mediana (720), media de volumen (24), umbrales RSI/2×/1,5×. Solo se convierte lo que es calendario o lo que protege contra solapamiento:

| Elemento | 1h | 4h | Razón |
|---|---|---|---|
| Horizontes | 1,2,4,8,16,24 h | 4,8,16,24,48,72 h = 1,2,4,6,12,18 velas | pedido del enunciado; la señal se observa al cierre de t, entrada open[t+1], salida close[t+h velas]; mantener h velas |
| Horizonte primario | 8h | **8h (2 velas)** | misma duración que 1h ⇒ comparación directa y mismos umbrales G3 |
| Cooldown por activo | 24 velas (=horizonte máx.) | **18 velas (=72h=horizonte máx.)** | misma regla: cooldown = horizonte máximo, sin solapamiento |
| Embargo entre tramos | 24 velas | **18 velas** | igual: = horizonte máximo |
| Velas futuras mínimas por barra elegible | 24 | **18** | igual |
| Historia mínima | 800 velas | 800 velas (las barras elegibles *empiezan* en el mismo instante calendario que en 1h, 2023-11-03 08:00 UTC; el calentamiento sale de datos previos) | misma ventana calendario |
| Régimen de mercado para desgloses | retorno BTC 720h | retorno BTC 30 días = 180 velas | es calendario (30 días), no parámetro de señal |
| Barras por día | 24 | 6 | frecuencias |
| MAE/MFE | sobre mínimos/máximos 1h | sobre mínimos/máximos 4h (más gruesos) | inevitable; subestima la excursión intravela |

Consecuencias que se asumen y se declaran: (a) ≈4× menos barras ⇒ muchas menos señales ⇒ menos potencia estadística; (b) las condiciones n≥10 por trimestre/activo de G4/G5 se mantienen sin cambios y pueden dejar fuera celdas; (c) los parámetros de bar-count representan ventanas temporales 4× más largas (p. ej. ruptura de 24 velas = 4 días): es una hipótesis distinta de «la ruptura de 24h», y es lo que se evalúa a propósito; (d) los umbrales absolutos de G3 (p5 ≥ −4%, peor ≥ −15%, DD ≤ 20%) no se escalan.

No se agrega ninguna familia, filtro ni parámetro nuevo. Las grillas son las de 1h: A {10,12,14,16,18} prim. 14; B,C,D {18,21,24,27,30} prim. 24; E {160,180,200,220,240} prim. 200 (más «régimen solo»).

## 3. Separación temporal y política de TEST (punto crítico)
- Ventana elegible principal = la de 1h en calendario: desde 2023-11-03 08:00 UTC hasta la última barra con ≥18 velas futuras. Split 70/15/15 por número de barras elegibles, con embargo de 18 velas ⇒ TRAIN / VAL / TEST-final. Fechas exactas en REPORTE_DATOS_4H.md (se calculan solo con el calendario, sin mirar retornos).
- **Contaminación declarada:** el TEST-final de 4h cae (salvo ≈2 días) en el mismo calendario que el TEST de 1h, que ya fue abierto, y el TRAIN+VAL de 1h ya fue examinado (incluidas tres enmiendas posteriores a ver desarrollo). **No existe en la ventana 2023-10→2026-10 ningún segmento virgen**: todos sus precios ya influyeron en la elección de la hipótesis «probar 4h» (el informe 1h lo recomendó tras ver el TEST). Los datos posteriores al 2026-10-01 son 4 días (≈24 velas por activo): inservibles.
- **Mitigación pre-registrada: TEST-PRE.** Un segundo TEST sobre un período que el experimento de 1h nunca usó: **2022-10-01 → 2023-09-30** (la barra elegible es la que tenga ≥18 velas futuras dentro de la descarga; calentamiento desde 2022-01-01). Es virgen para estas hipótesis. Es anterior en el tiempo al TRAIN y su resultado no se usa en ningún momento antes del final; el dev nunca lo lee.
- TEST-final y TEST-PRE se abren **una sola vez, juntos**, con `frozen.json` ya escrito. Etapa `dev` no carga ninguna vela posterior al fin de VALIDATION ni anterior a 2023-04-01.
- Regla de uso: TEST-final contaminado **solo puede descartar, no confirmar**; la confirmación exige además TEST-PRE (G8b).

## 4. Referencias, costos, slippage
- Referencias A (cualquier vela), B (mercado equiponderado), C (aleatoria emparejada activo×mes×tramo, exacta, decisión) — idénticas a 1h. TEST-PRE usa su propio tramo en la agrupación.
- Costo: 0,1% por lado, 0,2% round trip. Bruto = sin comisión; neto = bruto − 0,20%; se separan siempre. **El resultado neto utiliza comisión, pero no modela slippage** (igual que 1h).

## 5. Métricas
Las de 1h (retorno medio/mediana bruto y neto, PF, win rate, p5/p10, peor operación, pérdida media/mediana, MAE/MFE, DD con 1/max(10, señales del día) del capital, % días positivos, peor día, racha de días negativos, por activo, trimestre, régimen, robustez) **más** (pedido): % de semanas positivas (con señal), peor semana, racha perdedora máxima en operaciones consecutivas por orden temporal, y señales por activo por mes. Todo a 8h; retornos y excesos a los 6 horizontes.

## 6. Compuertas y clasificación
G1–G7 idénticas a 1h (sobre TRAIN+VAL, 8h, primario salvo G7; G2 con neto/PF/≥3 de 5 celdas con neto>0; G3 con DD bruto). Fuera de muestra, antes G8 único; ahora:
- **G8a (TEST-final):** exceso C bruto >0 y neto medio >0 a 8h.
- **G8b (TEST-PRE, virgen):** exceso C bruto >0 y neto medio >0 a 8h.

Clasificación en este orden: C si falla G1; E si falla G3; D si falla G4, G5 o G7, o si el exceso bruto en TEST-final **o** TEST-PRE es ≤0; B si pasa lo anterior pero falla G2, G6 o el neto de algún TEST es ≤0; A si pasa todo. Una familia clasificada no se modifica ni re-parametriza. Se reportan los 6 horizontes completos en todos los tramos; el horizonte nunca se elige después de ver un TEST.

**Criterio de la conclusión SÍ/NO:** SÍ solo si alguna familia es A. Aun así, como una parte de la evidencia está contaminada, SÍ significaría únicamente «pasar a validación forward (paper/datos posteriores a 2026-10-01)», nunca implementación directa con dinero real.

## 7. Registro de cambios metodológicos
**Ningún cambio metodológico** respecto de este texto (2026-10-05). Ni reglas, parámetros, horizontes, costos, métricas ni umbrales se modificaron después de ver resultados de desarrollo ni de TEST. `report_4h.py final` verifica con un `assert` que la clasificación pre-TEST recalculada coincide con `frozen_4h.json` (escrito antes de abrir TEST-final y TEST-PRE).
Aclaración operativa (no metodológica): la primera ejecución de `report_4h.py gates` falló al imprimir «≤» en la consola de Windows (cp1252) *después* de escribir `frozen_4h.json`; se repitió con salida UTF-8 sin cambios de lógica.
Las métricas adicionales del §5 (semanas positivas, racha por operaciones, señales/mes por activo) se incorporaron en `audit_4h.py` antes de la primera ejecución.
