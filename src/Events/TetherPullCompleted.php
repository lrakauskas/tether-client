<?php

namespace Tether\Client\Events;

use Tether\Client\SyncResult;

/**
 * Fired at the end of a pull-only sync cycle.
 */
class TetherPullCompleted
{
    public function __construct(
        public readonly SyncResult $result,
    ) {}
}
