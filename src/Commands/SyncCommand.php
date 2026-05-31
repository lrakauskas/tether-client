<?php

namespace Tether\Client\Commands;

use Illuminate\Console\Command;
use Tether\Client\SyncEngine;

class SyncCommand extends Command
{
    protected $signature = 'tether:sync';

    protected $description = 'Run a full Tether sync cycle: push pending mutations then pull server delta.';

    public function handle(SyncEngine $engine): int
    {
        $this->info('Running Tether sync…');

        $result = $engine->sync();

        $this->table(
            ['Pushed', 'Failed', 'Pulled'],
            [[$result->pushed, $result->failed, $result->pulled]],
        );

        if ($result->failed > 0) {
            $this->warn("{$result->failed} mutation(s) were rejected by the server.");
        }

        $this->info('Sync complete.');

        return $result->failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
