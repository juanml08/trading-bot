<?php

namespace Tests\Feature;

use App\Actions\Strategy\ActivateStrategyAction;
use App\Actions\Strategy\ActivateTradingCycleAction;
use App\Actions\Strategy\ExpireHoldCyclesAction;
use App\Actions\Strategy\RunAutomaticSearchAction;
use App\Actions\Strategy\SearchStrategiesAction;
use App\Actions\Strategy\StartAutomaticModeAction;
use App\Actions\Strategy\StopAllTradingCyclesAction;
use App\Actions\Strategy\StopTradingCycleAction;
use App\Automation\AutomaticTradingCycle;
use App\MarketData\Candle;
use App\MarketData\MarketDataProvider;
use App\MarketData\SymbolUniverseProvider;
use App\MarketData\Timeframe;
use App\Models\ActiveStrategy;
use App\Models\ActiveTradingCycle;
use App\Models\AutomaticSearchState;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Opportunity\OpportunityScanner;
use App\Orchestration\MarketDataStrategyPipelineRunner;
use App\Strategy\Signal as StrategySignal;
use App\Strategy\SignalType;
use App\Strategy\SmaCrossoverStrategy;
use App\Strategy\Strategy as StrategyContract;
use App\Strategy\StrategyEvaluator;
use App\Strategy\StrategyPipeline;
use App\Strategy\StrategySelector;
use App\Strategy\TrainValidationSplit;
use App\Trading\ActiveTradingCycleState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 4.5 item #16: a single end-to-end run of the sequence the audit asks
 * for — repeated searches, a real BUY, a real SELL, an expiration, another
 * search reusing the freed symbol, and a final StopAll — checking the
 * invariants that actually answer the phase's question ("can the bot run for
 * hours with up to 5 simultaneous cycles without states, strategies, events,
 * trades or slots becoming inconsistent?"). Every individual mechanism this
 * exercises already has its own focused unit/feature test elsewhere; this one
 * only proves they compose correctly across a realistic sequence.
 */
class MultiCycleConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['trading.active_cycles.max_active' => 5]);
    }

    public function test_the_full_multi_cycle_lifecycle_stays_consistent(): void
    {
        $account = TradingAccount::current();
        Strategy::factory()->create(['name' => 'Idle', 'class' => ConsistencyIdleStrategy::class, 'parameters' => []]);

        // 1) 0 ciclos.
        $this->assertSame(0, ActiveTradingCycle::query()->count());
        $this->assertSame(5, $this->freeSlots());

        // 2) automatic:search -> BNB/XRP/ADA/LTC/SOL ocupan los 5 slots.
        $this->runSearch(['BNBUSDT', 'XRPUSDT', 'ADAUSDT', 'LTCUSDT', 'SOLUSDT']);
        $this->assertSame(5, ActiveTradingCycle::query()->active()->count());
        $this->assertSame(0, $this->freeSlots());
        $this->assertNoDuplicateActiveCyclesPerAsset($account->id);

        // 3) repetir la misma búsqueda con los slots llenos -> nada nuevo, nada duplicado.
        $this->runSearch(['BNBUSDT', 'XRPUSDT', 'ADAUSDT', 'LTCUSDT', 'SOLUSDT']);
        $this->assertSame(5, ActiveTradingCycle::query()->count());
        $this->assertNoDuplicateActiveCyclesPerAsset($account->id);

        // 4) BUY real en el ciclo de BNB -> HOLD -> POSITION_OPEN.
        $bnbCycle = $this->cycleFor('BNBUSDT');
        $trader = Strategy::factory()->create([
            'name' => 'Trader',
            'class' => SmaCrossoverStrategy::class,
            'parameters' => ['shortPeriod' => 2, 'longPeriod' => 4],
        ]);
        $bnbCycle->activeStrategy->update(['strategy_id' => $trader->id]);

        (new AutomaticTradingCycle)->process($bnbCycle->activeStrategy->fresh('strategy'), $this->candles($this->risingCloses()));

        $bnbCycle->refresh();
        $this->assertSame(ActiveTradingCycleState::PositionOpen, $bnbCycle->state);
        $this->assertSame(ActiveStrategy::STATUS_RUNNING, $bnbCycle->activeStrategy->fresh()->status);
        $this->assertSame(5, ActiveTradingCycle::query()->active()->count(), 'POSITION_OPEN must still count as active.');
        $trade = Trade::query()->where('active_strategy_id', $bnbCycle->active_strategy_id)->sole();
        $this->assertSame('open', $trade->status);

        // 5) SELL real -> POSITION_OPEN -> CLOSED, ActiveStrategy detenida, slot libre.
        (new AutomaticTradingCycle)->process($bnbCycle->activeStrategy->fresh('strategy'), $this->candles($this->fallingCloses()));

        $bnbCycle->refresh();
        $this->assertSame(ActiveTradingCycleState::Closed, $bnbCycle->state);
        $this->assertSame(ActiveStrategy::STATUS_STOPPED, $bnbCycle->activeStrategy->fresh()->status);
        $this->assertSame('closed', $trade->fresh()->status);
        $this->assertSame(4, ActiveTradingCycle::query()->active()->count());
        $this->assertSame(1, $this->freeSlots());
        $this->assertDatabaseHas('bot_events', [
            'active_trading_cycle_id' => $bnbCycle->id,
            'event_type' => 'cycle_closed',
        ]);

        // 6) nueva búsqueda con BNB de nuevo en la lista (su ciclo anterior ya
        // está CLOSED, así que debe poder reutilizarse) -> exactamente 1
        // ciclo nuevo, los otros 4 intactos.
        $adaBefore = $this->cycleFor('ADAUSDT')->id;
        $this->runSearch(['BNBUSDT', 'XRPUSDT', 'ADAUSDT', 'LTCUSDT', 'SOLUSDT']);
        $this->assertSame(5, ActiveTradingCycle::query()->active()->count());
        $this->assertSame(0, $this->freeSlots());
        $this->assertSame($adaBefore, $this->cycleFor('ADAUSDT')->id, 'An untouched cycle must not be replaced.');
        $this->assertSame(2, ActiveTradingCycle::query()->where('asset_id', $bnbCycle->asset_id)->count(), 'BNB now has exactly two cycle rows: the closed one and the new one.');
        $this->assertNoDuplicateActiveCyclesPerAsset($account->id);

        // 7) uno de los HOLD (XRP) expira -> EXPIRED, ActiveStrategy
        // detenida, slot libre.
        $xrpCycle = $this->cycleFor('XRPUSDT');
        $xrpCycle->update(['expires_at' => now()->subMinute()]);
        (new ExpireHoldCyclesAction)();

        $xrpCycle->refresh();
        $this->assertSame(ActiveTradingCycleState::Expired, $xrpCycle->state);
        $this->assertSame(ActiveStrategy::STATUS_STOPPED, $xrpCycle->activeStrategy->fresh()->status);
        $this->assertSame(4, ActiveTradingCycle::query()->active()->count());
        $this->assertSame(1, $this->freeSlots());
        $this->assertDatabaseHas('bot_events', [
            'active_trading_cycle_id' => $xrpCycle->id,
            'event_type' => 'cycle_expired',
        ]);

        // 8) nueva búsqueda con un símbolo nunca visto -> ocupa el único
        // slot libre, sin tocar los demás. BNB y XRP se dejan fuera de la
        // lista a propósito: ambos ya tienen un ciclo terminal (CLOSED /
        // EXPIRED) y por lo tanto también serían reutilizables, lo que haría
        // ambiguo cuál de los candidatos llena el único slot libre.
        $this->runSearch(['ADAUSDT', 'LTCUSDT', 'SOLUSDT', 'DOTUSDT']);
        $this->assertSame(5, ActiveTradingCycle::query()->active()->count());
        $this->assertSame(0, $this->freeSlots());
        $this->assertNotNull($this->cycleFor('DOTUSDT'));

        // 9) StopAll -> 0 ciclos activos, todas las ActiveStrategy detenidas.
        $stopped = (new StopAllTradingCyclesAction(new StopTradingCycleAction))($account);

        $this->assertSame(5, $stopped);
        $this->assertSame(0, ActiveTradingCycle::query()->active()->count());
        $this->assertSame(5, $this->freeSlots());
        $this->assertSame(0, ActiveStrategy::query()->where('status', ActiveStrategy::STATUS_RUNNING)->count());

        // --- Invariantes finales ---
        $this->assertNoDuplicateActiveCyclesPerAsset($account->id);
        $this->assertNoOrphanRunningStrategies();

        // El Dashboard refleja exactamente lo que hay en base de datos.
        $summary = $this->getJson('/api/cycles/summary')->json();
        $this->assertSame(0, $summary['active_cycles']);
        $this->assertSame(5, $summary['free_slots']);
        $this->assertSame(0, $summary['open_positions']);

        $listed = $this->getJson('/api/cycles')->json('cycles');
        $this->assertSame(ActiveTradingCycle::query()->count(), count($listed));
    }

    private function freeSlots(): int
    {
        $maxActive = (int) config('trading.active_cycles.max_active');

        return max(0, $maxActive - ActiveTradingCycle::query()->active()->count());
    }

    private function cycleFor(string $symbol): ActiveTradingCycle
    {
        return ActiveTradingCycle::query()
            ->whereHas('asset', fn ($query) => $query->where('symbol', $symbol))
            ->latest('id')
            ->firstOrFail();
    }

    private function assertNoDuplicateActiveCyclesPerAsset(int $accountId): void
    {
        $duplicates = ActiveTradingCycle::query()
            ->active()
            ->where('account_id', $accountId)
            ->selectRaw('asset_id, count(*) as total')
            ->groupBy('asset_id')
            ->having('total', '>', 1)
            ->get();

        $this->assertCount(0, $duplicates, 'Found more than one active (HOLD/POSITION_OPEN) cycle for the same asset.');
    }

    private function assertNoOrphanRunningStrategies(): void
    {
        $orphans = ActiveStrategy::query()
            ->where('status', ActiveStrategy::STATUS_RUNNING)
            ->whereDoesntHave('activeTradingCycles', fn ($query) => $query->active())
            ->whereHas('activeTradingCycles')
            ->get();

        $this->assertCount(0, $orphans, 'Found a running ActiveStrategy whose only cycle(s) are already terminal.');
    }

    /**
     * @param  string[]  $symbols
     */
    private function runSearch(array $symbols): void
    {
        $state = AutomaticSearchState::query()->firstOrCreate(
            ['account_id' => TradingAccount::current()->id],
            ['timeframe' => '1h', 'capital' => '500', 'mode' => 'trial', 'status' => AutomaticSearchState::STATUS_RUNNING],
        );

        $provider = new ConsistencyFakeMarketDataProvider($this->candles($this->flatCloses()));
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
        $scanner = new OpportunityScanner(new ConsistencyFakeUniverseProvider($symbols), $provider);
        $activateCycleAction = new ActivateTradingCycleAction(new ActivateStrategyAction);

        $action = new RunAutomaticSearchAction(
            $searchAction,
            $activateCycleAction,
            new StartAutomaticModeAction,
            $scanner,
            ['Idle' => new ConsistencyIdleStrategy],
        );

        $action($state->fresh());
    }

    /**
     * @param  string[]  $closes
     * @return Candle[]
     */
    private function candles(array $closes): array
    {
        $base = CarbonImmutable::parse('2026-01-01 00:00:00');

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

    /**
     * @return string[]
     */
    private function flatCloses(): array
    {
        return ['100', '100', '100', '100', '100', '100', '100', '100', '100', '100'];
    }

    /**
     * A short SMA (2) crossing above a long SMA (4): a BUY on the last candle.
     *
     * @return string[]
     */
    private function risingCloses(): array
    {
        return ['100', '100', '100', '100', '110'];
    }

    /**
     * A short SMA (2) crossing below a long SMA (4): a SELL on the last candle.
     *
     * @return string[]
     */
    private function fallingCloses(): array
    {
        return ['110', '110', '110', '110', '90'];
    }
}

final class ConsistencyFakeMarketDataProvider implements MarketDataProvider
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

final class ConsistencyFakeUniverseProvider implements SymbolUniverseProvider
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
 * Never trades — with the permissive thresholds `runSearch()` configures,
 * it trivially passes Discovery/Validation/Selection for every candidate,
 * so the search pipeline always finds a (non-trading) "candidate" to fill a
 * slot with, exactly like ActiveCyclesIdleStrategy in
 * RunAutomaticSearchActionActiveCyclesTest.
 */
final class ConsistencyIdleStrategy implements StrategyContract
{
    public function generate(array $candles): StrategySignal
    {
        return new StrategySignal(
            type: SignalType::HOLD,
            reason: 'idle test signal',
            generatedAt: CarbonImmutable::now(),
        );
    }
}
