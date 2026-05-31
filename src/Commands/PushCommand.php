<?php

namespace Tether\Client\Commands;

use Illuminate\Console\Command;
use Tether\Client\SyncEngine;

class PushCommand extends Command
{
    protected $signature = 'tether:push';

    protected $description = 'Push all pending local mutations to the Tether server.';

    public function handle(SyncEngine $engine): int
    {
        $this->info('Pushing pending mutations…');

        $result = $engine->push();

        $this->table(
            ['Pushed', 'Failed'],
            [[$result->pushed, $result->failed]],
        );

        if ($result->failed > 0) {
            $this->warn("{$result->failed} mutation(s) were rejected by the server.");
        }

        $this->info('Push complete.');

        return $result->failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
