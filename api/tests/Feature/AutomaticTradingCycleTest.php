<?php

namespace Tests\Feature;

use App\Automation\AutomaticTradingCycle;
use App\MarketData\Candle;
use App\MarketData\Timeframe;
use App\Models\ActiveStrategy;
use App\Models\ActiveTradingCycle;
use App\Models\Asset;
use App\Models\BotEvent;
use App\Models\RiskSetting;
use App\Models\Strategy;
use App\Models\Trade;
use App\Models\TradingAccount;
use App\Trading\ActiveTradingCycleState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomaticTradingCycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_hold_signal_creates_no_trade(): void
    {
        $active = $this->activeStrategy();

        $this->process($active, $this->flatCandles());

        $this->assertDatabaseCount('trades', 0);
    }

    public function test_a_buy_signal_opens_a_position(): void
    {
        $active = $this->activeStrategy();

        $this->process($active, $this->risingCandles());

        $trade = Trade::query()->sole();
        $this->assertSame('open', $trade->status);
        $this->assertSame($active->account_id, $trade->account_id);
        $this->assertSame($active->strategy_id, $trade->strategy_id);
        $this->assertSame($active->id, $trade->active_strategy_id);
    }

    public function test_a_buy_signal_never_opens_a_second_position_for_an_already_open_asset(): void
    {
        $active = $this->activeStrategy();

        $this->process($active, $this->risingCandles());
        $this->process($active, $this->risingCandles());

        $this->assertSame(1, Trade::query()->count());
        $this->assertDatabaseHas('bot_events', ['event_type' => 'signal_ignored_position_open']);
    }

    public function test_a_sell_signal_without_an_open_position_creates_nothing(): void
    {
        $active = $this->activeStrategy();

        $this->process($active, $this->fallingCandles());

        $this->assertDatabaseCount('trades', 0);
        $this->assertDatabaseHas('bot_events', ['event_type' => 'signal_ignored_no_open_position']);
    }

    public function test_a_sell_signal_closes_an_existing_open_position(): void
    {
        $active = $this->activeStrategy();

        $this->process($active, $this->risingCandles());
        $this->process($active, $this->fallingCandles());

        $trade = Trade::query()->sole();
        $this->assertSame('closed', $trade->status);
        $this->assertNotNull($trade->exit_price);
        $this->assertNotNull($trade->profit_loss);
    }

    public function test_an_open_position_survives_between_separate_process_calls(): void
    {
        $active = $this->activeStrategy();

        $this->process($active, $this->risingCandles());

        // A fresh cycle instance and a freshly reloaded ActiveStrategy simulate
        // the process restarting between two scheduler ticks.
        $reloaded = ActiveStrategy::query()->with('strategy')->findOrFail($active->id);
        (new AutomaticTradingCycle)->process($reloaded, $this->candles($this->flatCandles()));

        $trade = Trade::query()->sole();
        $this->assertSame('open', $trade->status);
    }

    public function test_it_records_bot_events_for_opening_and_closing_positions(): void
    {
        $active = $this->activeStrategy();

        $this->process($active, $this->risingCandles());
        $this->process($active, $this->fallingCandles());

        $this->assertDatabaseHas('bot_events', ['event_type' => 'position_opened']);
        $this->assertDatabaseHas('bot_events', ['event_type' => 'position_closed']);
        $this->assertGreaterThanOrEqual(2, BotEvent::query()->where('event_type', 'candle_processed')->count());
    }

    /**
     * Fase 4: a BUY that actually opens a position moves the linked,
     * still-HOLD ActiveTradingCycle to POSITION_OPEN — see
     * AutomaticTradingCycle::handleBuy().
     */
    public function test_a_buy_signal_moves_a_linked_hold_cycle_to_position_open(): void
    {
        $active = $this->activeStrategy();
        $cycle = $this->linkedCycle($active);

        $this->process($active, $this->risingCandles());

        $this->assertSame(ActiveTradingCycleState::PositionOpen, $cycle->fresh()->state);
    }

    /**
     * Fase 4: a SELL that actually closes a position moves the linked
     * POSITION_OPEN cycle to CLOSED, and logs a distinct `cycle_closed`
     * BotEvent (separate from `position_closed`) — see
     * AutomaticTradingCycle::handleSell().
     */
    public function test_a_sell_signal_moves_a_linked_position_open_cycle_to_closed(): void
    {
        $active = $this->activeStrategy();
        $cycle = $this->linkedCycle($active);

        $this->process($active, $this->risingCandles());
        $this->process($active, $this->fallingCandles());

        $this->assertSame(ActiveTradingCycleState::Closed, $cycle->fresh()->state);
        $this->assertDatabaseHas('bot_events', ['event_type' => 'cycle_closed', 'active_trading_cycle_id' => $cycle->id]);
    }

    /**
     * Fase 4.5 audit finding: closing a cycle via a natural SELL must also
     * stop its ActiveStrategy — otherwise it keeps running for a cycle that
     * no longer exists (same bug class as ExpireHoldCyclesActionTest's
     * equivalent coverage).
     */
    public function test_a_sell_that_closes_a_cycle_also_stops_its_active_strategy(): void
    {
        $active = $this->activeStrategy();
        $this->linkedCycle($active);

        $this->process($active, $this->risingCandles());
        $this->process($active, $this->fallingCandles());

        $fresh = $active->fresh();
        $this->assertSame(ActiveStrategy::STATUS_STOPPED, $fresh->status);
        $this->assertNotNull($fresh->stopped_at);
    }

    /**
     * Every BotEvent recorded while processing a candle for an ActiveStrategy
     * with a linked cycle is tagged with that cycle's id, so its history can
     * be read back precisely (see the `active_trading_cycle_id` migration).
     */
    public function test_events_recorded_while_processing_are_tagged_with_the_linked_cycle(): void
    {
        $active = $this->activeStrategy();
        $cycle = $this->linkedCycle($active);

        $this->process($active, $this->risingCandles());

        $this->assertDatabaseHas('bot_events', [
            'event_type' => 'position_opened',
            'active_trading_cycle_id' => $cycle->id,
        ]);
        $this->assertSame(
            0,
            BotEvent::query()->where('event_type', 'position_opened')->whereNull('active_trading_cycle_id')->count(),
        );
    }

    /**
     * Manual mode applies a strategy without ever creating an
     * ActiveTradingCycle (see ActivateStrategyAction vs
     * ActivateTradingCycleAction) — processing it must behave exactly as
     * before, with no cycle to update and no cycle id on its events.
     */
    public function test_an_active_strategy_with_no_linked_cycle_processes_exactly_as_before(): void
    {
        $active = $this->activeStrategy();

        $this->process($active, $this->risingCandles());
        $this->process($active, $this->fallingCandles());

        $trade = Trade::query()->sole();
        $this->assertSame('closed', $trade->status);
        $this->assertSame(0, ActiveTradingCycle::query()->count());
        $this->assertDatabaseHas('bot_events', ['event_type' => 'position_opened', 'active_trading_cycle_id' => null]);
    }

    /**
     * Fase 5: without a RiskSetting configured for the account, the Risk
     * Manager applies only the base capital checks — the full ActiveStrategy
     * capital is used, exactly as before this phase.
     */
    public function test_a_buy_signal_uses_the_full_capital_when_the_account_has_no_risk_setting(): void
    {
        $active = $this->activeStrategy();

        $this->process($active, $this->risingCandles());

        $trade = Trade::query()->sole();
        $this->assertSame('1000.00000000', $trade->capital_used);
    }

    /**
     * Fase 5: with a RiskSetting configured, the capital actually deployed
     * for the trade is capped by max_risk_per_trade (a percentage of the
     * available capital), not the whole ActiveStrategy capital.
     */
    public function test_a_buy_signal_is_capped_by_the_account_risk_setting(): void
    {
        $active = $this->activeStrategy();
        RiskSetting::factory()->create([
            'account_id' => $active->account_id,
            'max_risk_per_trade' => '1.0000',
            'max_open_trades' => 5,
            'max_capital_per_trade' => '1000.00000000',
        ]);

        $this->process($active, $this->risingCandles());

        $trade = Trade::query()->sole();
        $this->assertSame('10.00000000', $trade->capital_used);
    }

    /**
     * Fase 5: a BUY is rejected once the account already has as many open
     * trades as its RiskSetting's max_open_trades allows.
     */
    public function test_a_buy_signal_is_rejected_when_max_open_trades_is_already_reached(): void
    {
        $active = $this->activeStrategy();
        RiskSetting::factory()->create([
            'account_id' => $active->account_id,
            'max_open_trades' => 1,
        ]);
        Trade::factory()->create([
            'account_id' => $active->account_id,
            'asset_id' => Asset::factory()->create(['symbol' => 'ETHUSDT']),
            'status' => 'open',
        ]);

        $this->process($active, $this->risingCandles());

        $this->assertSame(1, Trade::query()->count());
        $this->assertDatabaseHas('bot_events', ['event_type' => 'signal_rejected_by_risk']);
    }

    /**
     * Fase 5 multi-account isolation: a Risk Manager evaluation must never
     * apply another account's RiskSetting.
     */
    public function test_a_buy_signal_does_not_use_another_accounts_risk_setting(): void
    {
        $active = $this->activeStrategy();
        $otherAccount = TradingAccount::factory()->create();
        RiskSetting::factory()->create([
            'account_id' => $otherAccount->id,
            'max_risk_per_trade' => '0.0001',
        ]);

        $this->process($active, $this->risingCandles());

        $trade = Trade::query()->sole();
        $this->assertSame('1000.00000000', $trade->capital_used);
    }

    /**
     * Fase 5: exposure is derived from currently open trades, not reserved
     * separately — once a position closes, it no longer counts against
     * max_open_trades and a later BUY can proceed again.
     */
    public function test_closing_a_position_frees_the_exposure_for_a_later_buy(): void
    {
        $active = $this->activeStrategy();
        RiskSetting::factory()->create([
            'account_id' => $active->account_id,
            'max_open_trades' => 1,
        ]);

        $this->process($active, $this->risingCandles());
        $this->process($active, $this->fallingCandles());
        $this->process($active, $this->risingCandles());

        $this->assertSame(2, Trade::query()->count());
        $this->assertSame(1, Trade::query()->where('status', 'open')->count());
    }

    private function linkedCycle(ActiveStrategy $active): ActiveTradingCycle
    {
        return ActiveTradingCycle::factory()->create([
            'account_id' => $active->account_id,
            'asset_id' => Asset::factory()->create(['symbol' => $active->symbol])->id,
            'strategy_id' => $active->strategy_id,
            'active_strategy_id' => $active->id,
            'state' => ActiveTradingCycleState::Hold,
        ]);
    }

    private function activeStrategy(): ActiveStrategy
    {
        $strategy = Strategy::factory()->create([
            'class' => 'App\Strategy\SmaCrossoverStrategy',
            'parameters' => ['shortPeriod' => 2, 'longPeriod' => 4],
        ]);

        return ActiveStrategy::factory()->create([
            'strategy_id' => $strategy->id,
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'capital' => '1000',
            'status' => ActiveStrategy::STATUS_RUNNING,
        ]);
    }

    private function process(ActiveStrategy $active, array $closes): void
    {
        (new AutomaticTradingCycle)->process($active->fresh('strategy'), $this->candles($closes));
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
    private function flatCandles(): array
    {
        return ['100', '100', '100', '100', '100'];
    }

    /**
     * A short SMA (2) crossing above a long SMA (4): a BUY on the last candle.
     *
     * @return string[]
     */
    private function risingCandles(): array
    {
        return ['100', '100', '100', '100', '110'];
    }

    /**
     * A short SMA (2) crossing below a long SMA (4): a SELL on the last candle.
     *
     * @return string[]
     */
    private function fallingCandles(): array
    {
        return ['110', '110', '110', '110', '90'];
    }
}
