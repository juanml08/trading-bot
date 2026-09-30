<?php

namespace Tests\Feature;

use App\Models\ActiveTradingCycle;
use App\Models\Asset;
use App\Models\AutomaticSearchState;
use App\Models\BotEvent;
use App\Models\TradingAccount;
use App\Trading\ActiveTradingCycleState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StartAutomaticSearchModeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_creates_a_running_automatic_search_state(): void
    {
        $this->fakeBinance();
        $account = TradingAccount::factory()->create();
        $this->actingAsAccount($account);

        $response = $this->postJson('/api/automatic-search/start', $this->payload());

        $response->assertOk();
        $this->assertDatabaseHas('automatic_search_states', [
            'account_id' => $account->id,
            'status' => AutomaticSearchState::STATUS_RUNNING,
            'timeframe' => '1h',
        ]);
    }

    public function test_starting_logs_that_the_automatic_mode_and_a_search_attempt_started(): void
    {
        $this->fakeBinance();
        $account = TradingAccount::factory()->create();
        $this->actingAsAccount($account);

        $this->postJson('/api/automatic-search/start', $this->payload())->assertOk();

        $eventTypes = BotEvent::query()->where('account_id', $account->id)->pluck('event_type')->all();
        $this->assertContains('automatic_mode_started', $eventTypes);
        $this->assertContains('automatic_search_started', $eventTypes);
    }

    public function test_starting_when_the_account_has_no_free_active_cycle_slot_does_not_search_again(): void
    {
        config(['trading.active_cycles.max_active' => 5]);
        $this->fakeBinance();
        $account = TradingAccount::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            ActiveTradingCycle::factory()->create([
                'account_id' => $account->id,
                'asset_id' => Asset::factory()->create(['symbol' => "PRE{$i}USDT"])->id,
                'state' => ActiveTradingCycleState::Hold,
            ]);
        }
        $this->actingAsAccount($account);

        $response = $this->postJson('/api/automatic-search/start', $this->payload());

        $response->assertOk();
        $eventTypes = BotEvent::query()->where('account_id', $account->id)->pluck('event_type')->all();
        $this->assertNotContains('automatic_search_started', $eventTypes);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/v3/klines'));
    }

    public function test_starting_twice_does_not_re_announce_the_automatic_mode(): void
    {
        $this->fakeBinance();
        $account = TradingAccount::factory()->create();
        AutomaticSearchState::factory()->create(['account_id' => $account->id, 'status' => AutomaticSearchState::STATUS_RUNNING]);
        $this->actingAsAccount($account);

        $this->postJson('/api/automatic-search/start', $this->payload())->assertOk();

        $this->assertSame(
            0,
            BotEvent::query()->where('account_id', $account->id)->where('event_type', 'automatic_mode_started')->count(),
        );
    }

    /**
     * Regression test for the "el experimento pidió 30m pero corrió en 15m"
     * bug: whatever timeframe the request explicitly selects must be exactly
     * what gets persisted — no silent coercion to any other value.
     */
    public function test_starting_with_30m_persists_exactly_30m(): void
    {
        $this->fakeBinance();
        $account = TradingAccount::factory()->create();
        $this->actingAsAccount($account);

        $response = $this->postJson('/api/automatic-search/start', $this->payload(['timeframe' => '30m']));

        $response->assertOk();
        $this->assertDatabaseHas('automatic_search_states', [
            'account_id' => $account->id,
            'timeframe' => '30m',
        ]);
    }

    /**
     * A missing `timeframe` must be rejected outright — there is no default
     * it could silently fall back to (see StartAutomaticSearchRequest).
     */
    public function test_it_rejects_a_missing_timeframe_instead_of_defaulting(): void
    {
        Http::fake();
        $this->actingAsAccount(TradingAccount::factory()->create());

        $payload = $this->payload();
        unset($payload['timeframe']);

        $response = $this->postJson('/api/automatic-search/start', $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors('timeframe');
        $this->assertDatabaseCount('automatic_search_states', 0);
    }

    /**
     * A timeframe outside the bot's supported set must be rejected — the
     * backend never invents or accepts an unsupported value.
     */
    public function test_it_rejects_an_unsupported_timeframe(): void
    {
        Http::fake();
        $this->actingAsAccount(TradingAccount::factory()->create());

        $response = $this->postJson('/api/automatic-search/start', $this->payload(['timeframe' => '20m']));

        $response->assertUnprocessable()->assertJsonValidationErrors('timeframe');
        $this->assertDatabaseCount('automatic_search_states', 0);
    }

    /**
     * The Opportunity Scanner's own timeframe (config('trading.opportunity_scanner.timeframe'),
     * governed by OPPORTUNITY_SCANNER_TIMEFRAME) is a separate concept from
     * the experiment's trading timeframe — changing one must never affect
     * the other. This is the exact confusion that caused the "30m en .env
     * pero 15m en el experimento" incident.
     */
    public function test_the_opportunity_scanner_timeframe_does_not_affect_the_experiment_timeframe(): void
    {
        config(['trading.opportunity_scanner.timeframe' => '5m']);
        $this->fakeBinance();
        $account = TradingAccount::factory()->create();
        $this->actingAsAccount($account);

        $this->postJson('/api/automatic-search/start', $this->payload(['timeframe' => '30m']))->assertOk();

        $this->assertDatabaseHas('automatic_search_states', [
            'account_id' => $account->id,
            'timeframe' => '30m',
        ]);
    }

    /**
     * Starting an experiment must leave its effective configuration
     * auditable later, without reconstructing it from scattered logs — see
     * StartAutomaticSearchModeAction::configSnapshot(). Reuses the existing
     * `bot_events.data` JSON column instead of a new table/column.
     */
    public function test_starting_records_the_effective_experiment_configuration_snapshot(): void
    {
        $this->fakeBinance();
        $account = TradingAccount::factory()->create();
        $this->actingAsAccount($account);

        $this->postJson('/api/automatic-search/start', $this->payload(['timeframe' => '30m', 'capital' => '500']))->assertOk();

        $event = BotEvent::query()
            ->where('account_id', $account->id)
            ->where('event_type', 'automatic_mode_started')
            ->first();

        $this->assertNotNull($event);
        $this->assertNotNull($event->data);
        $this->assertSame('30m', $event->data['timeframe']);
        $this->assertSame('500', $event->data['capital']);
        $this->assertSame('trial', $event->data['mode']);
        $this->assertArrayHasKey('strategies_evaluated', $event->data);
        $this->assertArrayHasKey('lookback_days', $event->data);
        $this->assertArrayHasKey('max_active_cycles', $event->data);
    }

    public function test_it_rejects_mode_real(): void
    {
        Http::fake();
        $this->actingAsAccount(TradingAccount::factory()->create());

        $response = $this->postJson('/api/automatic-search/start', $this->payload(['mode' => 'real']));

        $response->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->assertDatabaseCount('automatic_search_states', 0);
        Http::assertNothingSent();
    }

    public function test_it_rejects_capital_above_the_binance_demo_balance(): void
    {
        $this->fakeBinance(free: '10.00000000');
        $this->actingAsAccount(TradingAccount::factory()->create());

        $response = $this->postJson('/api/automatic-search/start', $this->payload(['capital' => '500']));

        $response->assertUnprocessable()->assertJsonValidationErrors('capital');
        $this->assertDatabaseCount('automatic_search_states', 0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'timeframe' => '1h',
            'capital' => '500',
            'mode' => 'trial',
        ], $overrides);
    }

    private function fakeBinance(string $free = '10000.00000000'): void
    {
        Http::fake([
            '*/api/v3/account*' => Http::response([
                'balances' => [
                    ['asset' => 'USDT', 'free' => $free, 'locked' => '0.00000000'],
                ],
            ], 200),
            '*/api/v3/exchangeInfo*' => Http::response([
                'symbols' => [
                    ['symbol' => 'BTCUSDT', 'status' => 'TRADING', 'quoteAsset' => 'USDT'],
                ],
            ], 200),
            '*/api/v3/klines*' => Http::response($this->klines(), 200),
        ]);
    }

    /**
     * @return array<int, array<int, int|string>>
     */
    private function klines(): array
    {
        $closes = ['100', '101', '102', '103', '104', '105', '106', '107', '108', '109', '110', '111', '109', '108', '110', '112', '111', '113', '114', '115'];

        $base = CarbonImmutable::now()->subDays(30);

        return array_map(
            fn (string $close, int $index): array => [
                $base->addHours($index)->getTimestampMs(),
                $close,
                $close,
                $close,
                $close,
                '1',
            ],
            $closes,
            array_keys($closes),
        );
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
