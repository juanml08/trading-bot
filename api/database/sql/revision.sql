-- =============================================================================
-- KIT OFICIAL DE REVISION DEL TRADING BOT
-- Motor: MySQL. Solo consultas de lectura (SELECT). No modifica datos.
--
-- Nota: "evaluacion" = una fila de automatic_search_cycle_strategies
-- (una estrategia evaluada sobre un activo dentro de un ciclo de busqueda).
-- El activo y el cycle_id salen de automatic_search_cycle_assets
-- (symbol, automatic_search_cycle_id).
-- =============================================================================


-- =============================================================================
-- 1. Resumen general de Discovery y Validation
-- =============================================================================
SELECT
    COUNT(*) AS total_evaluaciones,
    COALESCE(SUM(discovery_passed = 1), 0) AS discovery_aprobadas,
    COALESCE(SUM(discovery_passed = 0), 0) AS discovery_rechazadas,
    COALESCE(SUM(validation_passed = 1), 0) AS validation_aprobadas,
    COALESCE(SUM(validation_passed = 0), 0) AS validation_rechazadas
FROM automatic_search_cycle_strategies;


-- =============================================================================
-- 2. Criterios que estan bloqueando Validation
-- =============================================================================
SELECT
    strategy_name,
    failed_criteria,
    COUNT(*) AS cantidad_casos
FROM automatic_search_cycle_strategies
WHERE validation_passed = 0
GROUP BY strategy_name, failed_criteria
ORDER BY cantidad_casos DESC;


-- =============================================================================
-- 3. Casos que casi pasan Validation (Discovery aprobada, Validation rechazada)
-- =============================================================================
SELECT
    a.automatic_search_cycle_id AS cycle_id,
    a.symbol AS asset,
    s.strategy_name,
    s.train_total_trades,
    s.train_win_rate,
    s.train_profit_loss_percentage,
    s.train_max_drawdown_percentage,
    s.train_profit_factor,
    s.validation_total_trades,
    s.validation_win_rate,
    s.validation_profit_loss_percentage,
    s.validation_max_drawdown_percentage,
    s.validation_profit_factor,
    s.failed_criteria
FROM automatic_search_cycle_strategies AS s
INNER JOIN automatic_search_cycle_assets AS a ON a.id = s.automatic_search_cycle_asset_id
WHERE s.discovery_passed = 1
  AND s.validation_passed = 0
ORDER BY s.validation_profit_loss_percentage DESC
LIMIT 50;


-- =============================================================================
-- 4. Promedios de Validation por estrategia (solo Discovery aprobadas)
-- =============================================================================
SELECT
    strategy_name,
    COUNT(*) AS discovery_aprobadas,
    AVG(validation_total_trades) AS promedio_trades,
    AVG(validation_win_rate) AS promedio_win_rate,
    AVG(validation_profit_loss_percentage) AS promedio_profit_loss_percentage,
    AVG(validation_max_drawdown_percentage) AS promedio_max_drawdown_percentage,
    -- validation_profit_factor es string (puede no ser numerico, p. ej. "INF"); se ignoran esos valores
    AVG(CASE WHEN validation_profit_factor REGEXP '^[0-9]+(\\.[0-9]+)?$'
        THEN CAST(validation_profit_factor AS DECIMAL(28, 8)) END) AS promedio_profit_factor
FROM automatic_search_cycle_strategies
WHERE discovery_passed = 1
GROUP BY strategy_name
ORDER BY strategy_name;


-- =============================================================================
-- 5. Resultado por activo
-- =============================================================================
SELECT
    a.symbol AS asset,
    COUNT(*) AS evaluaciones,
    COALESCE(SUM(s.discovery_passed = 1), 0) AS discovery_aprobadas,
    COALESCE(SUM(s.validation_passed = 1), 0) AS validation_aprobadas
FROM automatic_search_cycle_strategies AS s
INNER JOIN automatic_search_cycle_assets AS a ON a.id = s.automatic_search_cycle_asset_id
GROUP BY a.symbol
ORDER BY validation_aprobadas DESC, discovery_aprobadas DESC, evaluaciones DESC;


