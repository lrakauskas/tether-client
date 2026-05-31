<?php

use Tether\Client\SyncEngine;
use Tether\Client\SyncResult;
use Tether\Core\Sync\SyncStatus as SyncStatusDto;

use function Pest\Laravel\artisan;
use function Pest\Laravel\mock;

it('install command publishes config and prints next steps', function () {
    artisan('tether:install')
        ->expectsOutputToContain('Installing Tether Client')
        ->expectsOutputToContain('Configuration published')
        ->expectsOutputToContain('TETHER_SERVER_PUSH_URL')
        ->assertSuccessful();
});

it('sync command returns success when no mutations fail', function () {
    mock(SyncEngine::class)
        ->shouldReceive('sync')
        ->once()
        ->andReturn(new SyncResult(pushed: 2, failed: 0, pulled: 3));

    artisan('tether:sync')
        ->expectsOutputToContain('Running Tether sync')
        ->expectsTable(['Pushed', 'Failed', 'Pulled'], [[2, 0, 3]])
        ->expectsOutputToContain('Sync complete')
        ->assertSuccessful();
});

it('sync command returns failure when mutations fail', function () {
    mock(SyncEngine::class)
        ->shouldReceive('sync')
        ->once()
        ->andReturn(new SyncResult(pushed: 1, failed: 2, pulled: 0));

    artisan('tether:sync')
        ->expectsOutputToContain('2 mutation(s) were rejected by the server.')
        ->assertFailed();
});

it('push command returns success when all mutations push', function () {
    mock(SyncEngine::class)
        ->shouldReceive('push')
        ->once()
        ->andReturn(new SyncResult(pushed: 4, failed: 0));

    artisan('tether:push')
        ->expectsOutputToContain('Pushing pending mutations')
        ->expectsTable(['Pushed', 'Failed'], [[4, 0]])
        ->expectsOutputToContain('Push complete')
        ->assertSuccessful();
});

it('push command returns failure when mutations fail', function () {
    mock(SyncEngine::class)
        ->shouldReceive('push')
        ->once()
        ->andReturn(new SyncResult(pushed: 0, failed: 1));

    artisan('tether:push')
        ->expectsOutputToContain('1 mutation(s) were rejected by the server.')
        ->assertFailed();
});

it('pull command runs a pull cycle', function () {
    mock(SyncEngine::class)
        ->shouldReceive('pull')
        ->once()
        ->andReturn(new SyncResult(pulled: 5));

    artisan('tether:pull')
        ->expectsOutputToContain('Pulling from server')
        ->expectsOutputToContain('Pull complete')
        ->assertSuccessful();
});

it('status command renders the current sync status', function () {
    mock(SyncEngine::class)
        ->shouldReceive('syncStatus')
        ->once()
        ->andReturn(new SyncStatusDto(
            pending: 3,
            failed: 1,
            conflicts: 2,
            lastSyncCursor: '123',
            lastSyncAt: '2026-05-19T10:00:00+00:00',
        ));

    artisan('tether:status')
        ->expectsTable(['Key', 'Value'], [
            ['Pending mutations', 3],
            ['Failed mutations', 1],
            ['Conflicted mutations', 2],
            ['Last sync cursor', '123'],
            ['Last sync at', '2026-05-19T10:00:00+00:00'],
        ])
        ->assertSuccessful();
});
