<?php

namespace Tests\Unit;

use App\Strategy\StrategyCandidate;
use App\Strategy\StrategyEvaluation;
use App\Strategy\ValidationResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ValidationResultTest extends TestCase
{
    public function test_a_passing_result_exposes_the_candidate_its_validation_evaluation_and_no_failed_criteria(): void
    {
        $candidate = $this->candidate('A', profitLoss: '2');
        $validationEvaluation = $this->evaluation(profitLoss: '3');

        $result = new ValidationResult(
            candidate: $candidate,
            validationEvaluation: $validationEvaluation,
            passed: true,
        );

        $this->assertSame($candidate, $result->candidate);
        $this->assertSame($validationEvaluation, $result->validationEvaluation);
        $this->assertTrue($result->passed);
        $this->assertSame([], $result->failedCriteria);
    }

    public function test_a_failing_result_preserves_its_failed_criteria_exactly(): void
    {
        $candidate = $this->candidate('B', profitLoss: '2');
        $validationEvaluation = $this->evaluation(profitLoss: '-1');

        $result = new ValidationResult(
            candidate: $candidate,
            validationEvaluation: $validationEvaluation,
            passed: false,
            failedCriteria: ['minimumTrades', 'minimumWinRate', 'minimumProfitLoss'],
        );

        $this->assertFalse($result->passed);
        $this->assertSame(['minimumTrades', 'minimumWinRate', 'minimumProfitLoss'], $result->failedCriteria);
    }

    public function test_a_single_failed_criterion_is_kept_as_a_structured_list_not_as_text(): void
    {
        $result = new ValidationResult(
            candidate: $this->candidate('C', profitLoss: '2'),
            validationEvaluation: $this->evaluation(profitLoss: '1'),
            passed: false,
            failedCriteria: ['minimumTrades'],
        );

        $this->assertIsArray($result->failedCriteria);
        $this->assertSame(['minimumTrades'], $result->failedCriteria);
    }

    public function test_train_and_validation_evaluations_are_preserved_independently(): void
    {
        $trainEvaluation = $this->evaluation(profitLoss: '5');
        $validationEvaluation = $this->evaluation(profitLoss: '-2');
        $candidate = new StrategyCandidate('D', $trainEvaluation);

        $result = new ValidationResult(
            candidate: $candidate,
            validationEvaluation: $validationEvaluation,
            passed: false,
            failedCriteria: ['minimumProfitLoss'],
        );

        $this->assertSame($trainEvaluation, $result->candidate->evaluation);
        $this->assertSame($validationEvaluation, $result->validationEvaluation);
        $this->assertNotSame($result->candidate->evaluation, $result->validationEvaluation);
        $this->assertSame('5', $result->candidate->evaluation->profitLoss);
        $this->assertSame('-2', $result->validationEvaluation->profitLoss);
    }

    public function test_a_passing_result_has_an_empty_failed_criteria_list(): void
    {
        $result = new ValidationResult(
            candidate: $this->candidate('E', profitLoss: '2'),
            validationEvaluation: $this->evaluation(profitLoss: '4'),
            passed: true,
        );

        $this->assertSame([], $result->failedCriteria);
    }

    public function test_the_object_is_immutable_after_construction(): void
    {
        $result = new ValidationResult(
            candidate: $this->candidate('F', profitLoss: '2'),
            validationEvaluation: $this->evaluation(profitLoss: '4'),
            passed: true,
        );

        $this->expectException(\Error::class);

        // @phpstan-ignore-next-line — intentionally attempting to mutate a readonly property.
        $result->passed = false;
    }

    public function test_a_passed_result_cannot_list_failed_criteria(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ValidationResult(
            candidate: $this->candidate('G', profitLoss: '2'),
            validationEvaluation: $this->evaluation(profitLoss: '4'),
            passed: true,
            failedCriteria: ['minimumTrades'],
        );
    }

    public function test_a_failed_result_must_list_at_least_one_failed_criterion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ValidationResult(
            candidate: $this->candidate('H', profitLoss: '2'),
            validationEvaluation: $this->evaluation(profitLoss: '-1'),
            passed: false,
        );
    }

    private function candidate(string $strategyName, string $profitLoss): StrategyCandidate
    {
        return new StrategyCandidate($strategyName, $this->evaluation($profitLoss));
    }

    private function evaluation(string $profitLoss): StrategyEvaluation
    {
        return new StrategyEvaluation(
            initialCapital: '100',
            finalCapital: bcadd('100', $profitLoss, 18),
            profitLoss: $profitLoss,
            profitLossPercentage: bcmul(bcdiv($profitLoss, '100', 18), '100', 18),
            totalTrades: 20,
            winningTrades: 10,
            losingTrades: 10,
            winRate: '50',
            totalProfit: '10',
            totalLoss: '10',
            maxDrawdownPercentage: '5',
            hasOpenPositionAtEnd: false,
        );
    }
}
