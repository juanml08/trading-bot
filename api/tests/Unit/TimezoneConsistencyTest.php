<?php

namespace Tests\Unit;

use App\Actions\Strategy\ExpireHoldCyclesAction;
use App\Models\ActiveStrategy;
use App\Models\ActiveTradingCycle;
use App\Models\Trade;
use App\MarketData\Timeframe;
use App\Trading\ActiveTradingCycleState;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auditoría de timezone (ver reglas.md): fija en código la regla que debe
 * cumplirse siempre — persistencia y lógica interna en UTC, presentación en
 * America/Bogota — para que una regresión futura (p. ej. alguien vuelve a
 * fijar `app.timezone` a algo distinto de UTC, o asume la hora local del
 * servidor) rompa un test en vez de descubrirse en producción.
 */
class TimezoneConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_laravel_and_php_run_in_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
    }

    public function test_a_utc_instant_persists_and_reads_back_as_the_same_instant(): void
    {
        $openedAt = CarbonImmutable::parse('2026-09-30 02:00:00', 'UTC');

        $trade = Trade::factory()->create(['status' => 'open', 'opened_at' => $openedAt]);

        $this->assertTrue($trade->fresh()->opened_at->equalTo($openedAt));
    }

    public function test_a_utc_timestamp_displays_correctly_in_bogota(): void
    {
        // 2026-09-30T02:00:00Z es 2026-09-29 21:00 en America/Bogota (UTC-5),
        // el ejemplo exacto que pide la auditoría.
        $utc = CarbonImmutable::parse('2026-09-30T02:00:00Z');

        $bogota = $utc->setTimezone('America/Bogota');

        $this->assertSame('2026-09-29 21:00:00', $bogota->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30T02:00:00.000000Z', $utc->toJSON());
    }

    /**
     * Simula un servidor cuyo timezone por defecto no es UTC (el escenario
     * real que causó las discrepancias): las duraciones deben depender
     * únicamente del instante (epoch), nunca del timezone con el que PHP
     * interpretaría una hora "naive".
     */
    public function test_duration_calculations_do_not_depend_on_the_servers_default_timezone(): void
    {
        $openedAt = CarbonImmutable::parse('2026-09-29 10:00:00', 'UTC');
        $closedAt = CarbonImmutable::parse('2026-09-29 16:30:00', 'UTC');

        $this->assertSame(390.0, $openedAt->diffInMinutes($closedAt));

        // El mismo par de instantes, vistos por un proceso cuyo timezone por
        // defecto no es UTC, debe seguir dando la misma duración: la
        // comparación es sobre el instante real, no sobre la hora "de pared".
        date_default_timezone_set('America/Bogota');
        $this->assertSame(390.0, $openedAt->diffInMinutes($closedAt));
    }

    /**
     * HOLD timeout (ExpireHoldCyclesAction): debe expirar el ciclo por haber
     * pasado su `expires_at`, sin importar el timezone por defecto del
     * proceso que lo ejecuta (simula un servidor en UTC-5, como el que
     * reportó `@@session.time_zone = SYSTEM`).
     */
    public function test_hold_timeout_is_correct_regardless_of_server_default_timezone(): void
    {
        date_default_timezone_set('America/Bogota');

        $cycle = ActiveTradingCycle::factory()->create([
            'state' => ActiveTradingCycleState::Hold,
            'started_at' => CarbonImmutable::now()->subHours(5),
            'expires_at' => CarbonImmutable::now()->subHour(),
        ]);

        (new ExpireHoldCyclesAction)();

        $this->assertSame(ActiveTradingCycleState::Expired, $cycle->fresh()->state);
    }

    /**
     * Max holding (AutomaticTradingCycle::maybeExitOnRisk — ver
     * config('trading.risk_exit.max_holding_hours')): la comparación es
     * `opened_at->addHours($n)->lte(now())`, un contraste de instantes que
     * debe dar el mismo resultado sin importar el timezone por defecto.
     */
    public function test_max_holding_comparison_is_correct_regardless_of_server_default_timezone(): void
    {
        $openedAt = CarbonImmutable::now()->subHours(7);
        $maxHoldingHours = 6;

        date_default_timezone_set('UTC');
        $pastDeadlineUtc = $openedAt->copy()->addHours($maxHoldingHours)->lte(CarbonImmutable::now());

        date_default_timezone_set('America/Bogota');
        $pastDeadlineBogota = $openedAt->copy()->addHours($maxHoldingHours)->lte(CarbonImmutable::now());

        $this->assertTrue($pastDeadlineUtc);
        $this->assertSame($pastDeadlineUtc, $pastDeadlineBogota);
    }

    /**
     * `next_evaluation_at` (ActiveStrategy::nextEvaluationAt()) se calcula
     * sumando el timeframe a `last_evaluated_at` — debe seguir comparando
     * correctamente contra "ahora" sin importar el timezone por defecto.
     */
    public function test_next_evaluation_at_compares_correctly_regardless_of_server_default_timezone(): void
    {
        date_default_timezone_set('America/Bogota');

        $lastEvaluatedAt = CarbonImmutable::now()->subMinutes(20);

        $strategy = ActiveStrategy::factory()->create([
            'timeframe' => Timeframe::Minute15->value,
            'last_evaluated_at' => $lastEvaluatedAt,
        ]);

        $nextEvaluationAt = $strategy->nextEvaluationAt();

        $this->assertNotNull($nextEvaluationAt);
        $this->assertTrue($nextEvaluationAt->lte(CarbonImmutable::now()));
    }
}