-- =============================================================================
-- 6. Errores (bot_events con error / timeout / timed out / cURL)
-- =============================================================================
SELECT
    id,
    created_at,
    event_type,
    asset,
    message
FROM bot_events
WHERE event_type LIKE '%error%'
   OR event_type LIKE '%timeout%'
   OR message LIKE '%error%'
   OR message LIKE '%timeout%'
   OR message LIKE '%timed out%'
   OR message LIKE '%cURL%'
ORDER BY created_at DESC, id DESC;


-- =============================================================================
-- 7. Ciclos de busqueda incompletos
-- =============================================================================
SELECT
    id,
    started_at,
    completed_at,
    assets_reviewed,
    candidates_found
FROM automatic_search_cycles
WHERE completed_at IS NULL
ORDER BY id DESC;


-- =============================================================================
-- 8. Estrategias activas
-- =============================================================================
SELECT
    ast.id,
    ast.symbol AS asset,
    ast.timeframe,
    ast.strategy_id,
    st.name AS strategy_name,
    ast.status,
    ast.started_at,
    ast.last_evaluated_at
FROM active_strategies AS ast
LEFT JOIN strategies AS st ON st.id = ast.strategy_id
ORDER BY ast.id DESC;


-- =============================================================================
-- 9. Ciclos de trading activos/historicos
-- =============================================================================
SELECT
    atc.id,
    ast.symbol AS asset_symbol,
    atc.state,
    atc.started_at,
    atc.expires_at,
    atc.created_at,
    atc.updated_at,
    atc.asset_id,
    atc.strategy_id,
    st.name AS strategy_name,
    atc.active_strategy_id
FROM active_trading_cycles AS atc
LEFT JOIN assets AS ast ON ast.id = atc.asset_id
LEFT JOIN strategies AS st ON st.id = atc.strategy_id
ORDER BY atc.id DESC;


-- =============================================================================
-- 10. Ultimos eventos del bot
-- =============================================================================
SELECT
    created_at,
    event_type,
    asset,
    message
FROM bot_events
ORDER BY created_at DESC, id DESC
LIMIT 50;


-- =============================================================================
-- 11. Trades
-- =============================================================================
SELECT
    t.id,
    ast.symbol AS asset,
    st.name AS strategy_name,
    t.status,
    t.entry_price,
    t.exit_price,
    t.quantity,
    t.capital_used,
    t.stop_loss,
    t.take_profit,
    t.profit_loss,
    t.profit_loss_percent,
    t.opened_at,
    t.closed_at,
    t.created_at
FROM trades AS t
LEFT JOIN assets AS ast ON ast.id = t.asset_id
LEFT JOIN strategies AS st ON st.id = t.strategy_id
ORDER BY t.id DESC;


-- =============================================================================
-- 12. Orders
-- =============================================================================
SELECT
    o.id,
    o.trade_id,
    ast.symbol AS asset,
    o.exchange_order_id,
    o.type,
    o.side,
    o.status,
    o.quantity,
    o.price,
    o.created_at,
    o.executed_at
FROM orders AS o
LEFT JOIN trades AS t ON t.id = o.trade_id
LEFT JOIN assets AS ast ON ast.id = t.asset_id
ORDER BY o.id DESC;


-- =============================================================================
-- 13. Estado de Automatic Search
-- La tabla no tiene columna de retry; si el bot guarda datos de reintento,
-- estarian dentro del JSON last_cycle.
-- =============================================================================
SELECT
    id,
    account_id,
    status,
    symbol,
    timeframe,
    mode,
    capital,
    last_searched_at,
    next_search_at,
    last_cycle,
    created_at,
    updated_at
FROM automatic_search_states
ORDER BY id DESC;


-- =============================================================================
-- 14. Analisis completo del experimento "400 estrategias / 15 slots / 15m"
-- (2026-09-28). Consultas usadas para el analisis pedido por el usuario:
-- periodo real del experimento, actividad, P/L, motivos de cierre, impacto
-- del timeframe, diversidad de estrategias, uso de slots, concentracion por
-- activo, cadenas de reentrada y anomalias de implementacion.
-- =============================================================================

