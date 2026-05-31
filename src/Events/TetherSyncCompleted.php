<?php

namespace Tether\Client\Events;

use Tether\Client\SyncResult;

/**
 * Fired at the end of a full sync cycle (push + pull).
 */
class TetherSyncCompleted
{
    public function __construct(
        public readonly SyncResult $result,
    ) {}
}
