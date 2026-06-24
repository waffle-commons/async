<?php

declare(strict_types=1);

namespace Waffle\Commons\Async\Exception;

use RuntimeException;
use Waffle\Commons\Contracts\Async\Exception\DeferralBudgetExceededExceptionInterface;

use function sprintf;

/**
 * Thrown when a request defers more tasks than its bounded budget allows
 * (ASYNC-01).
 *
 * The explicit "move it to a real queue" signal: finish-request deferral trades
 * worker throughput for perceived latency and is not background processing, so
 * the budget is a hard ceiling.
 */
final class DeferralBudgetExceededException extends RuntimeException implements DeferralBudgetExceededExceptionInterface
{
    public function __construct(
        private readonly int $budget,
    ) {
        parent::__construct(sprintf(
            'Per-request deferral budget of %d task(s) exceeded; move this workload to a queue or broker.',
            $budget,
        ));
    }

    #[\Override]
    public function budget(): int
    {
        return $this->budget;
    }
}
