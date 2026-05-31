<?php

namespace Tether\Client\Events;

class TetherConflictDetected
{
    public function __construct(
        public readonly string $mutationId,
        public readonly string $model,
        public readonly string $entityId,
        /**
         * @var array<string, mixed>
         */
        public readonly array $serverState,
    ) {}
}
