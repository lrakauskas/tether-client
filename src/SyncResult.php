<?php

namespace Tether\Client;

use Tether\Core\Sync\PushRejection;

class SyncResult
{
    /**
     * @param  list<PushRejection>  $rejections
     * @param  int  $pullErrors  Number of snapshots that failed to apply during pull.
     * @param  bool $skipped     True when the cycle was skipped because another sync was already running.
     */
    public function __construct(
        public readonly int $pushed = 0,
        public readonly int $failed = 0,
        public readonly int $pulled = 0,
        public readonly int $conflicts = 0,
        public readonly array $rejections = [],
        public readonly int $pullErrors = 0,
        public readonly bool $skipped = false,
    ) {}

    /**
     * Return only rejections with a specific reason code.
     *
     * @return list<PushRejection>
     */
    public function rejectionsByReason(string $reason): array
    {
        return array_values(array_filter(
            $this->rejections,
            fn(PushRejection $rejection) => $rejection->reason === $reason,
        ));
    }

    /**
     * Convenience: return all validation_failed rejections, each with their
     * messages array, so callers can surface field errors to the user without
     * querying the database.
     *
     * @return list<PushRejection>
     */
    public function validationErrors(): array
    {
        return $this->rejectionsByReason('validation_failed');
    }
}
