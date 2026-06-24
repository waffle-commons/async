<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Async\Fixture;

use RuntimeException;

/**
 * A value whose destructor throws. Held as a local inside a deferred task that
 * never terminates, it models the real hazard the Fiber-isolation fix guards
 * against: when the abandoned, still-suspended Fiber is destroyed, this
 * destructor runs and throws. The runner must catch that throw (it forces the
 * destruction inside its try/catch) instead of letting it escape as an uncaught
 * fatal that would abort sibling tasks and crash the finish-request flush.
 */
final class ThrowingOnDestruct
{
    public const string MESSAGE = 'destructor blew up during Fiber destruction';

    public function __destruct()
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
