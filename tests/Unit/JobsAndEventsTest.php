<?php

use Illuminate\Support\Facades\Queue;
use Tether\Client\Events\TetherConflictDetected;
use Tether\Client\Jobs\PullJob;
use Tether\Client\Jobs\PushJob;
use Tether\Client\SyncEngine;

use function Pest\Laravel\mock;

it('push job handle calls push on the sync engine', function () {
    $engine = mock(SyncEngine::class)
        ->shouldReceive('push')
        ->once()
        ->getMock();

    (new PushJob())->handle($engine);
});

it('pull job uses the configured queue name', function () {
    Queue::fake();

    config([
        'tether-client.auto_sync_queue' => 'sync',
    ]);

    PullJob::dispatch();

    Queue::assertPushedOn('sync', PullJob::class);
});

it('pull job handle calls pull on the sync engine', function () {
    $engine = mock(SyncEngine::class)
        ->shouldReceive('pull')
        ->once()
        ->getMock();

    (new PullJob())->handle($engine);
});

it('conflict detected event exposes conflict context', function () {
    $event = new TetherConflictDetected(
        mutationId: 'mutation-1',
        model: 'Post',
        entityId: 'entity-1',
        serverState: [
            'title' => 'Server',
        ],
    );

    expect($event->mutationId)->toBe('mutation-1')
        ->and($event->model)->toBe('Post')
        ->and($event->entityId)->toBe('entity-1')
        ->and($event->serverState)->toBe([
            'title' => 'Server',
        ]);
});
