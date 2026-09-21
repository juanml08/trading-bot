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
    | Minimum viability criteria StrategyDiscovery (TRAIN) and
    | StrategyPipeline (VALIDATION) use to decide whether a strategy
    | evaluation is a viable candidate. These are exploration/testing
    | thresholds, not production risk limits, and are independent of the
    | evaluation run's initial capital.
    |
    | minimum_win_rate and maximum_drawdown are percentages on a 0-100
    | scale (e.g. "40" means 40%); minimum_profit_loss is an absolute
    | monetary amount. Kept as strings for BCMath precision.
    |
    */

    'discovery' => [
        'minimum_trades' => env('DISCOVERY_MINIMUM_TRADES', 8),
        'minimum_win_rate' => env('DISCOVERY_MINIMUM_WIN_RATE', '40'),
        'maximum_drawdown' => env('DISCOVERY_MAXIMUM_DRAWDOWN', '10'),
        'minimum_profit_loss' => env('DISCOVERY_MINIMUM_PROFIT_LOSS', '0'),
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
        'timeframe' => env('OPPORTUNITY_SCANNER_TIMEFRAME', '1h'),
        'lookback_candles' => env('OPPORTUNITY_SCANNER_LOOKBACK_CANDLES', 24),
        'max_universe_size' => env('OPPORTUNITY_SCANNER_MAX_UNIVERSE_SIZE', 50),
    ],

];
