<?php

namespace Tests\Feature;

use App\Models\ActiveStrategy;
use App\Models\AutomaticSearchState;
use App\Models\TradingAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StopAutomaticSearchModeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_stopping_moves_a_running_search_state_to_stopped(): void
    {
        $state = AutomaticSearchState::factory()->create(['status' => AutomaticSearchState::STATUS_RUNNING]);
        $this->actingAsAccount($state->tradingAccount);

        $response = $this->postJson('/api/automatic-search/stop');

        $response->assertOk();
        $this->assertSame(AutomaticSearchState::STATUS_STOPPED, $state->fresh()->status);
    }

    public function test_stopping_also_stops_a_running_active_strategy(): void
    {
        $state = AutomaticSearchState::factory()->create(['status' => AutomaticSearchState::STATUS_RUNNING]);
        $active = ActiveStrategy::factory()->create(['account_id' => $state->account_id, 'status' => ActiveStrategy::STATUS_RUNNING]);
        $this->actingAsAccount($state->tradingAccount);

        $this->postJson('/api/automatic-search/stop')->assertOk();

        $this->assertSame(ActiveStrategy::STATUS_STOPPED, $active->fresh()->status);
    }

    public function test_stopping_when_nothing_is_running_fails(): void
    {
        $this->actingAsAccount(TradingAccount::factory()->create());

        $response = $this->postJson('/api/automatic-search/stop');

        $response->assertUnprocessable();
    }

    /**
     * See {@see AutomaticModeControllerTest::actingAsAccount()}.
     */
    private function actingAsAccount(TradingAccount $account): void
    {
        $account->forceFill(['exchange' => 'binance'])->save();

        $user = $account->user;
        $user->forceFill(['email' => 'local@trading-bot.test'])->save();
    }
}
