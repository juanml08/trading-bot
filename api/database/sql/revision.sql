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
