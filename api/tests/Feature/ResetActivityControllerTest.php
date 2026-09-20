<?php

namespace Tests\Feature;

use App\Models\ActiveStrategy;
use App\Models\AutomaticSearchState;
use App\Models\BotEvent;
use App\Models\Trade;
use App\Models\TradingAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResetActivityControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_all_bot_events_for_the_current_account(): void
    {
        $account = $this->actingAsAccount();
        BotEvent::factory()->count(3)->create(['account_id' => $account->id]);

        $response = $this->deleteJson('/api/automatic/events');

        $response->assertOk();
        $this->assertSame(0, BotEvent::query()->where('account_id', $account->id)->count());
    }

    public function test_it_does_not_delete_trades(): void
    {
        $account = $this->actingAsAccount();
        BotEvent::factory()->create(['account_id' => $account->id]);
        $trade = Trade::factory()->create(['account_id' => $account->id]);

        $this->deleteJson('/api/automatic/events')->assertOk();

        $this->assertNotNull($trade->fresh());
    }

    public function test_it_does_not_delete_the_active_strategy(): void
    {
        $account = $this->actingAsAccount();
        BotEvent::factory()->create(['account_id' => $account->id]);
        $active = ActiveStrategy::factory()->create(['account_id' => $account->id, 'status' => ActiveStrategy::STATUS_RUNNING]);

        $this->deleteJson('/api/automatic/events')->assertOk();

        $this->assertNotNull($active->fresh());
        $this->assertSame(ActiveStrategy::STATUS_RUNNING, $active->fresh()->status);
    }

    public function test_it_does_not_delete_the_automatic_search_state(): void
    {
        $account = $this->actingAsAccount();
        BotEvent::factory()->create(['account_id' => $account->id]);
        $state = AutomaticSearchState::factory()->create(['account_id' => $account->id, 'status' => AutomaticSearchState::STATUS_RUNNING]);

        $this->deleteJson('/api/automatic/events')->assertOk();

        $this->assertNotNull($state->fresh());
        $this->assertSame(AutomaticSearchState::STATUS_RUNNING, $state->fresh()->status);
    }

    /**
     * See {@see AutomaticModeControllerTest::actingAsAccount()}.
     */
    private function actingAsAccount(): TradingAccount
    {
        $account = TradingAccount::factory()->create(['exchange' => 'binance']);

        $user = $account->user;
        $user->forceFill(['email' => 'local@trading-bot.test'])->save();

        return $account;
    }
}