-- 14.1 Ventana real del experimento (primer y ultimo evento/ciclo)
SELECT MIN(created_at) AS min_at, MAX(created_at) AS max_at, COUNT(*) AS n FROM bot_events;
SELECT MIN(created_at) AS min_at, MAX(created_at) AS max_at, COUNT(*) AS n FROM active_trading_cycles;
SELECT MIN(created_at) AS min_at, MAX(created_at) AS max_at, COUNT(*) AS n FROM trades;
SELECT MIN(created_at) AS min_at, MAX(created_at) AS max_at, COUNT(*) AS n FROM automatic_search_cycles;
SELECT * FROM automatic_search_states;

-- 14.2 Trades y ordenes completos (P/L real, no el del dashboard)
SELECT t.id, a.symbol AS asset, st.name AS strategy_name, t.status, t.entry_price, t.exit_price,
       t.profit_loss, t.profit_loss_percent, t.opened_at, t.closed_at,
       TIMESTAMPDIFF(MINUTE, t.opened_at, COALESCE(t.closed_at, NOW())) AS minutos
FROM trades t
LEFT JOIN assets a ON a.id = t.asset_id
LEFT JOIN strategies st ON st.id = t.strategy_id
ORDER BY t.id;

-- 14.3 Ciclos de trading (estado, expiracion, estrategia)
SELECT atc.id, a.symbol AS asset, atc.state, atc.started_at, atc.expires_at,
       st.name AS strategy_name, atc.active_strategy_id
FROM active_trading_cycles atc
LEFT JOIN assets a ON a.id = atc.asset_id
LEFT JOIN strategies st ON st.id = atc.strategy_id
ORDER BY atc.id;

-- 14.4 Conteo de eventos por tipo (BUY/SELL/HOLD, ignorados, stop loss, errores)
SELECT event_type, COUNT(*) AS n FROM bot_events GROUP BY event_type ORDER BY n DESC;

-- 14.5 Motivo de cada cierre de posicion (SELL natural vs risk_stop_loss vs risk_time_exit)
SELECT id, created_at, asset, message, data FROM bot_events WHERE event_type = 'position_closed' ORDER BY created_at;
SELECT id, created_at, asset, message, data FROM bot_events WHERE event_type = 'risk_stop_loss' ORDER BY created_at;
SELECT id, created_at, asset, message, data FROM bot_events WHERE event_type = 'risk_time_exit' ORDER BY created_at;

-- 14.6 BUY ignorados por posicion ya abierta (ruido del timeframe/exceso de señales)
SELECT asset, COUNT(*) AS n FROM bot_events WHERE event_type = 'signal_ignored_position_open' GROUP BY asset ORDER BY n DESC;
SELECT id, created_at, asset, message FROM bot_events WHERE event_type = 'signal_ignored_no_open_position' ORDER BY created_at;

-- 14.7 Universo de activos escaneado por Discover y estrategias evaluadas por scan
SELECT assets_reviewed, COUNT(*) AS n FROM automatic_search_cycles GROUP BY assets_reviewed ORDER BY n DESC;
SELECT COUNT(DISTINCT symbol) AS distinct_symbols_scanned FROM automatic_search_cycle_assets;
SELECT id, created_at, asset, message FROM bot_events WHERE event_type = 'strategies_evaluated' ORDER BY created_at LIMIT 5;

-- 14.8 Estrategias realmente desplegadas (no solo evaluadas) y su P/L
SELECT atc.strategy_id, st.name AS strategy_name, COUNT(*) AS cycles_count
FROM active_trading_cycles atc LEFT JOIN strategies st ON st.id = atc.strategy_id
GROUP BY atc.strategy_id, st.name ORDER BY cycles_count DESC;

SELECT t.strategy_id, st.name AS strategy_name, COUNT(*) AS trades,
       SUM(t.status = 'closed') AS closed_trades,
       SUM(CASE WHEN t.status = 'closed' AND t.profit_loss > 0 THEN 1 ELSE 0 END) AS wins,
       SUM(CASE WHEN t.status = 'closed' AND t.profit_loss <= 0 THEN 1 ELSE 0 END) AS losses,
       SUM(CASE WHEN t.status = 'closed' THEN t.profit_loss ELSE 0 END) AS pl_sum
