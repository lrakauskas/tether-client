<?php

namespace Tether\Client;

use Illuminate\Support\ServiceProvider;
use Tether\Client\Commands\InstallCommand;
use Tether\Client\Commands\PullCommand;
use Tether\Client\Commands\PushCommand;
use Tether\Client\Commands\StatusCommand;
use Tether\Client\Commands\SyncCommand;
use Tether\Core\Identity\UlidGenerator;
use Tether\Core\Mutation\MutationSerializer;
use Tether\Core\Sync\MutationApplicator;

class TetherClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/tether-client.php',
            'tether-client',
        );

        $this->app->singleton(UlidGenerator::class);

        $this->app->singleton(MutationSerializer::class);

        $this->app->singleton(MutationLogger::class, function ($app) {
            return new MutationLogger($app->make(UlidGenerator::class));
        });

        $this->app->singleton(PendingSyncQueue::class);

        $this->app->singleton(SyncStateStore::class);

        $this->app->singleton(ClientIdResolver::class, function ($app) {
            $resolver = new ClientIdResolver(
                store: $app->make(SyncStateStore::class),
                ulids: $app->make(UlidGenerator::class),
            );

            // Support invokable class string from config (config-cache safe)
            $resolverClass = config('tether-client.client_id_resolver');
            if (is_string($resolverClass) && class_exists($resolverClass)) {
                $configuredResolver = new $resolverClass();

                if (is_callable($configuredResolver)) {
                    ClientIdResolver::resolveUsing($configuredResolver);
                }
            }

            return $resolver;
        });

        $this->app->singleton(MutationApplicator::class, function () {
            return new MutationApplicator(
                modelNamespace: config('tether-client.model_namespace', 'App\\Models'),
                syncKeyColumn: config('tether-client.sync_key', 'tether_id'),
            );
        });

        $this->app->singleton(ClientSyncRegistry::class);

        $this->app->singleton(SnapshotApplicator::class, function ($app) {
            return new SnapshotApplicator(
                modelNamespace: config('tether-client.model_namespace', 'App\\Models'),
                syncKeyColumn: config('tether-client.sync_key', 'tether_id'),
                registry: $app->make(ClientSyncRegistry::class),
            );
        });

        $this->app->singleton(SyncHttpClient::class, function ($app) {
            return new SyncHttpClient(
                pushUrl: config('tether-client.server_routes.push', ''),
                pullUrl: config('tether-client.server_routes.pull', ''),
                clientId: $app->make(ClientIdResolver::class)->resolve(),
            );
        });

        $this->app->singleton(SyncEngine::class, function ($app) {
            return new SyncEngine(
                queue: $app->make(PendingSyncQueue::class),
                http: $app->make(SyncHttpClient::class),
                state: $app->make(SyncStateStore::class),
                snapshots: $app->make(SnapshotApplicator::class),
                registry: $app->make(ClientSyncRegistry::class),
                modelNamespace: config('tether-client.model_namespace', 'App\\Models'),
            );
        });

        // Register facade alias so TetherClient:: works out of the box.
        $this->app->alias(SyncEngine::class, 'tether-client');
    }

    /**
     * Register a runtime callable to resolve the client ID.
     * Call this in your AppServiceProvider::boot() method.
     *
     * Example:
     *   TetherClientServiceProvider::resolveClientIdUsing(fn() => auth()->id());
     */
    public static function resolveClientIdUsing(callable $resolver): void
    {
        ClientIdResolver::resolveUsing($resolver);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/tether-client.php' => config_path('tether-client.php'),
            ], 'tether-client-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'tether-client-migrations');

            $this->commands([
                SyncCommand::class,
                PushCommand::class,
                PullCommand::class,
                StatusCommand::class,
                InstallCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
