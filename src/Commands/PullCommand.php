<?php

namespace Tether\Client\Commands;

use Illuminate\Console\Command;
use Tether\Client\SyncEngine;

class PullCommand extends Command
{
    protected $signature = 'tether:pull';

    protected $description = 'Pull mutations from the Tether server and apply them locally.';

    public function handle(SyncEngine $engine): int
    {
        $this->info('Pulling from server…');

        $result = $engine->pull();

        $this->table(
            ['Pulled'],
            [[$result->pulled]],
        );

        $this->info('Pull complete.');

        return self::SUCCESS;
    }
}
