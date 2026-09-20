<?php

namespace Tests\Feature;

use App\Models\BotEvent;
use App\Models\TradingAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomaticEventsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_the_accounts_events_oldest_first(): void
    {
        $account = TradingAccount::factory()->create();
        $this->actingAsAccount($account);
        $first = BotEvent::factory()->create(['account_id' => $account->id, 'message' => 'Bot iniciado']);
        $second = BotEvent::factory()->create(['account_id' => $account->id, 'message' => 'Nueva vela']);

        $response = $this->getJson('/api/automatic/events');

        $response->assertOk()
            ->assertJsonPath('events.0.id', $first->id)
            ->assertJsonPath('events.1.id', $second->id);
    }

    public function test_it_only_returns_events_for_the_current_account(): void
    {
        $account = TradingAccount::factory()->create();
        $other = TradingAccount::factory()->create();
        $this->actingAsAccount($account);
        BotEvent::factory()->create(['account_id' => $other->id]);

        $response = $this->getJson('/api/automatic/events');

        $response->assertOk()->assertJsonCount(0, 'events');
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
