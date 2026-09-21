<?php

namespace Tests\Feature;

use App\Models\ActiveStrategy;
use App\Models\ActiveTradingCycle;
use App\Models\Asset;
use App\Models\AutomaticSearchState;
use App\Models\BotEvent;
use App\Trading\ActiveTradingCycleState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutomaticStrategySearchCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_not_search_for_a_stopped_automatic_search_state(): void
    {
        Http::fake();
        AutomaticSearchState::factory()->create(['status' => AutomaticSearchState::STATUS_STOPPED]);

        $this->artisan('automatic:search')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_it_does_not_search_when_the_account_has_no_free_active_cycle_slot(): void
    {
        config(['trading.active_cycles.max_active' => 5]);
        Http::fake();
        $state = AutomaticSearchState::factory()->create(['status' => AutomaticSearchState::STATUS_RUNNING]);
        for ($i = 0; $i < 5; $i++) {
            ActiveTradingCycle::factory()->create([
                'account_id' => $state->account_id,
                'asset_id' => Asset::factory()->create(['symbol' => "PRE{$i}USDT"])->id,
                'state' => ActiveTradingCycleState::Hold,
            ]);
        }

        $this->artisan('automatic:search')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_it_does_not_search_again_before_the_next_search_is_due(): void
    {
        Http::fake();
        AutomaticSearchState::factory()->create([
            'status' => AutomaticSearchState::STATUS_RUNNING,
            'last_searched_at' => CarbonImmutable::now()->subMinutes(10),
            'next_search_at' => CarbonImmutable::now()->addMinutes(50),
        ]);

        $this->artisan('automatic:search')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_it_searches_once_the_retry_interval_has_elapsed(): void
    {
        $this->fakeBinanceKlines();
        $state = AutomaticSearchState::factory()->create([
            'status' => AutomaticSearchState::STATUS_RUNNING,
            'last_searched_at' => CarbonImmutable::now()->subHours(2),
            'next_search_at' => CarbonImmutable::now()->subMinutes(1),
        ]);

        $this->artisan('automatic:search')->assertExitCode(0);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v3/exchangeInfo'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v3/klines'));
        $this->assertTrue($state->fresh()->last_searched_at->gt(CarbonImmutable::now()->subMinute()));
    }

    /**
     * Regression test: `isDue()` compares two real UTC instants
     * (`CarbonImmutable::now()` and the `next_search_at` cast), so it must
     * stay correct regardless of a server running in a non-UTC wall clock —
     * simulated here by freezing `now()` and writing `next_search_at`
     * straight to the `datetime` column (as MySQL stores it, naive/UTC, no
     * offset) rather than through a Carbon instance, to rule out any
     * implicit timezone conversion masking a bug.
     */
    public function test_it_is_due_correctly_when_timestamps_are_stored_in_utc(): void
    {
        // A single Http::fake() call for the whole test: Http::fake() merges
        // rather than replaces stub registrations, so an initial blanket
        // `Http::fake()` here would permanently out-rank the later specific
        // exchangeInfo/klines stubs (first matching stub wins) and silently
        // starve the Opportunity Scanner of any symbols.
        $this->fakeBinanceKlines();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-20 18:20:00', 'UTC'));

        $state = AutomaticSearchState::factory()->create([
            'status' => AutomaticSearchState::STATUS_RUNNING,
            'last_searched_at' => '2026-09-20 17:20:00',
            'next_search_at' => '2026-09-20 18:20:01',
        ]);

        $this->artisan('automatic:search')->assertExitCode(0);
        Http::assertNothingSent();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-20 18:20:01', 'UTC'));

        $this->artisan('automatic:search')->assertExitCode(0);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v3/exchangeInfo'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v3/klines'));

        CarbonImmutable::setTestNow();
    }

    public function test_it_searches_for_a_state_that_has_never_searched_yet(): void
    {
        $this->fakeBinanceKlines();
        AutomaticSearchState::factory()->create([
            'status' => AutomaticSearchState::STATUS_RUNNING,
            'last_searched_at' => null,
            'next_search_at' => null,
        ]);

        $this->artisan('automatic:search')->assertExitCode(0);

        $eventTypes = BotEvent::query()->pluck('event_type')->all();
        $this->assertContains('automatic_search_started', $eventTypes);
    }

    public function test_it_schedules_the_next_attempt_when_no_candidate_is_selected(): void
    {
        config(['trading.automatic_search.retry_seconds' => 3600]);
        $this->fakeBinanceKlines();
        $state = AutomaticSearchState::factory()->create([
            'status' => AutomaticSearchState::STATUS_RUNNING,
            'last_searched_at' => null,
            'next_search_at' => null,
        ]);

        $this->artisan('automatic:search')->assertExitCode(0);

        $fresh = $state->fresh();
        $this->assertNull(ActiveStrategy::query()->where('account_id', $state->account_id)->first());
        $this->assertNotNull($fresh->next_search_at);
        $this->assertTrue($fresh->next_search_at->gte(CarbonImmutable::now()->addMinutes(59)));

        $scheduledEvent = BotEvent::query()->where('event_type', 'next_search_scheduled')->first();
        $this->assertNotNull($scheduledEvent);
        // Regression: the message must not bake a server-timezone (UTC)
        // formatted hour into the text — `next_search_at` is already
        // exposed raw via AutomaticModeStatusController for the frontend
        // to localize, and a literal 'H:i' here previously disagreed with
        // that display.
        $this->assertDoesNotMatchRegularExpression('/\d{1,2}:\d{2}/', $scheduledEvent->message);
    }

    public function test_a_failure_for_one_account_is_logged_and_does_not_stop_the_others(): void
    {
        Http::fake([
            '*/api/v3/exchangeInfo*' => Http::response([
                'symbols' => [
                    ['symbol' => 'BTCUSDT', 'status' => 'TRADING', 'quoteAsset' => 'USDT'],
                ],
            ], 200),
            '*/api/v3/klines*' => Http::response('boom', 500),
        ]);

        $broken = AutomaticSearchState::factory()->create([
            'status' => AutomaticSearchState::STATUS_RUNNING,
            'next_search_at' => null,
        ]);

        $this->artisan('automatic:search')->assertExitCode(0);

        $this->assertSame(
            1,
            BotEvent::query()->where('account_id', $broken->account_id)->where('event_type', 'error')->count(),
        );
    }

    /**
     * Regression test for the "búsqueda automática iniciada every minute"
     * bug: a failure partway through a search attempt (here, the Strategy
     * Pipeline's klines request failing) must still push `next_search_at`
     * out by the configured retry interval. Without this, the state stays
     * due forever and every subsequent `everyMinute()` tick retries
     * immediately instead of waiting.
     */
    public function test_a_failed_attempt_reschedules_the_next_attempt_using_the_configured_retry_seconds(): void
    {
        config(['trading.automatic_search.retry_seconds' => 1800]);
        Http::fake([
            '*/api/v3/exchangeInfo*' => Http::response([
                'symbols' => [
                    ['symbol' => 'BTCUSDT', 'status' => 'TRADING', 'quoteAsset' => 'USDT'],
                ],
            ], 200),
            '*/api/v3/klines*' => Http::response('boom', 500),
        ]);

        $broken = AutomaticSearchState::factory()->create([
            'status' => AutomaticSearchState::STATUS_RUNNING,
            'next_search_at' => null,
        ]);

        $this->artisan('automatic:search')->assertExitCode(0);

        $fresh = $broken->fresh();
        $this->assertNotNull($fresh->next_search_at);
        $this->assertTrue($fresh->next_search_at->gte(CarbonImmutable::now()->addMinutes(29)));
        $this->assertTrue($fresh->next_search_at->lte(CarbonImmutable::now()->addMinutes(31)));
    }

    /**
     * A state that just failed must not be picked up again on the very next
     * scheduler tick — it should stay skipped until the rescheduled
     * `next_search_at` is actually reached.
     */
    public function test_a_state_that_just_failed_is_not_due_again_on_the_next_tick(): void
    {
        config(['trading.automatic_search.retry_seconds' => 1800]);
        Http::fake([
            '*/api/v3/exchangeInfo*' => Http::response([
                'symbols' => [
                    ['symbol' => 'BTCUSDT', 'status' => 'TRADING', 'quoteAsset' => 'USDT'],
                ],
            ], 200),
            '*/api/v3/klines*' => Http::response('boom', 500),
        ]);

        $broken = AutomaticSearchState::factory()->create([
            'status' => AutomaticSearchState::STATUS_RUNNING,
            'next_search_at' => null,
        ]);

        $this->artisan('automatic:search')->assertExitCode(0);
        $this->assertSame(
            1,
            BotEvent::query()->where('account_id', $broken->account_id)->where('event_type', 'automatic_search_started')->count(),
        );

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        $this->artisan('automatic:search')->assertExitCode(0);
        CarbonImmutable::setTestNow();

        $this->assertSame(
            1,
            BotEvent::query()->where('account_id', $broken->account_id)->where('event_type', 'automatic_search_started')->count(),
        );
    }

    private function fakeBinanceKlines(): void
    {
        $closes = ['100', '101', '102', '103', '104', '105', '106', '107', '108', '109', '110', '111', '109', '108', '110', '112', '111', '113', '114', '115'];
        $base = CarbonImmutable::now()->subDays(30);

        $klines = array_map(
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

        Http::fake([
            '*/api/v3/exchangeInfo*' => Http::response([
                'symbols' => [
                    ['symbol' => 'BTCUSDT', 'status' => 'TRADING', 'quoteAsset' => 'USDT'],
                ],
            ], 200),
            '*/api/v3/klines*' => Http::response($klines, 200),
        ]);
    }
}
