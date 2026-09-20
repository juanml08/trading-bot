<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Trade;
use App\Models\TradingAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomaticTradesControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_the_accounts_trades_most_recent_first(): void
    {
        $account = TradingAccount::factory()->create();
        $this->actingAsAccount($account);
        $asset = Asset::factory()->create();
        $first = Trade::factory()->create(['account_id' => $account->id, 'asset_id' => $asset->id]);
        $second = Trade::factory()->create(['account_id' => $account->id, 'asset_id' => $asset->id]);

        $response = $this->getJson('/api/automatic/trades');

        $response->assertOk()
            ->assertJsonPath('trades.0.id', $second->id)
            ->assertJsonPath('trades.1.id', $first->id);
    }

    public function test_it_only_returns_trades_for_the_current_account(): void
    {
        $account = TradingAccount::factory()->create();
        $other = TradingAccount::factory()->create();
        $this->actingAsAccount($account);
        Trade::factory()->create(['account_id' => $other->id]);

        $response = $this->getJson('/api/automatic/trades');

        $response->assertOk()->assertJsonCount(0, 'trades');
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