FROM trades t LEFT JOIN strategies st ON st.id = t.strategy_id
GROUP BY t.strategy_id, st.name ORDER BY pl_sum ASC;

-- 14.9 Concentracion por activo
SELECT a.symbol, COUNT(*) AS trades,
       SUM(t.status = 'closed') AS closed_trades,
       SUM(CASE WHEN t.status = 'closed' THEN t.profit_loss ELSE 0 END) AS pl_sum
FROM trades t LEFT JOIN assets a ON a.id = t.asset_id
GROUP BY a.symbol ORDER BY pl_sum ASC;

-- 14.10 Anomalias de implementacion: trades/ciclos huerfanos, ordenes duplicadas,
-- operaciones que excedan el max_holding_hours (6h) sin cierre, HOLD vencidos sin expirar
SELECT t.id AS trade_id, a.symbol, t.strategy_id, t.status, t.opened_at
FROM trades t
LEFT JOIN assets a ON a.id = t.asset_id
LEFT JOIN active_trading_cycles atc ON atc.asset_id = t.asset_id AND atc.strategy_id = t.strategy_id AND atc.state = 'POSITION_OPEN'
WHERE t.status = 'open' AND atc.id IS NULL;

SELECT o.trade_id, COUNT(CASE WHEN o.side = 'buy' THEN 1 END) AS buys, COUNT(CASE WHEN o.side = 'sell' THEN 1 END) AS sells
FROM orders o GROUP BY o.trade_id
HAVING sells > buys OR buys > 1 OR sells > 1;

SELECT id, opened_at, closed_at, TIMESTAMPDIFF(MINUTE, opened_at, closed_at) AS minutos
FROM trades WHERE status = 'closed' AND TIMESTAMPDIFF(HOUR, opened_at, closed_at) >= 6;

SELECT id, opened_at, TIMESTAMPDIFF(MINUTE, opened_at, NOW()) AS minutos_abierto
FROM trades WHERE status = 'open' AND TIMESTAMPDIFF(HOUR, opened_at, NOW()) >= 6;

SELECT id, state, started_at, expires_at FROM active_trading_cycles WHERE state = 'HOLD' AND expires_at <= NOW();

SELECT ast.id AS active_strategy_id, ast.symbol, ast.status AS strategy_status, atc.id AS cycle_id, atc.state AS cycle_state
FROM active_strategies ast
LEFT JOIN active_trading_cycles atc ON atc.active_strategy_id = ast.id
WHERE ast.status = 'running' AND atc.state IN ('CLOSED', 'EXPIRED');


-- =============================================================================
-- 15. Segundo corte del experimento "400 estrategias / 15 slots / 15m / hold 6h /
-- max holding 6h / stop loss 2% / lookback 10d" (auditoria del 2026-09-28).
-- Solo SELECT, sin efectos secundarios. Consultas nuevas, no se borran las
-- anteriores. Se apoyan en bot_events.active_trading_cycle_id (fase 4) y en
-- el JSON data->trade_id de risk_stop_loss / risk_time_exit / position_closed
-- para clasificar el motivo real de cada cierre.
-- =============================================================================

-- 15.1 Periodo exacto analizado (primer y ultimo evento, duracion)
SELECT
    MIN(created_at) AS primer_evento,
    MAX(created_at) AS ultimo_evento,
    TIMESTAMPDIFF(HOUR, MIN(created_at), MAX(created_at)) AS horas_totales,
    TIMESTAMPDIFF(MINUTE, MIN(created_at), MAX(created_at)) AS minutos_totales
FROM bot_events;

-- 15.2 Ciclos creados y distribucion de estados actuales
SELECT state, COUNT(*) AS n FROM active_trading_cycles GROUP BY state ORDER BY n DESC;
SELECT MIN(created_at) AS primer_ciclo, MAX(created_at) AS ultimo_ciclo, COUNT(*) AS total_ciclos FROM active_trading_cycles;

