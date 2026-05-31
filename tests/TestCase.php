<?php

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Tether\Client\TetherClientServiceProvider;
use Tether\Core\TetherCoreServiceProvider;

class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            TetherCoreServiceProvider::class,
            TetherClientServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom([
            __DIR__ . '/../database/migrations',
            __DIR__ . '/database/migrations',
        ]);
    }
}
