<?php

namespace Tether\Client\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Tether\Client\SyncEngine;

/**
 * Queued job that performs a pull-only sync cycle.
 *
 * Dispatch this job whenever you want to fetch the latest server state
 * in the background without pushing local mutations. Safe to use alongside
 * PushJob - the cache lock on SyncEngine ensures they do not run simultaneously.
 */
class PullJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
        $queue = config('tether-client.auto_sync_queue');
        if ($queue !== null) {
            $this->onQueue((string) $queue);
        }
    }

    public function handle(SyncEngine $engine): void
    {
        $engine->pull();
    }
}