-- 15.3 BUY / SELL reales ejecutados (no señales ignoradas) via ordenes
SELECT side, COUNT(*) AS n FROM orders GROUP BY side ORDER BY n DESC;

-- 15.4 Ocupacion de slots: cuantos ciclos no-terminales (HOLD/POSITION_OPEN)
-- habia abiertos en cada instante en que se crea o cierra un ciclo (proxy de
-- ocupacion simultanea a partir de started_at / closed equivalente).
-- Usamos started_at como apertura de slot y, para el cierre del slot,
-- updated_at del ciclo cuando su estado pasa a CLOSED/EXPIRED.
SELECT
    atc.id AS cycle_id,
    atc.state,
    atc.started_at,
    CASE WHEN atc.state IN ('CLOSED', 'EXPIRED') THEN atc.updated_at ELSE NULL END AS slot_liberado_en,
    (
        SELECT COUNT(*) FROM active_trading_cycles atc2
        WHERE atc2.started_at <= atc.started_at
          AND (atc2.state NOT IN ('CLOSED', 'EXPIRED') OR atc2.updated_at > atc.started_at)
    ) AS slots_ocupados_al_abrir
FROM active_trading_cycles atc
ORDER BY atc.started_at;

-- 15.4b Ocupacion maxima y promedio aproximada (a partir de la consulta anterior)
SELECT
    MAX(slots_ocupados_al_abrir) AS ocupacion_maxima,
    AVG(slots_ocupados_al_abrir) AS ocupacion_promedio,
    SUM(CASE WHEN slots_ocupados_al_abrir >= 15 THEN 1 ELSE 0 END) AS veces_en_limite_15,
    SUM(CASE WHEN slots_ocupados_al_abrir >= 13 THEN 1 ELSE 0 END) AS veces_cerca_13_o_mas
FROM (
    SELECT
        atc.id,
        (
            SELECT COUNT(*) FROM active_trading_cycles atc2
            WHERE atc2.started_at <= atc.started_at
              AND (atc2.state NOT IN ('CLOSED', 'EXPIRED') OR atc2.updated_at > atc.started_at)
        ) AS slots_ocupados_al_abrir
    FROM active_trading_cycles atc
) sub;

