<?php

namespace Tether\Client\Events;

use Tether\Client\SyncResult;

/**
 * Fired at the end of a push-only sync cycle.
 */
class TetherPushCompleted
{
    public function __construct(
        public readonly SyncResult $result,
    ) {}
}
