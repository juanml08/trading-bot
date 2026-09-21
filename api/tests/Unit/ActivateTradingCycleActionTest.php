<?php

namespace Tests\Unit;

use App\Actions\Strategy\ActivateStrategyAction;
use App\Actions\Strategy\ActivateTradingCycleAction;
use App\MarketData\Timeframe;
use App\Models\ActiveStrategy;
use App\Models\ActiveTradingCycle;
use App\Models\Asset;
use App\Models\Strategy as StrategyModel;
use App\Models\TradingAccount;
use App\Trading\ActiveTradingCycleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers {@see ActivateTradingCycleAction}: the atomic pairing of an
 * {@see ActiveStrategy} (via the existing, unmodified
 * {@see ActivateStrategyAction}) with the {@see ActiveTradingCycle} that
 * represents it — the piece RunAutomaticSearchAction uses to connect a
 * selected candidate to the live trading flow.
 */
class ActivateTradingCycleActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_active_strategy_and_a_linked_hold_cycle(): void
    {
        $account = TradingAccount::factory()->create();
        StrategyModel::factory()->create(['name' => 'EMA Simple']);

        $cycle = $this->action()($account, 'EMA Simple', 'BTCUSDT', Timeframe::Hour1, '500', 'trial');

        $this->assertInstanceOf(ActiveTradingCycle::class, $cycle);
        $this->assertSame(ActiveTradingCycleState::Hold, $cycle->state);
        $this->assertSame($account->id, $cycle->account_id);
        $this->assertNotNull($cycle->active_strategy_id);

        $activeStrategy = ActiveStrategy::query()->findOrFail($cycle->active_strategy_id);
        $this->assertSame('BTCUSDT', $activeStrategy->symbol);
        $this->assertSame($cycle->strategy_id, $activeStrategy->strategy_id);
        $this->assertSame('BTCUSDT', $cycle->asset->symbol);
    }

    public function test_expires_at_is_set_to_started_at_plus_the_configured_hold_timeout(): void
    {
        config(['trading.active_cycles.hold_timeout_hours' => 4]);
        $account = TradingAccount::factory()->create();
        StrategyModel::factory()->create(['name' => 'EMA Simple']);

        $cycle = $this->action()($account, 'EMA Simple', 'BTCUSDT', Timeframe::Hour1, '500', 'trial');

        $this->assertNotNull($cycle->expires_at);
        $this->assertSame(
            $cycle->started_at->copy()->addHours(4)->timestamp,
            $cycle->expires_at->timestamp,
        );
    }

    public function test_it_reuses_an_existing_asset_row_for_the_symbol(): void
    {
        $account = TradingAccount::factory()->create();
        StrategyModel::factory()->create(['name' => 'EMA Simple']);
        $asset = Asset::factory()->create(['exchange' => 'binance', 'symbol' => 'BTCUSDT']);

        $cycle = $this->action()($account, 'EMA Simple', 'btcusdt', Timeframe::Hour1, '500', 'trial');

        $this->assertSame($asset->id, $cycle->asset_id);
        $this->assertSame(1, Asset::query()->where('symbol', 'BTCUSDT')->count());
    }

    /**
     * If the cycle insert fails after the strategy was activated, the whole
     * operation must roll back — never leaving an ActiveStrategy without its
     * ActiveTradingCycle.
     */
    public function test_a_failure_creating_the_cycle_rolls_back_the_active_strategy_too(): void
    {
        $account = TradingAccount::factory()->create();
        StrategyModel::factory()->create(['name' => 'EMA Simple']);

        ActiveTradingCycle::creating(function (): void {
            throw new RuntimeException('forced failure for rollback test');
        });

        try {
            try {
                $this->action()($account, 'EMA Simple', 'BTCUSDT', Timeframe::Hour1, '500', 'trial');
                $this->fail('Expected the forced failure to propagate.');
            } catch (RuntimeException $exception) {
                $this->assertSame('forced failure for rollback test', $exception->getMessage());
            }

            $this->assertSame(0, ActiveStrategy::query()->count());
            $this->assertSame(0, ActiveTradingCycle::query()->count());
        } finally {
            ActiveTradingCycle::flushEventListeners();
        }
    }

    private function action(): ActivateTradingCycleAction
    {
        return new ActivateTradingCycleAction(new ActivateStrategyAction);
    }
}
