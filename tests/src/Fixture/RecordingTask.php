<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Async\Fixture;

use Closure;
use Waffle\Commons\Contracts\Async\DeferredTaskInterface;

/**
 * A configurable deferred task for {@see \Waffle\Commons\Async\DeferredTaskRunner}
 * tests: its {@see self::run()} body is an injected closure, so a single fixture
 * covers the recording, throwing, and Fiber-suspending scenarios.
 */
final class RecordingTask implements DeferredTaskInterface
{
    /**
     * @param Closure(): void $onRun
     */
    public function __construct(
        private readonly string $name,
        private readonly Closure $onRun,
    ) {}

    #[\Override]
    public function run(): void
    {
        ($this->onRun)();
    }

    #[\Override]
    public function name(): string
    {
        return $this->name;
    }
}
