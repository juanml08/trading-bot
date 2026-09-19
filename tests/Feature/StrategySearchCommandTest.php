<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StrategySearchCommandTest extends TestCase
{
    public function test_it_runs_successfully_with_valid_parameters(): void
    {
        Http::fake([
            '*' => Http::response($this->klines(), 200),
        ]);

        $this->artisan('strategy:search', [
            '--symbol' => 'BTCUSDT',
            '--timeframe' => '1h',
            '--from' => '2026-01-01',
            '--to' => '2026-01-02',
            '--capital' => '1000',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Symbol: BTCUSDT')
            ->expectsOutputToContain('Initial Capital: 1000')
            ->expectsOutputToContain('Validation Results');
    }

    public function test_it_evaluates_every_configured_strategy(): void
    {
        Http::fake([
            '*' => Http::response($this->klines(), 200),
        ]);

        $this->artisan('strategy:search', [
            '--symbol' => 'BTCUSDT',
            '--timeframe' => '1h',
            '--from' => '2026-01-01',
            '--to' => '2026-01-02',
            '--capital' => '1000',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('SMA Fast')
            ->expectsOutputToContain('SMA Medium')
            ->expectsOutputToContain('EMA Simple')
            ->expectsOutputToContain('Selected Strategy');
    }

    public function test_it_reflects_the_pipelines_selected_candidate(): void
    {
        // `expectsOutputToContain` matches each individual line write in
        // isolation, so it cannot distinguish "SMA Fast" appearing in the
        // Validation Results section from it appearing under "Selected
        // Strategy". We assert against the full buffered output instead.
        Http::fake([
            '*' => Http::response($this->oscillatingKlines(), 200),
        ]);

        $exitCode = Artisan::call('strategy:search', [
            '--symbol' => 'BTCUSDT',
            '--timeframe' => '1h',
            '--from' => '2026-01-01',
            '--to' => '2026-01-09',
            '--capital' => '1000',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('SMA Fast: PASSED', $output);
        $this->assertStringContainsString('SMA Medium: PASSED', $output);
        $this->assertStringContainsString('EMA Simple: PASSED', $output);
        $expected = implode(PHP_EOL, ['Selected Strategy', '-----------------', '', 'SMA Fast']);
        $this->assertStringContainsString($expected, $output);
    }

    public function test_it_rejects_an_invalid_timeframe(): void
    {
        Http::fake();

        $this->artisan('strategy:search', [
            '--symbol' => 'BTCUSDT',
            '--timeframe' => 'abc',
            '--from' => '2026-01-01',
            '--to' => '2026-01-02',
            '--capital' => '1000',
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('Invalid --timeframe');

        Http::assertNothingSent();
    }

    public function test_it_rejects_an_invalid_from_date(): void
    {
        Http::fake();

        $this->artisan('strategy:search', [
            '--symbol' => 'BTCUSDT',
            '--timeframe' => '1h',
            '--from' => 'not-a-date',
            '--to' => '2026-01-02',
            '--capital' => '1000',
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('Invalid --from date');

        Http::assertNothingSent();
    }

    public function test_it_rejects_an_invalid_to_date(): void
    {
        Http::fake();

        $this->artisan('strategy:search', [
            '--symbol' => 'BTCUSDT',
            '--timeframe' => '1h',
            '--from' => '2026-01-01',
            '--to' => 'not-a-date',
            '--capital' => '1000',
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('Invalid --to date');

        Http::assertNothingSent();
    }

    public function test_it_rejects_a_from_date_that_is_not_before_to(): void
    {
        Http::fake();

        $this->artisan('strategy:search', [
            '--symbol' => 'BTCUSDT',
            '--timeframe' => '1h',
            '--from' => '2026-01-02',
            '--to' => '2026-01-01',
            '--capital' => '1000',
        ])->assertExitCode(1);
    }

    public function test_it_rejects_zero_capital(): void
    {
        Http::fake();

        $this->artisan('strategy:search', [
            '--symbol' => 'BTCUSDT',
            '--timeframe' => '1h',
            '--from' => '2026-01-01',
            '--to' => '2026-01-02',
            '--capital' => '0',
        ])->assertExitCode(1);
    }

    public function test_it_rejects_negative_capital(): void
    {
        Http::fake();

        $this->artisan('strategy:search', [
            '--symbol' => 'BTCUSDT',
            '--timeframe' => '1h',
            '--from' => '2026-01-01',
            '--to' => '2026-01-02',
            '--capital' => '-10',
        ])->assertExitCode(1);
    }

    public function test_it_reports_a_market_data_failure_cleanly(): void
    {
        Http::fake([
            '*' => Http::response('Internal Server Error', 500),
        ]);

        $this->artisan('strategy:search', [
            '--symbol' => 'BTCUSDT',
            '--timeframe' => '1h',
            '--from' => '2026-01-01',
            '--to' => '2026-01-02',
            '--capital' => '1000',
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('Market data request failed');
    }

    /**
     * @return array<int, array<int, int|string>>
     */
    private function klines(): array
    {
        $closes = ['100', '101', '102', '103', '104', '105', '106', '107', '108', '109', '110', '111', '109', '108', '110', '112', '111', '113', '114', '115'];

        return $this->closesToKlines($closes);
    }

    /**
     * A 200-candle oscillating price series (a sine wave around 100),
     * fixed and precomputed so the resulting ValidationResults —
     * and which strategy the Selector ends up picking — are deterministic.
     *
     * @return array<int, array<int, int|string>>
     */
    private function oscillatingKlines(): array
    {
        $closes = ['100.0000', '103.9734', '107.7884', '111.2928', '114.3471', '116.8294', '118.6408', '119.7090', '119.9915', '119.4770', '118.1859', '116.1699', '113.5093', '110.3100', '106.6998', '102.8224', '98.8325', '94.8892', '91.1496', '87.7628', '84.8640', '82.5685', '80.9680', '80.1262', '80.0767', '80.8215', '82.3309', '84.5447', '87.3747', '90.7080', '94.4117', '98.3382', '102.3310', '106.2308', '109.8823', '113.1397', '115.8734', '117.9742', '119.3584', '119.9709', '119.7872', '118.8146', '117.0920', '114.6879', '111.6983', '108.2424', '104.4578', '100.4955', '96.5135', '92.6704', '89.1196', '86.0025', '83.4435', '81.5445', '80.3813', '80.0002', '80.4164', '81.6134', '83.5434', '86.1295', '89.2685', '92.8354', '96.6879', '100.6725', '104.6302', '108.4033', '111.8415', '114.8075', '117.1832', '118.8739', '119.8121', '119.9605', '119.3132', '117.8958', '115.7650', '113.0058', '109.7280', '106.0624', '102.1551', '98.1619', '94.2419', '90.5516', '87.2379', '84.4330', '82.2487', '80.7721', '80.0620', '80.1468', '81.0231', '82.6560', '84.9803', '87.9033', '91.3087', '95.0605', '99.0093', '102.9975', '106.8663', '110.4613', '113.6393', '116.2735', '118.2589', '119.5164', '119.9959', '119.6781', '118.5759', '116.7331', '114.2232', '111.1463', '107.6250', '103.7997', '99.8230', '95.8533', '92.0489', '88.5615', '85.5301', '83.0756', '81.2958', '80.2617', '80.0145', '80.5640', '81.8884', '83.9349', '86.6218', '89.8421', '93.4673', '97.3530', '101.3442', '105.2818', '109.0088', '112.3767', '115.2512', '117.5176', '119.0857', '119.8929', '119.9070', '119.1275', '117.5855', '115.3423', '112.4875', '109.1349', '105.4181', '101.4853', '97.4933', '93.6012', '89.9642', '86.7273', '84.0196', '81.9489', '80.5979', '80.0204', '80.2394', '81.2462', '83.0006', '85.4328', '88.4457', '91.9192', '95.7149', '99.6815', '103.6607', '107.4940', '111.0285', '114.1234', '116.6552', '118.5230', '119.6524', '119.9982', '119.5469', '118.3162', '116.3553', '113.7424', '110.5817', '106.9990', '103.1374', '99.1506', '95.1978', '91.4363', '88.0163', '85.0741', '82.7269', '81.0683', '80.1644', '80.0514', '80.7336', '82.1839', '84.3445', '87.1292', '90.4271', '94.1066', '98.0210', '102.0143', '105.9274', '109.6041', '112.8979', '115.6776', '117.8322', '119.2759', '119.9511', '119.8310', '118.9203', '117.2552'];

        return $this->closesToKlines($closes);
    }

    /**
     * @param  string[]  $closes
     * @return array<int, array<int, int|string>>
     */
    private function closesToKlines(array $closes): array
    {
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
