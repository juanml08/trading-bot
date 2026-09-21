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
use App\Models\ActiveStrategy;
use App\Models\ActiveTradingCycle;
use App\Models\Asset;
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
use App\Trading\ActiveTradingCycleState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers Fase 2's connection between the automatic search flow and
 * {@see ActiveTradingCycle}: up to `MAX_ACTIVE_CYCLES` selectable candidates
 * activated per search attempt (not just the first), the account-level
 * active-cycle limit, and the no-duplicate-symbol guard — see this
 * project's Fase 2 spec for the exact scenarios below.
 */
class RunAutomaticSearchActionActiveCyclesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['trading.active_cycles.max_active' => 5]);

        // ActivateStrategyAction looks up the strategy by name, exactly
        // like "Aplicar" does — the Idle test double used throughout this
        // file must exist as a Strategy row for activation to succeed.
        StrategyModel::factory()->create(['name' => 'Idle', 'class' => ActiveCyclesIdleStrategy::class, 'parameters' => []]);
    }

    public function test_a_search_with_one_selectable_candidate_creates_one_cycle_and_one_active_strategy(): void
    {
        $state = AutomaticSearchState::factory()->create();

        ($this->action(symbols: ['AAAUSDT']))($state);

        $this->assertSame(1, ActiveTradingCycle::query()->count());
        $this->assertSame(1, ActiveStrategy::query()->count());

        $cycle = ActiveTradingCycle::query()->first();
        $this->assertSame($cycle->active_strategy_id, ActiveStrategy::query()->first()->id);
        $this->assertSame(ActiveTradingCycleState::Hold, $cycle->state);
    }

    public function test_a_search_with_several_selectable_candidates_creates_a_cycle_for_each_without_stopping_at_the_first(): void
    {
        $state = AutomaticSearchState::factory()->create();

        ($this->action(symbols: ['AAAUSDT', 'BBBUSDT', 'CCCUSDT']))($state);

        $this->assertSame(3, ActiveTradingCycle::query()->count());
        $this->assertSame(3, ActiveStrategy::query()->count());
        $this->assertSame(
            ['AAAUSDT', 'BBBUSDT', 'CCCUSDT'],
            ActiveStrategy::query()->orderBy('id')->pluck('symbol')->all(),
        );
    }

    public function test_no_new_cycle_is_created_once_the_limit_is_reached(): void
    {
        $state = AutomaticSearchState::factory()->create();
        $this->createActiveCycles($state->account_id, 5);

        ($this->action(symbols: ['AAAUSDT', 'BBBUSDT']))($state);

        $this->assertSame(5, ActiveTradingCycle::query()->count());
        $this->assertSame(0, ActiveStrategy::query()->count());
    }

    public function test_only_the_remaining_slots_are_filled_when_some_cycles_already_exist(): void
    {
        $state = AutomaticSearchState::factory()->create();
        $this->createActiveCycles($state->account_id, 3);

        ($this->action(symbols: ['AAAUSDT', 'BBBUSDT', 'CCCUSDT', 'DDDUSDT', 'EEEUSDT']))($state);

        $this->assertSame(5, ActiveTradingCycle::query()->where('account_id', $state->account_id)->count());
        $this->assertSame(2, ActiveStrategy::query()->count());
    }

    public function test_a_closed_cycle_frees_a_slot_for_a_new_one(): void
    {
        $state = AutomaticSearchState::factory()->create();
        $this->createActiveCycles($state->account_id, 4);
        ActiveTradingCycle::factory()->create([
            'account_id' => $state->account_id,
            'asset_id' => Asset::factory()->create(['symbol' => 'PRECLOSEDUSDT'])->id,
            'state' => ActiveTradingCycleState::Closed,
        ]);

        ($this->action(symbols: ['NEWUSDT']))($state);

        $this->assertSame(1, ActiveStrategy::query()->count());
        $this->assertSame(
            5,
            ActiveTradingCycle::query()->active()->where('account_id', $state->account_id)->count(),
        );
    }

    public function test_an_expired_cycle_frees_a_slot_for_a_new_one(): void
    {
        $state = AutomaticSearchState::factory()->create();
        $this->createActiveCycles($state->account_id, 4);
        ActiveTradingCycle::factory()->create([
            'account_id' => $state->account_id,
            'asset_id' => Asset::factory()->create(['symbol' => 'PREEXPIREDUSDT'])->id,
            'state' => ActiveTradingCycleState::Expired,
        ]);

        ($this->action(symbols: ['NEWUSDT']))($state);

        $this->assertSame(1, ActiveStrategy::query()->count());
        $this->assertSame(
            5,
            ActiveTradingCycle::query()->active()->where('account_id', $state->account_id)->count(),
        );
    }

    public function test_a_symbol_already_on_hold_does_not_get_a_second_active_cycle(): void
    {
        $state = AutomaticSearchState::factory()->create();
        $asset = Asset::factory()->create(['symbol' => 'BNBUSDT']);
        ActiveTradingCycle::factory()->create([
            'account_id' => $state->account_id,
            'asset_id' => $asset->id,
            'state' => ActiveTradingCycleState::Hold,
        ]);

        ($this->action(symbols: ['BNBUSDT']))($state);

        $this->assertSame(1, ActiveTradingCycle::query()->where('asset_id', $asset->id)->count());
        $this->assertSame(0, ActiveStrategy::query()->count());
    }

    public function test_a_symbol_can_be_reused_once_its_cycle_is_closed(): void
    {
        $state = AutomaticSearchState::factory()->create();
        $asset = Asset::factory()->create(['symbol' => 'BNBUSDT']);
        ActiveTradingCycle::factory()->create([
            'account_id' => $state->account_id,
            'asset_id' => $asset->id,
            'state' => ActiveTradingCycleState::Closed,
        ]);

        ($this->action(symbols: ['BNBUSDT']))($state);

        $this->assertSame(2, ActiveTradingCycle::query()->where('asset_id', $asset->id)->count());
        $this->assertSame(1, ActiveTradingCycle::query()->active()->where('asset_id', $asset->id)->count());
        $this->assertSame(1, ActiveStrategy::query()->count());
    }

    public function test_activating_a_second_candidate_does_not_stop_the_first_ones_active_strategy(): void
    {
        $state = AutomaticSearchState::factory()->create();

        ($this->action(symbols: ['BNBUSDT', 'ETHUSDT']))($state);

        $this->assertSame(2, ActiveStrategy::query()->where('status', ActiveStrategy::STATUS_RUNNING)->count());
        $this->assertSame(0, ActiveStrategy::query()->where('status', ActiveStrategy::STATUS_STOPPED)->count());
    }

    /**
     * @param  string[]  $symbols
     */
    private function action(array $symbols): RunAutomaticSearchAction
    {
        $provider = new ActiveCyclesFakeMarketDataProvider($this->candles());
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
        $scanner = new OpportunityScanner(new ActiveCyclesFakeUniverseProvider($symbols), $provider);
        $activateCycleAction = new ActivateTradingCycleAction(new ActivateStrategyAction);

        return new RunAutomaticSearchAction(
            $searchAction,
            $activateCycleAction,
            new StartAutomaticModeAction,
            $scanner,
            ['Idle' => new ActiveCyclesIdleStrategy],
        );
    }

    private function createActiveCycles(int $accountId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            ActiveTradingCycle::factory()->create([
                'account_id' => $accountId,
                'asset_id' => Asset::factory()->create(['symbol' => "PRE{$i}USDT"])->id,
                'state' => ActiveTradingCycleState::Hold,
            ]);
        }
    }

    /**
     * @return Candle[]
     */
    private function candles(): array
    {
        $base = CarbonImmutable::parse('2026-01-01 00:00:00');
        $closes = ['100', '101', '102', '103', '104', '105', '106', '107', '108', '109'];

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

final class ActiveCyclesFakeMarketDataProvider implements MarketDataProvider
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

final class ActiveCyclesFakeUniverseProvider implements SymbolUniverseProvider
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
 * Never trades (always HOLD) — with the permissive thresholds `action()`
 * configures (minimumTrades: 0, minimumWinRate: '0', ...), it trivially
 * passes Discovery/Validation/Selection for every candidate symbol.
 */
final class ActiveCyclesIdleStrategy implements Strategy
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
