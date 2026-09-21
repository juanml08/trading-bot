<?php

namespace Tests\Feature;

use App\Models\ActiveTradingCycle;
use App\Models\Asset;
use App\Models\BotEvent;
use App\Models\TradingAccount;
use App\Trading\ActiveTradingCyclePresenter;
use App\Trading\ActiveTradingCycleState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers Fase 4A #2: GET /api/cycles/{cycle} returns one cycle's detail,
 * including its chronological history built from real sources only (cycle
 * creation + BotEvents) — see {@see ActiveTradingCyclePresenter}.
 */
class ActiveTradingCycleControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_404_for_a_nonexistent_cycle(): void
    {
        TradingAccount::current();

        $response = $this->getJson('/api/cycles/999999');

        $response->assertNotFound();
    }

    public function test_it_returns_the_cycles_detail(): void
    {
        $account = TradingAccount::current();
        $cycle = ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => Asset::factory()->create(['symbol' => 'BNBUSDT'])->id,
            'state' => ActiveTradingCycleState::Hold,
        ]);

        $response = $this->getJson("/api/cycles/{$cycle->id}");

        $response->assertOk();
        $this->assertSame($cycle->id, $response->json('id'));
        $this->assertSame('BNBUSDT', $response->json('symbol'));
    }

    public function test_the_history_is_ordered_chronologically(): void
    {
        $account = TradingAccount::current();
        $startedAt = CarbonImmutable::parse('2026-09-21 14:00:00');
        $cycle = ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => Asset::factory()->create(['symbol' => 'BNBUSDT'])->id,
            'state' => ActiveTradingCycleState::Closed,
            'started_at' => $startedAt,
        ]);

        // Inserted in true creation order (ascending id), each stamped with
        // a distinct `created_at` matching that order — the realistic case.
        // `created_at` isn't in BotEvent's Fillable list, so it must be
        // force-created to stick instead of being overwritten by the
        // automatic timestamp.
        BotEvent::query()->forceCreate([
            'account_id' => $account->id,
            'active_trading_cycle_id' => $cycle->id,
            'event_type' => 'position_opened',
            'message' => 'BUY',
            'created_at' => $startedAt->addHours(2),
        ]);
        BotEvent::query()->forceCreate([
            'account_id' => $account->id,
            'active_trading_cycle_id' => $cycle->id,
            'event_type' => 'position_closed',
            'message' => 'SELL',
            'created_at' => $startedAt->addHours(4),
        ]);
        // Same second as the previous event on purpose: `position_closed`
        // and the `cycle_closed` it triggers happen in the same request
        // (see AutomaticTradingCycle::handleSell()), so this proves the
        // endpoint orders by insertion (id), not by comparing timestamps
        // that can tie.
        BotEvent::query()->forceCreate([
            'account_id' => $account->id,
            'active_trading_cycle_id' => $cycle->id,
            'event_type' => 'cycle_closed',
            'message' => 'Cycle Closed',
            'created_at' => $startedAt->addHours(4),
        ]);

        $response = $this->getJson("/api/cycles/{$cycle->id}");

        $response->assertOk();
        $this->assertSame(
            ['cycle_created', 'position_opened', 'position_closed', 'cycle_closed'],
            $response->json('history.*.type'),
        );
    }

    public function test_the_history_never_includes_another_cycles_events(): void
    {
        $account = TradingAccount::current();
        $cycle = ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => Asset::factory()->create(['symbol' => 'BNBUSDT'])->id,
            'state' => ActiveTradingCycleState::Closed,
        ]);
        $otherCycle = ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => Asset::factory()->create(['symbol' => 'ADAUSDT'])->id,
            'state' => ActiveTradingCycleState::Hold,
        ]);
        BotEvent::query()->create([
            'account_id' => $account->id,
            'active_trading_cycle_id' => $otherCycle->id,
            'event_type' => 'candle_processed',
            'message' => 'unrelated',
        ]);

        $response = $this->getJson("/api/cycles/{$cycle->id}");

        $this->assertSame(['cycle_created'], $response->json('history.*.type'));
    }

    /**
     * Fase 4.5 audit item #10: once a symbol's earlier cycle closes and a
     * later, unrelated cycle reuses the same symbol, the later cycle's
     * history must not leak the earlier one's events — this only holds
     * because history is built from `active_trading_cycle_id`, never from
     * (account, asset, timestamps).
     */
    public function test_reusing_a_symbol_after_closing_a_cycle_does_not_leak_its_history(): void
    {
        $account = TradingAccount::current();
        $asset = Asset::factory()->create(['symbol' => 'BNBUSDT']);

        $firstCycle = ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => $asset->id,
            'state' => ActiveTradingCycleState::Closed,
        ]);
        BotEvent::query()->create([
            'account_id' => $account->id,
            'active_trading_cycle_id' => $firstCycle->id,
            'event_type' => 'position_opened',
            'message' => 'BUY from cycle 1',
        ]);
        BotEvent::query()->create([
            'account_id' => $account->id,
            'active_trading_cycle_id' => $firstCycle->id,
            'event_type' => 'cycle_closed',
            'message' => 'Cycle 1 closed',
        ]);

        // Same symbol, brand-new cycle — the realistic "BNB closes, later a
        // new BNB opportunity is found" scenario from Fase 3/4.
        $secondCycle = ActiveTradingCycle::factory()->create([
            'account_id' => $account->id,
            'asset_id' => $asset->id,
            'state' => ActiveTradingCycleState::Hold,
        ]);

        $response = $this->getJson("/api/cycles/{$secondCycle->id}");

        $response->assertOk();
        $this->assertSame(['cycle_created'], $response->json('history.*.type'));
        $this->assertSame($secondCycle->id, $response->json('id'));
    }

    public function test_it_returns_404_for_another_accounts_cycle(): void
    {
        TradingAccount::current();
        $otherAccount = TradingAccount::factory()->create();
        $otherCycle = ActiveTradingCycle::factory()->create([
            'account_id' => $otherAccount->id,
            'asset_id' => Asset::factory()->create(['symbol' => 'ADAUSDT'])->id,
            'state' => ActiveTradingCycleState::Hold,
        ]);

        $response = $this->getJson("/api/cycles/{$otherCycle->id}");

        $response->assertNotFound();
    }
}
