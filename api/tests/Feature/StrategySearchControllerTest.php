<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StrategySearchControllerTest extends TestCase
{
    public function test_it_reaches_the_action_and_returns_json(): void
    {
        $this->fakeBinance();

        $response = $this->postJson('/api/strategies/search', $this->payload());

        $response->assertOk()
            ->assertJsonStructure([
                'selectedCandidate',
                'validationResults',
            ]);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'BTCUSDT'));
    }

    public function test_it_reports_every_configured_strategys_validation_result(): void
    {
        $this->fakeBinance();

        $response = $this->postJson('/api/strategies/search', $this->payload());

        $response->assertOk();

        $names = array_map(
            fn (array $validationResult): string => $validationResult['candidate']['strategyName'],
            $response->json('validationResults'),
        );

        $this->assertSame(['SMA Fast', 'SMA Medium', 'EMA Simple'], $names);
    }

    /**
     * The capital submitted over HTTP must reach StrategyEvaluation
     * unmodified, end to end: SearchStrategiesRequest -> StrategySearchController
     * -> SearchStrategiesAction -> MarketDataStrategyPipelineRunner ->
     * StrategyPipeline -> StrategyEvaluator -> StrategyEvaluation.initialCapital.
     */
    public function test_the_submitted_capital_reaches_every_strategy_evaluation_unchanged(): void
    {
        $this->fakeBinance();

        $response = $this->postJson('/api/strategies/search', $this->payload(['capital' => '20']));

        $response->assertOk();

        $initialCapitals = array_map(
            fn (array $validationResult): array => [
                $validationResult['candidate']['evaluation']['initialCapital'],
                $validationResult['validationEvaluation']['initialCapital'],
            ],
            $response->json('validationResults'),
        );

        foreach ($initialCapitals as [$trainInitialCapital, $validationInitialCapital]) {
            $this->assertSame('20', $trainInitialCapital);
            $this->assertSame('20', $validationInitialCapital);
        }
    }

    public function test_it_rejects_a_missing_symbol(): void
    {
        Http::fake();

        $response = $this->postJson('/api/strategies/search', $this->payload(['symbol' => null]));

        $response->assertUnprocessable()->assertJsonValidationErrors('symbol');
        Http::assertNothingSent();
    }

    public function test_it_rejects_an_invalid_timeframe(): void
    {
        Http::fake();

        $response = $this->postJson('/api/strategies/search', $this->payload(['timeframe' => 'abc']));

        $response->assertUnprocessable()->assertJsonValidationErrors('timeframe');
        Http::assertNothingSent();
    }

    public function test_it_rejects_an_invalid_from_date(): void
    {
        Http::fake();

        $response = $this->postJson('/api/strategies/search', $this->payload(['from' => 'not-a-date']));

        $response->assertUnprocessable()->assertJsonValidationErrors('from');
        Http::assertNothingSent();
    }

    public function test_it_rejects_a_from_date_that_is_not_before_to(): void
    {
        Http::fake();

        $response = $this->postJson('/api/strategies/search', $this->payload([
            'from' => '2026-01-02',
            'to' => '2026-01-01',
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors('to');
        Http::assertNothingSent();
    }

    public function test_it_rejects_zero_capital(): void
    {
        Http::fake();

        $response = $this->postJson('/api/strategies/search', $this->payload(['capital' => '0']));

        $response->assertUnprocessable()->assertJsonValidationErrors('capital');
        Http::assertNothingSent();
    }

    public function test_it_rejects_negative_capital(): void
    {
        Http::fake();

        $response = $this->postJson('/api/strategies/search', $this->payload(['capital' => '-10']));

        $response->assertUnprocessable()->assertJsonValidationErrors('capital');
        Http::assertNothingSent();
    }

    public function test_it_rejects_a_missing_mode(): void
    {
        Http::fake();

        $response = $this->postJson('/api/strategies/search', $this->payload(['mode' => null]));

        $response->assertUnprocessable()->assertJsonValidationErrors('mode');
        Http::assertNothingSent();
    }

    public function test_mode_trial_with_capital_within_balance_is_allowed(): void
    {
        $this->fakeBinance(free: '10000.00000000');

        $response = $this->postJson('/api/strategies/search', $this->payload(['mode' => 'trial', 'capital' => '500']));

        $response->assertOk();
    }

    public function test_mode_trial_with_capital_above_balance_is_rejected(): void
    {
        $this->fakeBinance(free: '10000.00000000');

        $response = $this->postJson('/api/strategies/search', $this->payload(['mode' => 'trial', 'capital' => '12000']));

        $response->assertUnprocessable()->assertJsonValidationErrors('capital');

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/v3/klines'));
    }

    public function test_mode_trial_uses_the_balance_from_binance_demo_not_a_hardcoded_value(): void
    {
        $this->fakeBinance(free: '50.00000000');

        $response = $this->postJson('/api/strategies/search', $this->payload(['mode' => 'trial', 'capital' => '100']));

        $response->assertUnprocessable();
        $response->assertJsonFragment(['capital' => ['El capital solicitado (100) supera el saldo disponible en Binance Demo (50.00000000).']]);
    }

    public function test_mode_real_is_rejected_and_never_reaches_binance_or_strategy_search(): void
    {
        Http::fake();

        $response = $this->postJson('/api/strategies/search', $this->payload(['mode' => 'real']));

        $response->assertUnprocessable()->assertJsonValidationErrors('mode');
        Http::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_filter(array_merge([
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'from' => '2026-01-01',
            'to' => '2026-01-02',
            'capital' => '1000',
            'mode' => 'trial',
        ], $overrides), fn ($value) => $value !== null);
    }

    private function fakeBinance(string $free = '10000.00000000'): void
    {
        Http::fake([
            '*/api/v3/account*' => Http::response([
                'balances' => [
                    ['asset' => 'USDT', 'free' => $free, 'locked' => '0.00000000'],
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

        $base = CarbonImmutable::parse('2026-01-01 00:00:00');

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
}
