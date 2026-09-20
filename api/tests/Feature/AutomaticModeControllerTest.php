<?php

namespace Tests\Feature;

use App\Models\ActiveStrategy;
use App\Models\Asset;
use App\Models\Trade;
use App\Models\TradingAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomaticModeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_moves_an_applied_strategy_to_running(): void
    {
        $active = ActiveStrategy::factory()->create(['status' => ActiveStrategy::STATUS_APPLIED]);
        $this->actingAsAccount($active->tradingAccount);

        $response = $this->postJson('/api/automatic/start');

        $response->assertOk();
        $this->assertSame(ActiveStrategy::STATUS_RUNNING, $active->fresh()->status);
    }

    public function test_starting_without_an_applied_strategy_fails(): void
    {
        $this->actingAsAccount(TradingAccount::factory()->create());

        $response = $this->postJson('/api/automatic/start');

        $response->assertUnprocessable();
    }

    public function test_stopping_moves_a_running_strategy_to_stopped(): void
    {
        $active = ActiveStrategy::factory()->create(['status' => ActiveStrategy::STATUS_RUNNING]);
        $this->actingAsAccount($active->tradingAccount);

        $response = $this->postJson('/api/automatic/stop');

        $response->assertOk();
        $fresh = $active->fresh();
        $this->assertSame(ActiveStrategy::STATUS_STOPPED, $fresh->status);
        $this->assertNotNull($fresh->stopped_at);
    }

    public function test_stopping_when_not_running_fails(): void
    {
        $active = ActiveStrategy::factory()->create(['status' => ActiveStrategy::STATUS_APPLIED]);
        $this->actingAsAccount($active->tradingAccount);

        $response = $this->postJson('/api/automatic/stop');

        $response->assertUnprocessable();
        $this->assertSame(ActiveStrategy::STATUS_APPLIED, $active->fresh()->status);
    }

    public function test_status_returns_the_current_active_strategy(): void
    {
        $active = ActiveStrategy::factory()->create(['status' => ActiveStrategy::STATUS_RUNNING]);
        $this->actingAsAccount($active->tradingAccount);

        $response = $this->getJson('/api/automatic/status');

        $response->assertOk()->assertJsonPath('activeStrategy.id', $active->id);
    }

    public function test_status_returns_null_when_nothing_was_ever_applied(): void
    {
        $this->actingAsAccount(TradingAccount::factory()->create());

        $response = $this->getJson('/api/automatic/status');

        $response->assertOk()->assertJson([
            'activeStrategy' => null,
            'cycleProfitLoss' => null,
            'nextReview' => null,
            'openPosition' => null,
        ]);
    }

    public function test_cycle_profit_loss_starts_at_zero_for_a_freshly_applied_strategy(): void
    {
        $active = ActiveStrategy::factory()->create(['status' => ActiveStrategy::STATUS_RUNNING]);
        $this->actingAsAccount($active->tradingAccount);

        $response = $this->getJson('/api/automatic/status');

        $response->assertOk()->assertJsonPath('cycleProfitLoss', '0');
    }

    public function test_a_buy_does_not_alter_the_accumulated_cycle_profit_loss(): void
    {
        $active = ActiveStrategy::factory()->create(['status' => ActiveStrategy::STATUS_RUNNING]);
        $this->actingAsAccount($active->tradingAccount);
        Trade::factory()->create([
            'account_id' => $active->account_id,
            'active_strategy_id' => $active->id,
            'status' => 'open',
        ]);

        $response = $this->getJson('/api/automatic/status');

        $response->assertOk()->assertJsonPath('cycleProfitLoss', '0');
    }

    public function test_a_sell_accumulates_into_the_cycle_profit_loss(): void
    {
        $active = ActiveStrategy::factory()->create(['status' => ActiveStrategy::STATUS_RUNNING]);
        $this->actingAsAccount($active->tradingAccount);
        $asset = Asset::factory()->create();
        Trade::factory()->closed('2')->create(['account_id' => $active->account_id, 'active_strategy_id' => $active->id, 'asset_id' => $asset->id]);
        Trade::factory()->closed('-3')->create(['account_id' => $active->account_id, 'active_strategy_id' => $active->id, 'asset_id' => $asset->id]);

        $response = $this->getJson('/api/automatic/status');

        $response->assertOk()->assertJsonPath('cycleProfitLoss', '-1.00000000');
    }

    public function test_cycle_profit_loss_ignores_trades_from_a_previous_active_strategy_assignment(): void
    {
        $account = TradingAccount::factory()->create();
        $previous = ActiveStrategy::factory()->create(['account_id' => $account->id, 'status' => ActiveStrategy::STATUS_STOPPED]);
        Trade::factory()->closed('50')->create(['account_id' => $account->id, 'active_strategy_id' => $previous->id]);
        $current = ActiveStrategy::factory()->create(['account_id' => $account->id, 'status' => ActiveStrategy::STATUS_RUNNING]);
        $this->actingAsAccount($account);

        $response = $this->getJson('/api/automatic/status');

        $response->assertOk()->assertJsonPath('cycleProfitLoss', '0');
    }

    public function test_next_review_is_null_when_no_candle_has_been_evaluated_yet(): void
    {
        $active = ActiveStrategy::factory()->create([
            'status' => ActiveStrategy::STATUS_RUNNING,
            'last_evaluated_at' => null,
        ]);
        $this->actingAsAccount($active->tradingAccount);

        $response = $this->getJson('/api/automatic/status');

        $response->assertOk()->assertJsonPath('nextReview.lastEvaluatedAt', null)
            ->assertJsonPath('nextReview.nextDueAt', null);
    }

    public function test_next_review_reports_the_next_due_time_based_on_the_timeframe(): void
    {
        $lastEvaluatedAt = CarbonImmutable::parse('2026-09-20 11:00:00');
        $active = ActiveStrategy::factory()->create([
            'status' => ActiveStrategy::STATUS_RUNNING,
            'timeframe' => '1h',
            'last_evaluated_at' => $lastEvaluatedAt,
        ]);
        $this->actingAsAccount($active->tradingAccount);

        $response = $this->getJson('/api/automatic/status');

        $response->assertOk()
            ->assertJsonPath('nextReview.lastEvaluatedAt', $lastEvaluatedAt->toJSON())
            ->assertJsonPath('nextReview.nextDueAt', $lastEvaluatedAt->addHour()->toJSON());
    }

    public function test_open_position_is_reported_when_a_trade_is_open(): void
    {
        $active = ActiveStrategy::factory()->create(['status' => ActiveStrategy::STATUS_RUNNING]);
        $this->actingAsAccount($active->tradingAccount);
        Trade::factory()->create([
            'account_id' => $active->account_id,
            'active_strategy_id' => $active->id,
            'entry_price' => '104230',
            'capital_used' => '500',
            'status' => 'open',
        ]);

        $response = $this->getJson('/api/automatic/status');

        $response->assertOk()
            ->assertJsonPath('openPosition.symbol', 'BTCUSDT')
            ->assertJsonPath('openPosition.entryPrice', '104230.000000000000')
            ->assertJsonPath('openPosition.capitalUsed', '500.00000000');
    }

    public function test_open_position_is_null_when_there_is_no_open_trade(): void
    {
        $active = ActiveStrategy::factory()->create(['status' => ActiveStrategy::STATUS_RUNNING]);
        $this->actingAsAccount($active->tradingAccount);
        Trade::factory()->closed('2')->create(['account_id' => $active->account_id, 'active_strategy_id' => $active->id]);

        $response = $this->getJson('/api/automatic/status');

        $response->assertOk()->assertJsonPath('openPosition', null);
    }

    /**
     * The controllers resolve the single local account via
     * {@see TradingAccount::current()}, which finds-or-creates one for a
     * fixed local user/exchange. To exercise a specific account in a test,
     * make it the one `current()` will find by matching that same
     * user_id/exchange pair.
     */
    private function actingAsAccount(TradingAccount $account): void
    {
        $account->forceFill(['exchange' => 'binance'])->save();

        $user = $account->user;
        $user->forceFill(['email' => 'local@trading-bot.test'])->save();
    }
}
