<?php

use Tether\Client\ClientIdResolver;
use Tether\Client\SyncStateStore;
use Tether\Core\Identity\UlidGenerator;

afterEach(function (): void {
    ClientIdResolver::forgetResolver();
});

it('uses a registered runtime resolver and memoizes the value', function () {
    $calls = 0;
    ClientIdResolver::resolveUsing(function () use (&$calls): string {
        $calls++;

        return 'runtime-client';
    });

    $resolver = new ClientIdResolver(app(SyncStateStore::class), app(UlidGenerator::class));

    expect($resolver->resolve())->toBe('runtime-client')
        ->and($resolver->resolve())->toBe('runtime-client')
        ->and($calls)->toBe(1);
});

it('uses configured client id before persisted state', function () {
    config([
        'tether-client.client_id' => 'configured-client',
    ]);
    app(SyncStateStore::class)->set('client_id', 'persisted-client');

    $resolver = new ClientIdResolver(app(SyncStateStore::class), app(UlidGenerator::class));

    expect($resolver->resolve())->toBe('configured-client');
});

it('uses persisted client id before generating a new one', function () {
    app(SyncStateStore::class)->set('client_id', 'persisted-client');

    $resolver = new ClientIdResolver(app(SyncStateStore::class), app(UlidGenerator::class));

    expect($resolver->resolve())->toBe('persisted-client');
});

it('generates and persists a client id when none exists', function () {
    config([
        'tether-client.client_id' => '',
    ]);

    $resolver = new ClientIdResolver(app(SyncStateStore::class), app(UlidGenerator::class));
    $clientId = $resolver->resolve();

    expect($clientId)->toHaveLength(26)
        ->and(app(SyncStateStore::class)->get('client_id'))->toBe($clientId);
});
