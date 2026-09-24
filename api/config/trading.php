<?php

return [

    /*
    |--------------------------------------------------------------------
    | Automatic strategy search
    |--------------------------------------------------------------------
    |
    | Governs how "Modo Automático" retries the strategy search while no
    | strategy is active: how often it retries when no candidate was
    | selectable, and how many days of market data the rolling backtest
    | window covers on each attempt.
    |
    */

    'automatic_search' => [
        'retry_seconds' => env('AUTOMATIC_SEARCH_RETRY_SECONDS', 3600),
        'lookback_days' => env('AUTOMATIC_SEARCH_LOOKBACK_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------
    | Strategy Discovery thresholds
    |--------------------------------------------------------------------
    |
    | Minimum viability criteria StrategyDiscovery (TRAIN) uses to decide
    | whether a strategy evaluation is worth carrying into VALIDATION.
    | Deliberately more permissive than `validation` below — Discovery's
    | job is to let more candidates through for evaluation, not to declare
    | anything profitable; VALIDATION remains the strict filter. These are
    | exploration/testing thresholds, not production risk limits, and are
    | independent of the evaluation run's initial capital.
    |
    | minimum_win_rate and maximum_drawdown are percentages on a 0-100
    | scale (e.g. "40" means 40%); minimum_profit_loss is an absolute
    | monetary amount. Kept as strings for BCMath precision.
    |
    */

    'discovery' => [
        'minimum_trades' => env('DISCOVERY_MINIMUM_TRADES', 5),
        'minimum_win_rate' => env('DISCOVERY_MINIMUM_WIN_RATE', '30'),
        'maximum_drawdown' => env('DISCOVERY_MAXIMUM_DRAWDOWN', '15'),
        'minimum_profit_loss' => env('DISCOVERY_MINIMUM_PROFIT_LOSS', '0'),
    ],

    /*
    |--------------------------------------------------------------------
    | Strategy Validation thresholds
    |--------------------------------------------------------------------
    |
    | Minimum viability criteria StrategyPipeline uses, independently of
    | `discovery` above, to decide whether a candidate that already passed
    | Discovery on TRAIN also holds up on the out-of-sample VALIDATION
    | window. Kept as a separate config section (not reused from
    | `discovery`) so the two stages can be tuned independently: Discovery
    | stays open to surface more candidates, Validation stays the strict
    | final filter. Same units as `discovery`.
    |
    */

    'validation' => [
        'minimum_trades' => env('VALIDATION_MINIMUM_TRADES', 6),
        'minimum_win_rate' => env('VALIDATION_MINIMUM_WIN_RATE', '40'),
        'maximum_drawdown' => env('VALIDATION_MAXIMUM_DRAWDOWN', '10'),
        'minimum_profit_loss' => env('VALIDATION_MINIMUM_PROFIT_LOSS', '0'),
    ],

    /*
    |--------------------------------------------------------------------
    | Opportunity Scanner
    |--------------------------------------------------------------------
    |
    | Governs how the Opportunity Scanner reduces the tradable symbol
    | universe to a small set of candidates worth handing to the Strategy
    | Pipeline for evaluation (see App\Opportunity\OpportunityScanner).
    |
    | `limit` is how many candidates it returns at most (10 by default, as
    | required by the current product scope). `quote_asset` restricts the
    | universe to pairs quoted in this asset (e.g. only *USDT pairs).
    | `timeframe`/`lookback_candles` control how much recent OHLCV data is
    | sampled per symbol to rank it by recent traded volume (liquidity),
    | which is the only criterion available today — see OpportunityScanner's
    | docblock for why this is not, and must not be read as, a profitability
    | signal. `max_universe_size` bounds how many symbols from the universe
    | are sampled at all, to keep a single scan from making an unbounded
    | number of market data requests.
    |
    */

    'opportunity_scanner' => [
        'limit' => env('OPPORTUNITY_SCANNER_LIMIT', 10),
        'quote_asset' => env('OPPORTUNITY_SCANNER_QUOTE_ASSET', 'USDT'),
        'timeframe' => env('OPPORTUNITY_SCANNER_TIMEFRAME', '30m'),
        'lookback_candles' => env('OPPORTUNITY_SCANNER_LOOKBACK_CANDLES', 24),
        'max_universe_size' => env('OPPORTUNITY_SCANNER_MAX_UNIVERSE_SIZE', 50),
    ],

    /*
    |--------------------------------------------------------------------
    | Active trading cycles
    |--------------------------------------------------------------------
    |
    | Governs how many {@see App\Models\ActiveTradingCycle} rows an account
    | may keep simultaneously in a non-terminal state (HOLD or
    | POSITION_OPEN). RunAutomaticSearchAction stops selecting and
    | activating new candidates once this limit is reached, until a cycle
    | reaches a terminal state (CLOSED or EXPIRED) and frees a slot.
    |
    | `hold_timeout_hours` bounds how long a cycle may sit in HOLD before
    | {@see App\Actions\Strategy\ExpireHoldCyclesAction} expires it: a cycle
    | that never produces a BUY signal within this window is considered to
    | have had its chance, so it is moved to EXPIRED and its slot is freed
    | for a new candidate instead of holding it indefinitely. Does not
    | affect POSITION_OPEN cycles.
    |
    */

    'active_cycles' => [
        'max_active' => env('MAX_ACTIVE_CYCLES', 5),
        'hold_timeout_hours' => env('HOLD_CYCLE_TIMEOUT_HOURS', 4),
    ],

];
