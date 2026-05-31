<?php

namespace Tether\Client\Commands;

use Illuminate\Console\Command;
use Tether\Client\SyncEngine;

class StatusCommand extends Command
{
    protected $signature = 'tether:status';

    protected $description = 'Show current Tether sync status (pending, failed, cursor)';

    public function handle(SyncEngine $engine): int
    {
        $status = $engine->syncStatus();

        $this->table(
            ['Key', 'Value'],
            [
                ['Pending mutations', $status->pending],
                ['Failed mutations',  $status->failed],
                ['Conflicted mutations', $status->conflicts],
                ['Last sync cursor',  $status->lastSyncCursor ?? '-'],
                ['Last sync at',      $status->lastSyncAt ?? '-'],
            ],
        );

        return self::SUCCESS;
    }
}
