<?php

namespace Tests\Unit;

use App\Actions\Strategy\ActivateStrategyAction;
use App\Actions\Strategy\ActivateTradingCycleAction;
use App\Actions\Strategy\RunAutomaticSearchAction;
use App\Actions\Strategy\SearchStrategiesAction;
use App\Actions\Strategy\StartAutomaticModeAction;
use App\MarketData\Candle;
use App\MarketData\MarketDataProvider;
use App\MarketData\SymbolUniverseProvider;
use App\MarketData\Timeframe;
use App\Models\AutomaticSearchCycle;
use App\Models\AutomaticSearchCycleAsset;
use App\Models\AutomaticSearchCycleStrategy;
use App\Models\AutomaticSearchState;
use App\Models\Strategy as StrategyModel;
use App\Opportunity\OpportunityScanner;
use App\Orchestration\MarketDataStrategyPipelineRunner;
use App\Strategy\Signal;
use App\Strategy\SignalType;
use App\Strategy\Strategy;
use App\Strategy\StrategyEvaluator;
use App\Strategy\StrategyPipeline;
use App\Strategy\StrategySelector;
use App\Strategy\TrainValidationSplit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers persisting the permanent {@see AutomaticSearchCycle} history that
 * {@see RunAutomaticSearchAction} writes as a consequence of the pipeline
 * result it already computes — never a second evaluation. This is additional
 * to (and must not affect) {@see AutomaticSearchState::$last_cycle}, which is
 * covered separately by RunAutomaticSearchActionTest.
 */
class AutomaticSearchCycleHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_completed_cycle_creates_a_history_record(): void
    {
        StrategyModel::factory()->create(['name' => 'Idle', 'class' => AutomaticSearchCycleHistoryIdleStrategy::class, 'parameters' => []]);
        $state = AutomaticSearchState::factory()->create();

        ($this->action(['Idle' => new AutomaticSearchCycleHistoryIdleStrategy]))($state);

        $this->assertSame(1, AutomaticSearchCycle::query()->count());

        $cycle = AutomaticSearchCycle::query()->first();
        $this->assertSame($state->account_id, $cycle->account_id);
        $this->assertSame(1, $cycle->assets_reviewed);
        $this->assertSame(1, $cycle->candidates_found);
        $this->assertNotNull($cycle->started_at);
        $this->assertNotNull($cycle->completed_at);
    }

    public function test_the_evaluated_assets_are_associated_to_the_correct_cycle(): void
    {
        // minimumTrades: 1 keeps the idle (never trading) strategy from
        // passing Discovery on either symbol, so both scanned candidates get
        // fully evaluated instead of the loop stopping at the first one.
        $state = AutomaticSearchState::factory()->create();

        ($this->action(['Idle' => new AutomaticSearchCycleHistoryIdleStrategy], minimumTrades: 1, symbols: ['AAAUSDT', 'BBBUSDT']))($state);

        $cycle = AutomaticSearchCycle::query()->first();
        $this->assertSame(2, $cycle->assets()->count());
        $this->assertSame(
            ['AAAUSDT', 'BBBUSDT'],
            $cycle->assets()->orderBy('id')->pluck('symbol')->all(),
        );
    }

    /**
     * Every strategy handed to the pipeline must be persisted, including one
     * that never survives TRAIN Discovery — this is the whole point of the
     * history table (analyzing whether current criteria are too strict).
     */
    public function test_every_evaluated_strategy_is_persisted_including_those_rejected_in_discovery(): void
    {
        $state = AutomaticSearchState::factory()->create();

        $strategies = ['Idle' => new AutomaticSearchCycleHistoryIdleStrategy];

        ($this->action($strategies, minimumTrades: 1))($state);

        $asset = AutomaticSearchCycleAsset::query()->first();
        $this->assertSame('no_opportunity', $asset->status);

        $strategyRecord = $asset->strategies()->first();
        $this->assertNotNull($strategyRecord);
        $this->assertSame('Idle', $strategyRecord->strategy_name);
        $this->assertSame('discarded_in_discovery', $strategyRecord->status);
        $this->assertFalse($strategyRecord->discovery_passed);
        $this->assertNull($strategyRecord->validation_passed);
        $this->assertSame(0, $strategyRecord->train_total_trades);
        $this->assertNull($strategyRecord->validation_total_trades);
    }

    /**
     * A strategy that reaches Validation must keep its Validation metrics
     * alongside its Train metrics, not overwrite or discard either.
     */
    public function test_strategies_reaching_validation_keep_both_train_and_validation_metrics(): void
    {
        $trainCloses = ['100', '101', '102', '103', '104', '105', '106', '107'];
        $validationCloses = ['200', '200', '140', '220'];
        $candles = $this->candles([...$trainCloses, ...$validationCloses]);

        $provider = new AutomaticSearchCycleHistoryFakeMarketDataProvider($candles);
        $pipeline = new StrategyPipeline(
            evaluator: new StrategyEvaluator,
            selector: new StrategySelector,
            split: new TrainValidationSplit(70),
            minimumTrades: 1,
            minimumWinRate: '50',
            maximumDrawdown: '10',
            minimumProfitLoss: '0',
        );
        $searchAction = new SearchStrategiesAction(new MarketDataStrategyPipelineRunner($provider, $pipeline));
        $scanner = new OpportunityScanner(new AutomaticSearchCycleHistoryFakeUniverseProvider(['BTCUSDT']), $provider);
        $strategy = new AutomaticSearchCycleHistoryPositionalStrategy([2 => SignalType::BUY, 4 => SignalType::SELL]);
        $action = new RunAutomaticSearchAction($searchAction, new ActivateTradingCycleAction(new ActivateStrategyAction), new StartAutomaticModeAction, $scanner, ['Volatile' => $strategy]);

        $state = AutomaticSearchState::factory()->create();

        $action($state);

        $strategyRecord = AutomaticSearchCycleStrategy::query()->first();
        $this->assertSame('discarded_in_validation', $strategyRecord->status);
        $this->assertTrue($strategyRecord->discovery_passed);
        $this->assertFalse($strategyRecord->validation_passed);
        $this->assertSame(1, $strategyRecord->train_total_trades);
        $this->assertSame(1, $strategyRecord->validation_total_trades);
        $this->assertSame(['discovery' => [], 'validation' => ['maximumDrawdown']], $strategyRecord->failed_criteria);
    }

    public function test_failed_criteria_are_preserved_for_both_stages(): void
    {
        $state = AutomaticSearchState::factory()->create();

        ($this->action(['Idle' => new AutomaticSearchCycleHistoryIdleStrategy], minimumTrades: 1))($state);

        $strategyRecord = AutomaticSearchCycleStrategy::query()->first();
        $this->assertSame(['minimumTrades'], $strategyRecord->failed_criteria['discovery']);
        $this->assertSame([], $strategyRecord->failed_criteria['validation']);
    }

    public function test_a_selected_strategy_is_correctly_identified_in_the_history(): void
    {
        StrategyModel::factory()->create(['name' => 'Idle', 'class' => AutomaticSearchCycleHistoryIdleStrategy::class, 'parameters' => []]);
        $state = AutomaticSearchState::factory()->create();

        ($this->action(['Idle' => new AutomaticSearchCycleHistoryIdleStrategy]))($state);

        $strategyRecord = AutomaticSearchCycleStrategy::query()->first();
        $this->assertSame('selected', $strategyRecord->status);
        $this->assertTrue($strategyRecord->discovery_passed);
        $this->assertTrue($strategyRecord->validation_passed);
    }

    /**
     * Persisting the history must never change `last_cycle`'s existing
     * behavior — the UI still reads the most recent cycle from there exactly
     * as before this history table existed.
     */
    public function test_persisting_the_history_does_not_change_last_cycle_behavior(): void
    {
        StrategyModel::factory()->create(['name' => 'Idle', 'class' => AutomaticSearchCycleHistoryIdleStrategy::class, 'parameters' => []]);
        $state = AutomaticSearchState::factory()->create();

        ($this->action(['Idle' => new AutomaticSearchCycleHistoryIdleStrategy]))($state);

        $lastCycle = $state->fresh()->last_cycle;
        $this->assertSame(1, $lastCycle['assetsReviewed']);
        $this->assertSame(1, $lastCycle['candidatesFound']);
        $this->assertSame('BTCUSDT', $lastCycle['assets'][0]['symbol']);

        // Both representations must agree on the same underlying result.
        $cycle = AutomaticSearchCycle::query()->first();
        $this->assertSame($lastCycle['assetsReviewed'], $cycle->assets_reviewed);
        $this->assertSame($lastCycle['candidatesFound'], $cycle->candidates_found);
    }

    /**
     * A single search attempt must persist exactly one cycle row, with
     * exactly one asset row per candidate and one strategy row per strategy
     * evaluated for that candidate — no duplicates.
     */
    public function test_no_duplicate_records_are_created_within_a_single_cycle(): void
    {
        StrategyModel::factory()->create(['name' => 'Idle', 'class' => AutomaticSearchCycleHistoryIdleStrategy::class, 'parameters' => []]);
        $state = AutomaticSearchState::factory()->create();

        ($this->action(['Idle' => new AutomaticSearchCycleHistoryIdleStrategy], symbols: ['AAAUSDT']))($state);

        $this->assertSame(1, AutomaticSearchCycle::query()->count());
        $this->assertSame(1, AutomaticSearchCycleAsset::query()->count());
        $this->assertSame(1, AutomaticSearchCycleStrategy::query()->count());
    }

    /**
     * A search attempt that fails mid-scan (e.g. Binance unreachable) never
     * completes a cycle, so it must not persist a history row either — exactly
     * mirroring `last_cycle` staying untouched in that case.
     */
    public function test_a_failed_search_attempt_does_not_persist_a_history_record(): void
    {
        $state = AutomaticSearchState::factory()->create();

        $provider = new AutomaticSearchCycleHistoryFakeMarketDataProvider($this->candles());
        $pipeline = new StrategyPipeline(
            evaluator: new StrategyEvaluator,
            selector: new StrategySelector,
            split: new TrainValidationSplit(50),
            minimumTrades: 0,
            minimumWinRate: '0',
            maximumDrawdown: '100',
            minimumProfitLoss: '-1000000',
        );
        $searchAction = new SearchStrategiesAction(new MarketDataStrategyPipelineRunner($provider, $pipeline));
        $scanner = new OpportunityScanner(new AutomaticSearchCycleHistoryThrowingUniverseProvider, $provider);
        $action = new RunAutomaticSearchAction($searchAction, new ActivateTradingCycleAction(new ActivateStrategyAction), new StartAutomaticModeAction, $scanner);

        try {
            $action($state);
        } catch (\Throwable) {
            // expected: the scanner failure propagates, see RunAutomaticSearchActionTest.
        }

        $this->assertSame(0, AutomaticSearchCycle::query()->count());
    }

    /**
     * Forces a failure after the cycle row and one full asset+strategy pair
     * have already been inserted, to prove `persistCycleHistory()`'s
     * transaction really rolls back everything it attempted — not merely
     * that it never started.
     */
    public function test_a_failure_partway_through_persistence_rolls_back_the_entire_cycle(): void
    {
        $state = AutomaticSearchState::factory()->create();
        $action = $this->action(['Idle' => new AutomaticSearchCycleHistoryIdleStrategy]);

        AutomaticSearchCycleAsset::creating(function (AutomaticSearchCycleAsset $model): void {
            if ($model->symbol === 'BBBUSDT') {
                throw new RuntimeException('forced failure for rollback test');
            }
        });

        $cycle = [
            'completedAt' => CarbonImmutable::now()->toISOString(),
            'assetsReviewed' => 2,
            'candidatesFound' => 0,
            'assets' => [
                $this->assetReview('AAAUSDT'),
                $this->assetReview('BBBUSDT'),
            ],
        ];

        try {
            $method = new ReflectionMethod(RunAutomaticSearchAction::class, 'persistCycleHistory');

            try {
                $method->invoke($action, $state->tradingAccount, CarbonImmutable::now(), $cycle);
                $this->fail('Expected the forced failure to propagate.');
            } catch (RuntimeException $exception) {
                $this->assertSame('forced failure for rollback test', $exception->getMessage());
            }

            // 'AAAUSDT' was created (and its strategy row too) before
            // 'BBBUSDT' triggered the failure — if the transaction did not
            // roll back, the cycle and 'AAAUSDT' would still be here.
            $this->assertSame(0, AutomaticSearchCycle::query()->count());
            $this->assertSame(0, AutomaticSearchCycleAsset::query()->count());
            $this->assertSame(0, AutomaticSearchCycleStrategy::query()->count());
        } finally {
            AutomaticSearchCycleAsset::flushEventListeners();
        }
    }

    /**
     * A failure during history persistence must not leave `last_cycle`
     * claiming the attempt completed — it must keep whatever it held before
     * this (failed) attempt, exactly as if the attempt never ran.
     */
    public function test_last_cycle_is_not_updated_when_history_persistence_fails(): void
    {
        StrategyModel::factory()->create(['name' => 'Idle', 'class' => AutomaticSearchCycleHistoryIdleStrategy::class, 'parameters' => []]);
        $state = AutomaticSearchState::factory()->create();
        $action = $this->action(['Idle' => new AutomaticSearchCycleHistoryIdleStrategy], minimumTrades: 1);

        // A real, successfully persisted cycle first, so there is a genuine
        // previous value for `last_cycle` to be compared against.
        $action($state);
        $this->assertSame(1, AutomaticSearchCycle::query()->count());
        $lastCycleBefore = $state->fresh()->last_cycle;
        $this->assertNotNull($lastCycleBefore);

        AutomaticSearchCycleAsset::creating(function (): void {
            throw new RuntimeException('forced history failure');
        });

        try {
            try {
                $action($state);
                $this->fail('Expected the forced failure to propagate.');
            } catch (RuntimeException $exception) {
                $this->assertSame('forced history failure', $exception->getMessage());
            }

            // No second cycle was added, and `last_cycle` still holds the
            // first (successful) attempt's data, not the failed one's.
            $this->assertSame(1, AutomaticSearchCycle::query()->count());
            $this->assertSame($lastCycleBefore, $state->fresh()->last_cycle);
        } finally {
            AutomaticSearchCycleAsset::flushEventListeners();
        }
    }

    /**
     * With history persisted before `last_cycle` is written, the success
     * path must behave exactly as before this fix: a full cycle (every
     * asset, every strategy) and `last_cycle` both end up saved together.
     */
    public function test_a_successful_cycle_still_persists_full_history_and_last_cycle_together(): void
    {
        StrategyModel::factory()->create(['name' => 'Idle', 'class' => AutomaticSearchCycleHistoryIdleStrategy::class, 'parameters' => []]);
        $state = AutomaticSearchState::factory()->create();

        // minimumTrades: 1 keeps Idle from passing Discovery on either
        // symbol, so both scanned candidates are fully evaluated.
        ($this->action(['Idle' => new AutomaticSearchCycleHistoryIdleStrategy], minimumTrades: 1, symbols: ['AAAUSDT', 'BBBUSDT']))($state);

        $this->assertSame(1, AutomaticSearchCycle::query()->count());
        $cycle = AutomaticSearchCycle::query()->first();
        $this->assertSame(2, $cycle->assets()->count());
        $this->assertSame(2, AutomaticSearchCycleStrategy::query()->count());

        $lastCycle = $state->fresh()->last_cycle;
        $this->assertSame(2, $lastCycle['assetsReviewed']);
        $this->assertSame(0, $lastCycle['candidatesFound']);
        $this->assertSame(['AAAUSDT', 'BBBUSDT'], array_column($lastCycle['assets'], 'symbol'));
    }

    /**
     * The transaction/ordering fix must not change how many times the
     * strategy is actually evaluated: persisting history still consumes the
     * TRAIN/VALIDATION results the pipeline already computed, it never
     * re-runs Discovery or Validation to build the history rows.
     */
    public function test_persisting_history_does_not_re_evaluate_the_strategy(): void
    {
        $state = AutomaticSearchState::factory()->create();
        $counting = new AutomaticSearchCycleHistoryCountingStrategy(new AutomaticSearchCycleHistoryIdleStrategy);

        // minimumTrades: 1 keeps Idle (0 trades) from passing Discovery, so
        // VALIDATION never runs — the only calls come from evaluating TRAIN
        // once per its candle (a 50% split of the default 10 candles = 5).
        ($this->action(['Idle' => $counting], minimumTrades: 1))($state);

        $this->assertSame(5, $counting->calls);
    }

    /**
     * @return array{symbol: string, evaluatedAt: string, status: string, reason: string|null, strategies: array<int, array<string, mixed>>}
     */
    private function assetReview(string $symbol): array
    {
        return [
            'symbol' => $symbol,
            'evaluatedAt' => CarbonImmutable::now()->toISOString(),
            'status' => 'no_opportunity',
            'reason' => null,
            'strategies' => [
                [
                    'name' => 'Idle',
                    'status' => 'discarded_in_discovery',
                    'discovery' => [
                        'passed' => false,
                        'failedCriteria' => ['minimumTrades'],
                        'metrics' => $this->zeroMetrics(),
                    ],
                    'validation' => null,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function zeroMetrics(): array
    {
        return [
            'totalTrades' => 0,
            'winningTrades' => 0,
            'losingTrades' => 0,
            'winRate' => '0',
            'profitLoss' => '0',
            'profitLossPercentage' => '0',
            'maxDrawdownPercentage' => '0',
            'profitFactor' => '0',
            'totalCosts' => '0',
            'grossProfitLoss' => '0',
        ];
    }

    /**
     * @param  array<string, Strategy>  $strategies
     * @param  string[]  $symbols
     */
    private function action(array $strategies, int $minimumTrades = 0, array $symbols = ['BTCUSDT']): RunAutomaticSearchAction
    {
        $provider = new AutomaticSearchCycleHistoryFakeMarketDataProvider($this->candles());
        $pipeline = new StrategyPipeline(
            evaluator: new StrategyEvaluator,
            selector: new StrategySelector,
            split: new TrainValidationSplit(50),
            minimumTrades: $minimumTrades,
            minimumWinRate: '0',
            maximumDrawdown: '100',
            minimumProfitLoss: '-1000000',
        );
        $searchAction = new SearchStrategiesAction(new MarketDataStrategyPipelineRunner($provider, $pipeline));
        $scanner = new OpportunityScanner(new AutomaticSearchCycleHistoryFakeUniverseProvider($symbols), $provider);

        return new RunAutomaticSearchAction($searchAction, new ActivateTradingCycleAction(new ActivateStrategyAction), new StartAutomaticModeAction, $scanner, $strategies);
    }

    /**
     * @param  string[]|null  $closes
     * @return Candle[]
     */
    private function candles(?array $closes = null): array
    {
        $base = CarbonImmutable::parse('2026-01-01 00:00:00');
        $closes ??= ['100', '101', '102', '103', '104', '105', '106', '107', '108', '109'];

        return array_map(
            fn (string $close, int $index): Candle => new Candle(
                symbol: 'BTCUSDT',
                timeframe: Timeframe::Hour1,
                timestamp: $base->addHours($index),
                open: $close,
                high: $close,
                low: $close,
                close: $close,
                volume: '1',
            ),
            $closes,
            array_keys($closes),
        );
    }
}

final class AutomaticSearchCycleHistoryFakeMarketDataProvider implements MarketDataProvider
{
    /**
     * @param  Candle[]  $candles
     */
    public function __construct(private readonly array $candles) {}

    public function getHistoricalCandles(string $symbol, Timeframe $timeframe, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->candles;
    }
}

final class AutomaticSearchCycleHistoryFakeUniverseProvider implements SymbolUniverseProvider
{
    /**
     * @param  string[]  $symbols
     */
    public function __construct(private readonly array $symbols) {}

    public function activeSymbols(string $quoteAsset): array
    {
        return $this->symbols;
    }
}

final class AutomaticSearchCycleHistoryThrowingUniverseProvider implements SymbolUniverseProvider
{
    public function activeSymbols(string $quoteAsset): array
    {
        throw new RuntimeException('boom');
    }
}

final class AutomaticSearchCycleHistoryIdleStrategy implements Strategy
{
    public function generate(array $candles): Signal
    {
        return new Signal(
            type: SignalType::HOLD,
            reason: 'idle test signal',
            generatedAt: CarbonImmutable::now(),
        );
    }
}

/**
 * Counts how many times {@see Strategy::generate()} is actually invoked,
 * delegating the signal itself to `$inner` — used to prove that persisting
 * cycle history never triggers an extra Discovery/Validation run.
 */
final class AutomaticSearchCycleHistoryCountingStrategy implements Strategy
{
    public int $calls = 0;

    public function __construct(private readonly Strategy $inner) {}

    public function generate(array $candles): Signal
    {
        $this->calls++;

        return $this->inner->generate($candles);
    }
}

final class AutomaticSearchCycleHistoryPositionalStrategy implements Strategy
{
    /**
     * @param  array<int, SignalType>  $signalsByCandleCount
     */
    public function __construct(private readonly array $signalsByCandleCount) {}

    public function generate(array $candles): Signal
    {
        $type = $this->signalsByCandleCount[count($candles)] ?? SignalType::HOLD;

        return new Signal(
            type: $type,
            reason: 'positional test signal',
            generatedAt: CarbonImmutable::now(),
        );
    }
}
