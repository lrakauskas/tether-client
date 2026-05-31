<?php

namespace Tether\Client\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Tether\Client\SyncEngine;

/**
 * Queued job that performs a push-only sync cycle.
 *
 * Dispatched automatically after Syncable model mutations when
 * 'tether-client.auto_sync' is enabled. Push-only (never pull)
 * to avoid feedback loops where server mutations re-trigger this job.
 *
 * When 'tether-client.auto_sync_throttle' is set to a positive integer,
 * duplicate dispatches within that window (seconds) are silently dropped,
 * coalescing rapid mutations into a single sync attempt. Set to 0 to
 * disable deduplication (default).
 */
class PushJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private string $jobId;

    public function __construct()
    {
        $this->jobId = (string) Str::uuid();

        $queue = config('tether-client.auto_sync_queue');
        if ($queue !== null) {
            $this->onQueue((string) $queue);
        }
    }

    /**
     * The unique ID for the job.
     *
     * When deduplication is disabled (auto_sync_throttle = 0), a fresh UUID
     * is used per dispatch so the lock key is always unique and deduplication
     * never fires. When enabled, all dispatches share the same key scoped to
     * this client, so only the first one within the window is queued.
     */
    public function uniqueId(): string
    {
        if ((int) config('tether-client.auto_sync_throttle', 0) === 0) {
            return $this->jobId;
        }

        return 'tether-push-' . (string) config('tether-client.client_id', '');
    }

    /**
     * The number of seconds the unique lock should be held.
     */
    public function uniqueFor(): int
    {
        return max(0, (int) config('tether-client.auto_sync_throttle', 0));
    }

    public function handle(SyncEngine $engine): void
    {
        $engine->push();
    }
}
