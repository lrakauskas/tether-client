<?php

namespace Tether\Client\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'tether:install';

    protected $description = 'Publish Tether Client configuration and print next steps';

    public function handle(): int
    {
        $this->info('Installing Tether Client...');
        $this->newLine();

        $this->call('vendor:publish', [
            '--tag'  => 'tether-client-config',
            '--ansi' => true,
        ]);

        $this->newLine();
        $this->info('Configuration published.');
        $this->newLine();

        $this->line('Next steps:');
        $this->line('  1. Run migrations:');
        $this->line('       php artisan migrate');
        $this->newLine();
        $this->line('  2. Add the Syncable trait to your local Eloquent models:');
        $this->line('       use Tether\Client\Traits\Syncable;');
        $this->newLine();
        $this->line('  3. Add a tether_id column to each model\'s migration:');
        $this->line('       $table->tetherUlid();');
        $this->newLine();
        $this->line('  4. Set the server URLs in .env:');
        $this->line('       TETHER_SERVER_PUSH_URL=https://yourserver.test/tether/push');
        $this->line('       TETHER_SERVER_PULL_URL=https://yourserver.test/tether/pull');
        $this->newLine();
        $this->line('  5. (Optional) Set TETHER_AUTO_SYNC=true to auto-push to queue on every write.');
        $this->newLine();

        $this->info('Done!');

        return self::SUCCESS;
    }
}
