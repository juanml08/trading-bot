<?php

namespace Tests\Unit;

use App\Actions\Strategy\ActivateStrategyAction;
use App\Actions\Strategy\RunAutomaticSearchAction;
use App\Actions\Strategy\SearchStrategiesAction;
use App\Actions\Strategy\StartAutomaticModeAction;
use App\Console\Commands\AutomaticStrategySearchCommand;
use App\MarketData\Candle;
use App\MarketData\MarketDataProvider;
use App\MarketData\SymbolUniverseProvider;
use App\MarketData\Timeframe;
use App\Models\ActiveStrategy;
use App\Models\AutomaticSearchState;
use App\Models\BotEvent;
use App\Models\Strategy as StrategyModel;
use App\Opportunity\OpportunityScanner;
use App\Orchestration\MarketDataStrategyPipelineRunner;
use App\Strategy\Signal;
use App\Strategy\SignalType;
use App\Strategy\Strategy;
use App\Strategy\StrategyCatalog;
use App\Strategy\StrategyEvaluator;
use App\Strategy\StrategyPipeline;
use App\Strategy\StrategySelector;
use App\Strategy\TrainValidationSplit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RunAutomaticSearchActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_nothing_when_the_account_already_has_a_running_strategy(): void
    {
        $active = ActiveStrategy::factory()->create(['status' => ActiveStrategy::STATUS_RUNNING]);
        $state = AutomaticSearchState::factory()->create(['account_id' => $active->account_id]);

        ($this->action(['Idle' => new RunAutomaticSearchActionIdleStrategy]))($state);

        $this->assertSame(0, BotEvent::query()->count());
        $this->assertSame(1, ActiveStrategy::query()->count());
    }

    public function test_a_selectable_candidate_is_applied_and_started_automatically(): void
    {
        StrategyModel::factory()->create(['name' => 'Idle', 'class' => RunAutomaticSearchActionIdleStrategy::class, 'parameters' => []]);
        $state = AutomaticSearchState::factory()->create();

        ($this->action(['Idle' => new RunAutomaticSearchActionIdleStrategy]))($state);

        $active = ActiveStrategy::query()->where('account_id', $state->account_id)->first();
        $this->assertNotNull($active);
        $this->assertSame(ActiveStrategy::STATUS_RUNNING, $active->status);
        $this->assertSame('Idle', $active->strategy->name);
        $this->assertSame('BTCUSDT', $active->symbol);

        $this->assertSame(
            ['automatic_search_started', 'opportunities_scanned', 'strategies_evaluated', 'strategy_selected_automatically', 'strategy_applied_automatically', 'bot_started', 'automatic_search_completed'],
            BotEvent::query()->orderBy('id')->pluck('event_type')->all(),
        );

        $completedEvent = BotEvent::query()->where('event_type', 'automatic_search_completed')->first();
        $this->assertSame('Búsqueda completada: 1 activo(s) revisado(s), 1 candidato(s) encontrado(s).', $completedEvent->message);

        $this->assertSame('BTCUSDT', $state->fresh()->symbol);
        $this->assertNull($state->fresh()->next_search_at);
        $this->assertNotNull($state->fresh()->last_searched_at);

        $lastCycle = $state->fresh()->last_cycle;
        $this->assertSame(1, $lastCycle['assetsReviewed']);
        $this->assertSame(1, $lastCycle['candidatesFound']);
        $this->assertSame('BTCUSDT', $lastCycle['assets'][0]['symbol']);
        $this->assertSame('candidate_found', $lastCycle['assets'][0]['status']);
        $this->assertNull($lastCycle['assets'][0]['reason']);

        // The single strategy evaluated reached the Selector and was picked,
        // so its per-strategy diagnostic must say exactly that.
        $strategies = $lastCycle['assets'][0]['strategies'];
        $this->assertCount(1, $strategies);
        $this->assertSame('Idle', $strategies[0]['name']);
        $this->assertSame('selected', $strategies[0]['status']);
        $this->assertTrue($strategies[0]['discovery']['passed']);
        $this->assertSame([], $strategies[0]['discovery']['failedCriteria']);
        $this->assertNotNull($strategies[0]['validation']);
        $this->assertTrue($strategies[0]['validation']['passed']);
        $this->assertSame([], $strategies[0]['validation']['failedCriteria']);
        $this->assertArrayHasKey('totalTrades', $strategies[0]['validation']['metrics']);
    }

    public function test_no_selectable_candidate_does_not_apply_anything_and_schedules_the_next_attempt(): void
    {
        config(['trading.automatic_search.retry_seconds' => 3600]);
        $state = AutomaticSearchState::factory()->create();

        $strategies = [
            'A' => new RunAutomaticSearchActionIdleStrategy,
            'B' => new RunAutomaticSearchActionIdleStrategy,
        ];

        ($this->action($strategies, minimumTrades: 1))($state);

        $this->assertSame(0, ActiveStrategy::query()->count());
        $this->assertSame(
            ['automatic_search_started', 'opportunities_scanned', 'strategies_evaluated', 'no_candidate_found', 'next_search_scheduled', 'automatic_search_completed'],
            BotEvent::query()->orderBy('id')->pluck('event_type')->all(),
        );

        $completedEvent = BotEvent::query()->where('event_type', 'automatic_search_completed')->first();
        $this->assertSame('Búsqueda completada: 1 activo(s) revisado(s), 0 candidato(s) encontrado(s).', $completedEvent->message);

        $fresh = $state->fresh();
        $this->assertNotNull($fresh->next_search_at);
        $this->assertTrue($fresh->next_search_at->gte(CarbonImmutable::now()->addMinutes(59)));

        $lastCycle = $fresh->last_cycle;
        $this->assertSame(1, $lastCycle['assetsReviewed']);
        $this->assertSame(0, $lastCycle['candidatesFound']);
        $this->assertSame('BTCUSDT', $lastCycle['assets'][0]['symbol']);
    }

    /**
     * Trial uses a 1800-second (30-minute) retry instead of the 1-hour
     * default, to get more exploration cycles during testing. This must
     * only change how soon the next search attempt is scheduled.
     */
    public function test_no_selectable_candidate_schedules_the_next_attempt_using_the_configured_retry_seconds(): void
    {
        config(['trading.automatic_search.retry_seconds' => 1800]);
        $state = AutomaticSearchState::factory()->create();

        $strategies = [
            'A' => new RunAutomaticSearchActionIdleStrategy,
            'B' => new RunAutomaticSearchActionIdleStrategy,
        ];

        ($this->action($strategies, minimumTrades: 1))($state);

        $fresh = $state->fresh();
        $this->assertNotNull($fresh->next_search_at);
        $this->assertTrue($fresh->next_search_at->gte(CarbonImmutable::now()->addMinutes(29)));
        $this->assertTrue($fresh->next_search_at->lte(CarbonImmutable::now()->addMinutes(31)));
    }

    /**
     * The Opportunity Scanner narrows the universe to candidates; the
     * pipeline must still be able to evaluate more than one of them in a
     * single search attempt, trying each in ranked order until one is
     * selectable (or none is).
     */
    public function test_it_evaluates_every_scanned_candidate_until_one_is_selectable(): void
    {
        config(['trading.automatic_search.retry_seconds' => 3600]);
        $state = AutomaticSearchState::factory()->create();

        $strategies = ['A' => new RunAutomaticSearchActionIdleStrategy];

        ($this->action($strategies, minimumTrades: 1, symbols: ['AAAUSDT', 'BBBUSDT', 'CCCUSDT']))($state);

        $this->assertSame(0, ActiveStrategy::query()->count());
        $evaluatedFor = BotEvent::query()->where('event_type', 'strategies_evaluated')->pluck('asset')->all();
        $this->assertSame(['AAAUSDT', 'BBBUSDT', 'CCCUSDT'], $evaluatedFor);
    }

    /**
     * The Activity must show, in the Scanner's own ranked order, exactly the
     * candidates it returned — before any strategy evaluation is logged.
     */
    public function test_the_scanned_opportunities_are_logged_in_ranked_order_before_evaluation(): void
    {
        $state = AutomaticSearchState::factory()->create();

        ($this->action(['Idle' => new RunAutomaticSearchActionIdleStrategy], minimumTrades: 1, symbols: ['BBBUSDT', 'AAAUSDT', 'CCCUSDT']))($state);

        $opportunitiesEvent = BotEvent::query()->where('event_type', 'opportunities_scanned')->first();
        $this->assertNotNull($opportunitiesEvent);
        $this->assertSame(
            'Oportunidades seleccionadas para evaluar: BBBUSDT, AAAUSDT, CCCUSDT.',
            $opportunitiesEvent->message,
        );

        $firstEvaluationEvent = BotEvent::query()->where('event_type', 'strategies_evaluated')->orderBy('id')->first();
        $this->assertNotNull($firstEvaluationEvent);
        $this->assertLessThan($firstEvaluationEvent->id, $opportunitiesEvent->id);
    }

    /**
     * If the universe genuinely only has fewer symbols available than the
     * Scanner's limit, the Activity must show only those — never invented
     * placeholders to fill out the count.
     */
    public function test_it_shows_only_the_opportunities_the_scanner_actually_returned(): void
    {
        $state = AutomaticSearchState::factory()->create();

        ($this->action(['Idle' => new RunAutomaticSearchActionIdleStrategy], minimumTrades: 1, symbols: ['AAAUSDT', 'BBBUSDT']))($state);

        $opportunitiesEvent = BotEvent::query()->where('event_type', 'opportunities_scanned')->first();
        $this->assertSame('Oportunidades seleccionadas para evaluar: AAAUSDT, BBBUSDT.', $opportunitiesEvent->message);
    }

    /**
     * The Scanner reducing the universe to zero candidates (e.g. no symbols
     * available yet) must not be treated as an error: it schedules the next
     * attempt exactly like "no selectable candidate" does, and must not
     * invent a candidate to search with.
     */
    public function test_no_opportunities_found_schedules_the_next_attempt_without_searching(): void
    {
        config(['trading.automatic_search.retry_seconds' => 3600]);
        $state = AutomaticSearchState::factory()->create();

        ($this->action(['Idle' => new RunAutomaticSearchActionIdleStrategy], symbols: []))($state);

        $this->assertSame(0, ActiveStrategy::query()->count());
        $this->assertSame(
            ['automatic_search_started', 'opportunities_scanned', 'no_candidate_found', 'next_search_scheduled', 'automatic_search_completed'],
            BotEvent::query()->orderBy('id')->pluck('event_type')->all(),
        );

        $opportunitiesEvent = BotEvent::query()->where('event_type', 'opportunities_scanned')->first();
        $this->assertSame('No se encontraron oportunidades para evaluar.', $opportunitiesEvent->message);

        $completedEvent = BotEvent::query()->where('event_type', 'automatic_search_completed')->first();
        $this->assertSame('Búsqueda completada: 0 activo(s) revisado(s), 0 candidato(s) encontrado(s).', $completedEvent->message);

        $lastCycle = $state->fresh()->last_cycle;
        $this->assertSame(0, $lastCycle['assetsReviewed']);
        $this->assertSame(0, $lastCycle['candidatesFound']);
        $this->assertSame([], $lastCycle['assets']);
    }

    /**
     * A candidate whose strategies never reach VALIDATION (none survives
     * TRAIN Discovery) must be reported as "no_opportunity" — distinct from
     * "discarded", which requires at least one strategy to have reached
     * VALIDATION and still not be selected.
     */
    public function test_records_no_opportunity_status_when_no_strategy_passes_discovery(): void
    {
        $state = AutomaticSearchState::factory()->create();

        $strategies = ['Idle' => new RunAutomaticSearchActionIdleStrategy];

        ($this->action($strategies, minimumTrades: 1))($state);

        $lastCycle = $state->fresh()->last_cycle;
        $this->assertSame('no_opportunity', $lastCycle['assets'][0]['status']);
        $this->assertNull($lastCycle['assets'][0]['reason']);

        // The strategy never reached VALIDATION, so its diagnostic must say
        // exactly that, with Discovery's own failed criteria and no
        // validation block at all (rather than an invented one).
        $strategies = $lastCycle['assets'][0]['strategies'];
        $this->assertCount(1, $strategies);
        $this->assertSame('Idle', $strategies[0]['name']);
        $this->assertSame('discarded_in_discovery', $strategies[0]['status']);
        $this->assertFalse($strategies[0]['discovery']['passed']);
        $this->assertSame(['minimumTrades'], $strategies[0]['discovery']['failedCriteria']);
        $this->assertSame(0, $strategies[0]['discovery']['metrics']['totalTrades']);
        $this->assertNull($strategies[0]['validation']);
    }

    /**
     * A strategy that passes TRAIN Discovery but fails VALIDATION on
     * `maximumDrawdown` must be reported as "discarded" with the short
     * reason already implied by {@see ValidationResult::$failedCriteria} —
     * this reuses that existing data, it does not add new discard logic.
     */
    public function test_records_discarded_status_with_a_short_reason_when_validation_fails(): void
    {
        // TRAIN: steady rise, clears every criterion with no drawdown.
        $trainCloses = ['100', '101', '102', '103', '104', '105', '106', '107'];
        // VALIDATION: dips 30% before recovering to close a winning trade —
        // the trade itself satisfies every other criterion, but the drawdown
        // incurred while it was open breaches maximumDrawdown.
        $validationCloses = ['200', '200', '140', '220'];
        $candles = $this->candles([...$trainCloses, ...$validationCloses]);

        $provider = new RunAutomaticSearchActionFakeMarketDataProvider($candles);
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
        $scanner = new OpportunityScanner(new RunAutomaticSearchActionFakeUniverseProvider(['BTCUSDT']), $provider);
        $strategy = new RunAutomaticSearchActionPositionalStrategy([2 => SignalType::BUY, 4 => SignalType::SELL]);
        $action = new RunAutomaticSearchAction($searchAction, new ActivateStrategyAction, new StartAutomaticModeAction, $scanner, ['Volatile' => $strategy]);

        $state = AutomaticSearchState::factory()->create();

        $action($state);

        $lastCycle = $state->fresh()->last_cycle;
        $this->assertSame('discarded', $lastCycle['assets'][0]['status']);
        $this->assertSame('riesgo alto', $lastCycle['assets'][0]['reason']);

        // The strategy passed Discovery but failed VALIDATION specifically
        // on maximumDrawdown — the diagnostic must show both stages and the
        // exact VALIDATION metrics StrategyEvaluator already computed for it.
        $strategies = $lastCycle['assets'][0]['strategies'];
        $this->assertCount(1, $strategies);
        $this->assertSame('Volatile', $strategies[0]['name']);
        $this->assertSame('discarded_in_validation', $strategies[0]['status']);
        $this->assertTrue($strategies[0]['discovery']['passed']);
        $this->assertNotNull($strategies[0]['validation']);
        $this->assertFalse($strategies[0]['validation']['passed']);
        $this->assertSame(['maximumDrawdown'], $strategies[0]['validation']['failedCriteria']);
        $this->assertSame(1, $strategies[0]['validation']['metrics']['totalTrades']);
    }

    /**
     * Two candidates that both pass VALIDATION with tied metrics dominate
     * neither each other, so {@see StrategySelector} deliberately selects
     * none (see its own docblock) — the diagnostic breakdown must reflect
     * that as "validated_not_selected" for both, and this diagnostic layer
     * must not change that outcome: still no ActiveStrategy is created and
     * the cycle still reports 0 candidates found, exactly as before this
     * per-strategy detail existed.
     */
    public function test_records_validated_not_selected_status_for_tied_survivors_without_changing_the_decision(): void
    {
        // TRAIN: steady rise, both strategies clear every criterion.
        $trainCloses = ['100', '101', '102', '103', '104', '105', '106', '107'];
        // VALIDATION: identical for both strategies, and both use the exact
        // same signal timing, so their metrics tie exactly.
        $validationCloses = ['300', '301', '302', '303', '304', '305', '306', '307'];
        $candles = $this->candles([...$trainCloses, ...$validationCloses]);

        $provider = new RunAutomaticSearchActionFakeMarketDataProvider($candles);
        $pipeline = new StrategyPipeline(
            evaluator: new StrategyEvaluator,
            selector: new StrategySelector,
            split: new TrainValidationSplit(50),
            minimumTrades: 1,
            minimumWinRate: '50',
            maximumDrawdown: '100',
            minimumProfitLoss: '0',
        );
        $searchAction = new SearchStrategiesAction(new MarketDataStrategyPipelineRunner($provider, $pipeline));
        $scanner = new OpportunityScanner(new RunAutomaticSearchActionFakeUniverseProvider(['BTCUSDT']), $provider);
        $signals = [2 => SignalType::BUY, 4 => SignalType::SELL];
        $strategies = [
            'One' => new RunAutomaticSearchActionPositionalStrategy($signals),
            'Two' => new RunAutomaticSearchActionPositionalStrategy($signals),
        ];
        $action = new RunAutomaticSearchAction($searchAction, new ActivateStrategyAction, new StartAutomaticModeAction, $scanner, $strategies);

        $state = AutomaticSearchState::factory()->create();

        $action($state);

        $this->assertSame(0, ActiveStrategy::query()->count());

        $lastCycle = $state->fresh()->last_cycle;
        $this->assertSame(0, $lastCycle['candidatesFound']);
        $this->assertSame('discarded', $lastCycle['assets'][0]['status']);

        $byName = [];
        foreach ($lastCycle['assets'][0]['strategies'] as $strategy) {
            $byName[$strategy['name']] = $strategy;
        }

        $this->assertSame('validated_not_selected', $byName['One']['status']);
        $this->assertSame('validated_not_selected', $byName['Two']['status']);
        $this->assertTrue($byName['One']['validation']['passed']);
        $this->assertTrue($byName['Two']['validation']['passed']);
    }

    /**
     * Regression test: `strategies_evaluated` must report how many
     * strategies were actually handed to the pipeline, not how many of them
     * survived TRAIN Discovery. Discovery's own thresholds (kept strict
     * here via minimumTrades) can legitimately let 0 candidates through
     * while the catalog itself is never empty — reporting "0 estrategia(s)"
     * in that case would wrongly suggest the catalog was empty.
     */
    public function test_reports_the_number_of_strategies_evaluated_even_when_none_pass_discovery(): void
    {
        $state = AutomaticSearchState::factory()->create();

        $strategies = [
            'A' => new RunAutomaticSearchActionIdleStrategy,
            'B' => new RunAutomaticSearchActionIdleStrategy,
            'C' => new RunAutomaticSearchActionIdleStrategy,
        ];

        ($this->action($strategies, minimumTrades: 1))($state);

        $event = BotEvent::query()->where('event_type', 'strategies_evaluated')->first();
        $this->assertSame('Se evaluaron 3 estrategia(s) sobre BTCUSDT.', $event->message);
    }

    public function test_defaults_to_the_configured_strategy_catalog_when_none_is_given(): void
    {
        $state = AutomaticSearchState::factory()->create(['symbol' => 'BTCUSDT']);

        $provider = new RunAutomaticSearchActionFakeMarketDataProvider($this->candles());
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
        $scanner = new OpportunityScanner(new RunAutomaticSearchActionFakeUniverseProvider(['BTCUSDT']), $provider);
        $action = new RunAutomaticSearchAction($searchAction, new ActivateStrategyAction, new StartAutomaticModeAction, $scanner);

        $action($state);

        $event = BotEvent::query()->where('event_type', 'strategies_evaluated')->first();
        $this->assertSame('Se evaluaron '.count(StrategyCatalog::all()).' estrategia(s) sobre BTCUSDT.', $event->message);
    }

    /**
     * Regression test for the "búsqueda automática iniciada every minute"
     * bug: an exception raised while scanning (e.g. Binance unreachable)
     * must still reschedule `next_search_at` by the configured retry
     * interval, and must keep propagating so
     * {@see AutomaticStrategySearchCommand} can log
     * its `error` BotEvent as it already does.
     */
    public function test_an_exception_during_the_scan_reschedules_the_next_attempt_and_still_propagates(): void
    {
        config(['trading.automatic_search.retry_seconds' => 1800]);
        $state = AutomaticSearchState::factory()->create();

        $provider = new RunAutomaticSearchActionFakeMarketDataProvider($this->candles());
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
        $scanner = new OpportunityScanner(new RunAutomaticSearchActionThrowingUniverseProvider, $provider);
        $action = new RunAutomaticSearchAction($searchAction, new ActivateStrategyAction, new StartAutomaticModeAction, $scanner);

        try {
            $action($state);
            $this->fail('Expected the scanner failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertSame(0, ActiveStrategy::query()->count());
        $this->assertSame(['automatic_search_started'], BotEvent::query()->orderBy('id')->pluck('event_type')->all());

        $fresh = $state->fresh();
        $this->assertNotNull($fresh->next_search_at);
        $this->assertTrue($fresh->next_search_at->gte(CarbonImmutable::now()->addMinutes(29)));
        $this->assertTrue($fresh->next_search_at->lte(CarbonImmutable::now()->addMinutes(31)));
        $this->assertNull($fresh->last_searched_at);
        $this->assertNull($fresh->last_cycle);
    }

    /**
     * @param  array<string, Strategy>  $strategies
     * @param  string[]  $symbols
     */
    private function action(array $strategies, int $minimumTrades = 0, array $symbols = ['BTCUSDT']): RunAutomaticSearchAction
    {
        $provider = new RunAutomaticSearchActionFakeMarketDataProvider($this->candles());
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
        $scanner = new OpportunityScanner(new RunAutomaticSearchActionFakeUniverseProvider($symbols), $provider);

        return new RunAutomaticSearchAction($searchAction, new ActivateStrategyAction, new StartAutomaticModeAction, $scanner, $strategies);
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

final class RunAutomaticSearchActionFakeMarketDataProvider implements MarketDataProvider
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

final class RunAutomaticSearchActionFakeUniverseProvider implements SymbolUniverseProvider
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

/**
 * Simulates the Scanner's Binance call failing (e.g. unreachable), to
 * exercise the mid-search failure path.
 */
final class RunAutomaticSearchActionThrowingUniverseProvider implements SymbolUniverseProvider
{
    public function activeSymbols(string $quoteAsset): array
    {
        throw new RuntimeException('boom');
    }
}

/**
 * Test double that never trades, always returning HOLD — trivially passes
 * the permissive Discovery/Validation thresholds used here, so a single
 * instance of it is always selected, and two tied instances are never
 * dominated by each other.
 */
final class RunAutomaticSearchActionIdleStrategy implements Strategy
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
 * Test double that returns a fixed signal based purely on how many candles
 * it has been given so far (`count($candles)`), defaulting to HOLD. Because
 * it carries no mutable state, the same instance can safely be evaluated
 * twice (once over TRAIN candles, once over VALIDATION candles) — each
 * evaluate() call starts counting from 1 again, so both runs replay the same
 * positional pattern independently. Mirrors StrategyPipelineTest's own
 * double, kept local here so this file's tests don't depend on another test
 * file being loaded first.
 */
final class RunAutomaticSearchActionPositionalStrategy implements Strategy
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
