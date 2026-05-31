<?php

use Illuminate\Database\Schema\Blueprint;
use Tether\Client\ClientIdResolver;
use Tether\Client\ClientSyncRegistry;
use Tether\Client\Facades\TetherClient;
use Tether\Client\MutationLogger;
use Tether\Client\PendingSyncQueue;
use Tether\Client\SnapshotApplicator;
use Tether\Client\SyncEngine;
use Tether\Client\SyncHttpClient;
use Tether\Client\SyncStateStore;
use Tether\Client\TetherClientServiceProvider;
use Tether\Core\Identity\UlidGenerator;
use Tether\Core\Sync\MutationApplicator;
use Tether\Core\Mutation\MutationSerializer;

afterEach(function (): void {
    ClientIdResolver::forgetResolver();
});

it('registers core client services and facade alias', function () {
    expect(app(UlidGenerator::class))->toBeInstanceOf(UlidGenerator::class)
        ->and(app(MutationSerializer::class))->toBeInstanceOf(MutationSerializer::class)
        ->and(app(MutationLogger::class))->toBeInstanceOf(MutationLogger::class)
        ->and(app(PendingSyncQueue::class))->toBeInstanceOf(PendingSyncQueue::class)
        ->and(app(SyncStateStore::class))->toBeInstanceOf(SyncStateStore::class)
        ->and(app(MutationApplicator::class))->toBeInstanceOf(MutationApplicator::class)
        ->and(app(ClientSyncRegistry::class))->toBeInstanceOf(ClientSyncRegistry::class)
        ->and(app(SnapshotApplicator::class))->toBeInstanceOf(SnapshotApplicator::class)
        ->and(app(SyncHttpClient::class))->toBeInstanceOf(SyncHttpClient::class)
        ->and(app(SyncEngine::class))->toBeInstanceOf(SyncEngine::class)
        ->and(app('tether-client'))->toBe(app(SyncEngine::class))
        ->and(TetherClient::getFacadeRoot())->toBe(app(SyncEngine::class));
});

it('registers configured invokable client id resolver classes', function () {
    config([
        'tether-client.client_id_resolver' => ConfiguredClientIdResolver::class,
    ]);

    app()->forgetInstance(ClientIdResolver::class);

    expect(app(ClientIdResolver::class)->resolve())->toBe('configured-invokable-client');
});

it('ignores non-callable configured resolver classes', function () {
    config([
        'tether-client.client_id' => 'fallback-client',
        'tether-client.client_id_resolver' => NonCallableClientIdResolver::class,
    ]);

    app()->forgetInstance(ClientIdResolver::class);

    expect(app(ClientIdResolver::class)->resolve())->toBe('fallback-client');
});

it('exposes runtime client id resolver registration helper', function () {
    TetherClientServiceProvider::resolveClientIdUsing(fn(): string => 'helper-client');

    app()->forgetInstance(ClientIdResolver::class);

    expect(app(ClientIdResolver::class)->resolve())->toBe('helper-client');
});

it('registers tether blueprint macros through the core provider', function () {
    expect(Blueprint::hasMacro('tetherUlid'))->toBeTrue()
        ->and(Blueprint::hasMacro('dropTetherUlid'))->toBeTrue();
});

class ConfiguredClientIdResolver
{
    public function __invoke(): string
    {
        return 'configured-invokable-client';
    }
}

class NonCallableClientIdResolver {}