-- 15.5 Resultado financiero global (closed trades)
SELECT
    COUNT(*) AS trades_cerrados,
    SUM(CASE WHEN profit_loss > 0 THEN 1 ELSE 0 END) AS ganadoras,
    SUM(CASE WHEN profit_loss <= 0 THEN 1 ELSE 0 END) AS perdedoras,
    ROUND(SUM(CASE WHEN profit_loss > 0 THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) AS win_rate_pct,
    SUM(profit_loss) AS pl_total,
    AVG(CASE WHEN profit_loss > 0 THEN profit_loss END) AS ganancia_promedio,
    AVG(CASE WHEN profit_loss <= 0 THEN profit_loss END) AS perdida_promedio,
    SUM(CASE WHEN profit_loss > 0 THEN profit_loss ELSE 0 END) AS suma_ganancias,
    SUM(CASE WHEN profit_loss <= 0 THEN ABS(profit_loss) ELSE 0 END) AS suma_perdidas,
    (SUM(CASE WHEN profit_loss > 0 THEN profit_loss ELSE 0 END)
        / NULLIF(SUM(CASE WHEN profit_loss <= 0 THEN ABS(profit_loss) ELSE 0 END), 0)) AS profit_factor,
    MAX(profit_loss) AS mejor_operacion,
    MIN(profit_loss) AS peor_operacion
FROM trades
WHERE status = 'closed';

-- 15.6 Drawdown aproximado sobre la curva de equity (P/L acumulado en orden de cierre)
SELECT
    e.id,
    e.closed_at,
    e.profit_loss,
    e.equity_acumulado,
    MAX(e.equity_acumulado) OVER (ORDER BY e.closed_at, e.id) AS pico_acumulado,
    e.equity_acumulado - MAX(e.equity_acumulado) OVER (ORDER BY e.closed_at, e.id) AS drawdown
FROM (
    SELECT t.id, t.closed_at, t.profit_loss,
        SUM(t.profit_loss) OVER (ORDER BY t.closed_at, t.id) AS equity_acumulado
    FROM trades t
    WHERE t.status = 'closed'
) e
ORDER BY e.closed_at, e.id;

-- 15.7 Clasificacion del motivo de cada cierre (natural / stop loss / time exit)
-- via el trade_id que cada evento guarda en su JSON `data`.
SELECT
    t.id AS trade_id,
    a.symbol AS asset,
    t.opened_at,
    t.closed_at,
    TIMESTAMPDIFF(MINUTE, t.opened_at, t.closed_at) AS minutos_duracion,
    t.profit_loss,
    CASE
        WHEN sl.id IS NOT NULL THEN 'stop_loss'
        WHEN te.id IS NOT NULL THEN 'risk_time_exit'
        ELSE 'sell_natural'
    END AS motivo_salida
FROM trades t
LEFT JOIN assets a ON a.id = t.asset_id
LEFT JOIN bot_events sl ON sl.event_type = 'risk_stop_loss' AND sl.data->>'$.trade_id' = t.id
LEFT JOIN bot_events te ON te.event_type = 'risk_time_exit' AND te.data->>'$.trade_id' = t.id
WHERE t.status = 'closed'
ORDER BY t.closed_at;

-- 15.8 Resumen de motivos de salida (conteo, % y duracion por tipo)
SELECT
    motivo_salida,
    COUNT(*) AS n,
    ROUND(COUNT(*) / SUM(COUNT(*)) OVER () * 100, 2) AS porcentaje,
    AVG(minutos_duracion) AS duracion_promedio_min,
    MIN(minutos_duracion) AS duracion_min_min,
    MAX(minutos_duracion) AS duracion_max_min
FROM (
    SELECT
        t.id,
        TIMESTAMPDIFF(MINUTE, t.opened_at, t.closed_at) AS minutos_duracion,
        CASE
            WHEN sl.id IS NOT NULL THEN 'stop_loss'
            WHEN te.id IS NOT NULL THEN 'risk_time_exit'
            ELSE 'sell_natural'
        END AS motivo_salida
    FROM trades t
    LEFT JOIN bot_events sl ON sl.event_type = 'risk_stop_loss' AND sl.data->>'$.trade_id' = t.id
    LEFT JOIN bot_events te ON te.event_type = 'risk_time_exit' AND te.data->>'$.trade_id' = t.id
    WHERE t.status = 'closed'
) x
GROUP BY motivo_salida;

-- 15.8b Duracion mediana global de las operaciones cerradas (percentil 50)
SELECT
    minutos_duracion AS duracion_mediana_min
FROM (
    SELECT
        TIMESTAMPDIFF(MINUTE, opened_at, closed_at) AS minutos_duracion,
        PERCENT_RANK() OVER (ORDER BY TIMESTAMPDIFF(MINUTE, opened_at, closed_at)) AS pr
    FROM trades
    WHERE status = 'closed'
) ranked
WHERE pr >= 0.5
ORDER BY pr
LIMIT 1;

-- 15.9 Entradas repetidas: BUY ignorados por posicion ya abierta, por activo
SELECT asset, COUNT(*) AS buy_ignored
FROM bot_events
WHERE event_type = 'signal_ignored_position_open'
GROUP BY asset
ORDER BY buy_ignored DESC;

-- 15.10 Estrategias que mas repiten señales sobre posiciones abiertas
SELECT
    ast.strategy_id,
    st.name AS strategy_name,
    be.asset,
    COUNT(*) AS buy_ignored
FROM bot_events be
JOIN active_trading_cycles atc ON atc.id = be.active_trading_cycle_id
JOIN active_strategies ast ON ast.id = atc.active_strategy_id
LEFT JOIN strategies st ON st.id = ast.strategy_id
WHERE be.event_type = 'signal_ignored_position_open'
GROUP BY ast.strategy_id, st.name, be.asset
ORDER BY buy_ignored DESC;

-- 15.11 Estrategias realmente desplegadas (tienen al menos un active_strategy)
-- vs total de estrategias existentes en catalogo
SELECT
    (SELECT COUNT(*) FROM strategies) AS estrategias_en_catalogo,
    (SELECT COUNT(DISTINCT strategy_id) FROM active_strategies) AS estrategias_desplegadas,
    (SELECT COUNT(DISTINCT strategy_id) FROM trades) AS estrategias_con_operaciones;

-- 15.12 Operaciones y P/L por estrategia (solo estrategias con >=1 trade)
SELECT
    t.strategy_id,
    st.name AS strategy_name,
    COUNT(*) AS trades_totales,
    SUM(t.status = 'closed') AS trades_cerrados,
    SUM(CASE WHEN t.status = 'closed' AND t.profit_loss > 0 THEN 1 ELSE 0 END) AS ganadoras,
    SUM(CASE WHEN t.status = 'closed' AND t.profit_loss <= 0 THEN 1 ELSE 0 END) AS perdedoras,
    SUM(CASE WHEN t.status = 'closed' THEN t.profit_loss ELSE 0 END) AS pl_total
FROM trades t
LEFT JOIN strategies st ON st.id = t.strategy_id
GROUP BY t.strategy_id, st.name
ORDER BY pl_total DESC;

-- 15.13 Operaciones, P/L y win rate por activo + señales repetidas por activo
SELECT
    a.symbol,
    COUNT(t.id) AS trades_totales,
    SUM(t.status = 'closed') AS trades_cerrados,
    SUM(CASE WHEN t.status = 'closed' AND t.profit_loss > 0 THEN 1 ELSE 0 END) AS ganadoras,
    ROUND(SUM(CASE WHEN t.status = 'closed' AND t.profit_loss > 0 THEN 1 ELSE 0 END)
        / NULLIF(SUM(t.status = 'closed'), 0) * 100, 2) AS win_rate_pct,
    SUM(CASE WHEN t.status = 'closed' THEN t.profit_loss ELSE 0 END) AS pl_total,
    (SELECT COUNT(*) FROM bot_events be WHERE be.asset = a.symbol AND be.event_type = 'signal_ignored_position_open') AS buy_ignored
FROM assets a
LEFT JOIN trades t ON t.asset_id = a.id
GROUP BY a.id, a.symbol
ORDER BY pl_total DESC;

-- 15.14 Posiciones actualmente abiertas por activo
SELECT
    a.symbol,
    t.id AS trade_id,
    t.entry_price,
    t.opened_at,
    TIMESTAMPDIFF(MINUTE, t.opened_at, NOW()) AS minutos_abierta,
    st.name AS strategy_name
FROM trades t
LEFT JOIN assets a ON a.id = t.asset_id
LEFT JOIN strategies st ON st.id = t.strategy_id
WHERE t.status = 'open'
ORDER BY t.opened_at;

-- 15.15 Cadenas de reentrada por (activo, estrategia): operaciones consecutivas
-- del mismo par activo+estrategia, en orden cronologico, con numero de
-- operacion dentro de la cadena y P/L acumulado de la cadena.
SELECT
    t.asset_id,
    a.symbol,
    t.strategy_id,
    st.name AS strategy_name,
    t.id AS trade_id,
    t.opened_at,
    t.closed_at,
    t.profit_loss,
    ROW_NUMBER() OVER (PARTITION BY t.asset_id, t.strategy_id ORDER BY t.opened_at) AS num_en_cadena,
    SUM(t.profit_loss) OVER (PARTITION BY t.asset_id, t.strategy_id ORDER BY t.opened_at
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS pl_acumulado_cadena,
    COUNT(*) OVER (PARTITION BY t.asset_id, t.strategy_id) AS operaciones_en_cadena
FROM trades t
LEFT JOIN assets a ON a.id = t.asset_id
LEFT JOIN strategies st ON st.id = t.strategy_id
ORDER BY a.symbol, st.name, t.opened_at;

-- 15.16 Resumen de cadenas de reentrada (una fila por activo+estrategia)
SELECT
    a.symbol,
    st.name AS strategy_name,
    COUNT(*) AS operaciones_en_cadena,
    SUM(CASE WHEN t.status = 'closed' THEN t.profit_loss ELSE 0 END) AS pl_acumulado_cadena
FROM trades t
LEFT JOIN assets a ON a.id = t.asset_id
LEFT JOIN strategies st ON st.id = t.strategy_id
GROUP BY t.asset_id, a.symbol, t.strategy_id, st.name
HAVING COUNT(*) > 1
ORDER BY operaciones_en_cadena DESC;

-- 15.17 INTEGRIDAD -----------------------------------------------------------

-- 15.17a Trades abiertos sin ciclo POSITION_OPEN correspondiente
SELECT t.id AS trade_id, a.symbol, t.strategy_id, t.opened_at
FROM trades t
LEFT JOIN assets a ON a.id = t.asset_id
LEFT JOIN active_trading_cycles atc
    ON atc.asset_id = t.asset_id AND atc.strategy_id = t.strategy_id AND atc.state = 'POSITION_OPEN'
WHERE t.status = 'open' AND atc.id IS NULL;

-- 15.17b Ciclos POSITION_OPEN sin trade abierto correspondiente
SELECT atc.id AS cycle_id, a.symbol, atc.strategy_id, atc.started_at
FROM active_trading_cycles atc
LEFT JOIN assets a ON a.id = atc.asset_id
LEFT JOIN trades t ON t.asset_id = atc.asset_id AND t.strategy_id = atc.strategy_id AND t.status = 'open'
WHERE atc.state = 'POSITION_OPEN' AND t.id IS NULL;

-- 15.17c BUY duplicados (mas de 1 orden buy para el mismo trade)
SELECT trade_id, COUNT(*) AS n_buys
FROM orders
WHERE side = 'buy'
GROUP BY trade_id
HAVING COUNT(*) > 1;

-- 15.17d SELL sin BUY correspondiente (trade con sell pero sin buy)
SELECT o.trade_id
FROM orders o
WHERE o.side = 'sell'
  AND NOT EXISTS (SELECT 1 FROM orders b WHERE b.trade_id = o.trade_id AND b.side = 'buy')
GROUP BY o.trade_id;

-- 15.17e Operaciones (cerradas o abiertas) que superan las 6h (max_holding_hours)
SELECT id, status, opened_at, closed_at,
    TIMESTAMPDIFF(MINUTE, opened_at, COALESCE(closed_at, NOW())) AS minutos
FROM trades
WHERE opened_at IS NOT NULL
  AND TIMESTAMPDIFF(HOUR, opened_at, COALESCE(closed_at, NOW())) >= 6;

-- 15.17f HOLD expirados incorrectamente (deberian estar EXPIRED, siguen en HOLD)
SELECT id, state, started_at, expires_at, TIMESTAMPDIFF(HOUR, expires_at, NOW()) AS horas_vencido
FROM active_trading_cycles
WHERE state = 'HOLD' AND expires_at IS NOT NULL AND expires_at <= NOW();

-- 15.17g Estrategias activas (status=running) asociadas a ciclos cerrados/expirados
SELECT ast.id AS active_strategy_id, ast.symbol, ast.status AS strategy_status,
    atc.id AS cycle_id, atc.state AS cycle_state
FROM active_strategies ast
JOIN active_trading_cycles atc ON atc.active_strategy_id = ast.id
WHERE ast.status = 'running' AND atc.state IN ('CLOSED', 'EXPIRED');

-- 15.17h Errores relevantes registrados en el periodo
SELECT id, created_at, event_type, asset, message
FROM bot_events
WHERE event_type LIKE '%error%' OR message LIKE '%error%' OR message LIKE '%timeout%' OR message LIKE '%timed out%'
ORDER BY created_at DESC;

-- 15.18 Base para comparacion con el corte anterior: conteos globales de eventos
-- y agregados clave, para dejar constancia numerica de este corte.
SELECT event_type, COUNT(*) AS n FROM bot_events GROUP BY event_type ORDER BY n DESC;
