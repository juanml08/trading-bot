<?php

namespace App\Broker;

/**
 * Immutable result of a (possibly simulated) broker execution attempt.
 *
 * This is a pure data contract for the Broker layer: it carries whether
 * the operation was executed and the resulting execution details. It has
 * no knowledge of strategy decisions, risk authorization, or persistence.
 */
final readonly class BrokerExecution
{
    public function __construct(
        public bool $executed,
        public string $reason,
        public ?string $executedPrice = null,
        public ?string $quantity = null,
        public ?string $capitalUsed = null,
    ) {}
}
