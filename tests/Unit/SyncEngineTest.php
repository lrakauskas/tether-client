<?php

use Tests\Models\TestPost;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tether\Client\Events\TetherConflictDetected;
use Tether\Client\Events\TetherPullCompleted;
use Tether\Client\Events\TetherPushCompleted;
use Tether\Client\Events\TetherSyncCompleted;
use Tether\Client\ClientSyncRegistry;
use Tether\Client\Models\MutationLog;
use Tether\Client\PendingSyncQueue;
use Tether\Client\SnapshotApplicator;
use Tether\Client\SyncEngine;
use Tether\Client\SyncHttpClient;
use Tether\Client\SyncStateStore;
use Tether\Core\Enums\SyncStatus;
use Tether\Core\Mutation\Mutation;
use Tether\Core\Sync\PullResult;
use Tether\Core\Sync\PushResult;

use function Pest\Laravel\mock;

function makeSnapshots(): SnapshotApplicator
{
    return new SnapshotApplicator(
        modelNamespace: 'Tests\\Models',
        syncKeyColumn: 'tether_id',
        registry: new ClientSyncRegistry(),
    );
}

function pushResult(array $data): PushResult
{
    return PushResult::fromArray($data);
}

function pullResult(array $data): PullResult
{
    return PullResult::fromArray($data);
}

// ── Push ──────────────────────────────────────────────────────────────────────

it('push sends pending mutations and marks them as synced', function () {
    $post = TestPost::create([
        'title' => 'Offline Post',
        'body' => null,
    ]);

    $mutationId = MutationLog::first()->mutation_id;

    $http = mock(SyncHttpClient::class)
        ->shouldReceive('push')
        ->once()
        ->andReturn(pushResult([
            'applied'   => [$mutationId],
            'rejected'  => [],
            'conflicts' => [],
        ]))
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
    );

    $engine->push();

    expect(MutationLog::first()->sync_status)->toBe(SyncStatus::Synced);
});

it('push marks mutations as failed when the server rejects them', function () {
    TestPost::create([
        'title' => 'Offline Post',
        'body' => null,
    ]);

    $mutationId = MutationLog::first()->mutation_id;

    $http = mock(SyncHttpClient::class)
        ->shouldReceive('push')
        ->once()
        ->andReturn(pushResult([
            'applied'   => [],
            'rejected'  => [[
                'mutation_id' => $mutationId,
                'reason' => 'error',
            ]],
            'conflicts' => [],
        ]))
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
    );

    $engine->push();

    expect(MutationLog::first()->sync_status)->toBe(SyncStatus::Failed);
});

it('push stores structured rejection data as an array when server returns it', function () {
    TestPost::create([
        'title' => 'Offline Post',
        'body' => null,
    ]);

    $mutationId = MutationLog::first()->mutation_id;

    $http = mock(SyncHttpClient::class)
        ->shouldReceive('push')
        ->once()
        ->andReturn(pushResult([
            'applied'   => [],
            'rejected'  => [[
                'mutation_id' => $mutationId,
                'reason'      => 'validation_failed',
                'data'        => [
                    'messages' => [
                        'title' => ['Too short.'],
                    ],
                ],
            ]],
            'conflicts' => [],
        ]))
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
    );

    $result = $engine->push();

    $log = MutationLog::first();
    expect($log->sync_status)->toBe(SyncStatus::Failed)
        ->and($log->rejection_reason)->toBe('validation_failed')
        ->and($log->rejection_data)->toBe([
            'messages' => [
                'title' => ['Too short.'],
            ],
        ]);

    expect($result->rejections[0]->data)->toBe([
        'messages' => [
            'title' => ['Too short.'],
        ],
    ]);
});

it('push does nothing when there are no pending mutations', function () {
    $http = mock(SyncHttpClient::class)
        ->shouldNotReceive('push')
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
    );

    $result = $engine->push();

    expect($result->pushed)->toBe(0)
        ->and($result->failed)->toBe(0)
        ->and($result->conflicts)->toBe(0)
        ->and($result->rejections)->toBe([]);
});

it('push automatically retries error-failed mutations on the next call', function () {
    TestPost::create([
        'title' => 'Retry Me',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;

    // Simulate a prior failed push - marks mutation as Failed with reason 'error'
    $httpFail = mock(SyncHttpClient::class)
        ->shouldReceive('push')
        ->once()
        ->andReturn(pushResult([
            'applied'   => [],
            'rejected'  => [[
                'mutation_id' => $mutationId,
                'reason' => 'error',
                'data' => [],
            ]],
            'conflicts' => [],
        ]))
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $httpFail,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
    );

    $engine->push();
    expect(MutationLog::first()->sync_status)->toBe(SyncStatus::Failed);

    // Second push - error-failed mutation is automatically re-queued and retried
    $httpRetry = mock(SyncHttpClient::class)
        ->shouldReceive('push')
        ->once()
        ->andReturn(pushResult([
            'applied' => [$mutationId],
            'rejected' => [],
            'conflicts' => [],
        ]))
        ->getMock();

    $engine2 = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $httpRetry,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
    );

    $engine2->push();

    expect(MutationLog::first()->sync_status)->toBe(SyncStatus::Synced);
});

it('push does not retry after max_retry_attempts is exhausted', function () {
    TestPost::create([
        'title' => 'Exhausted',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;

    // Set retry_count to max
    MutationLog::where('mutation_id', $mutationId)->update([
        'sync_status'      => 'failed',
        'rejection_reason' => 'error',
        'retry_count'      => 3,
    ]);

    $http = mock(SyncHttpClient::class)
        ->shouldNotReceive('push')
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
    );

    $result = $engine->push();

    expect($result->pushed)->toBe(0)
        ->and($result->failed)->toBe(0)
        ->and($result->conflicts)->toBe(0)
        ->and($result->rejections)->toBe([])
        ->and(MutationLog::first()->sync_status)->toBe(SyncStatus::Failed);
});

it('push does not retry validation_failed mutations', function () {
    TestPost::create([
        'title' => 'Invalid',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;

    MutationLog::where('mutation_id', $mutationId)->update([
        'sync_status'      => 'failed',
        'rejection_reason' => 'validation_failed',
        'retry_count'      => 0,
    ]);

    $http = mock(SyncHttpClient::class)
        ->shouldNotReceive('push')
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
    );

    $result = $engine->push();

    expect($result->pushed)->toBe(0)
        ->and($result->failed)->toBe(0)
        ->and($result->conflicts)->toBe(0)
        ->and($result->rejections)->toBe([])
        ->and(MutationLog::first()->sync_status)->toBe(SyncStatus::Failed);
});

it('push marks duplicate rejections as synced instead of failed', function () {
    TestPost::create([
        'title' => 'Duplicate',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;

    $http = mock(SyncHttpClient::class)
        ->shouldReceive('push')
        ->once()
        ->andReturn(pushResult([
            'applied'   => [],
            'rejected'  => [[
                'mutation_id' => $mutationId,
                'reason' => 'duplicate',
                'data' => [],
            ]],
            'conflicts' => [],
        ]))
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
    );

    $engine->push();

    expect(MutationLog::first()->sync_status)->toBe(SyncStatus::Synced);
});

// ── Pull ──────────────────────────────────────────────────────────────────────

it('pull applies received snapshots to the local database', function () {
    $cursor = now()->subMinute()->timestamp * 1_000_000;

    $http = mock(SyncHttpClient::class)
        ->shouldReceive('pull')
        ->once()
        ->with(null, null)
        ->andReturn(pullResult([
            'snapshots' => [
                [
                    'entity_id' => '01HXYZ0001AABBCCDD0011AAB1',
                    'model'     => 'TestPost',
                    'operation' => 'upsert',
                    'payload'   => [
                        'title' => 'From Server',
                        'body' => null,
                    ],
                ],
            ],
            'new_sync_cursor' => $cursor,
            'has_more'        => false,
        ]))
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
    );

    $result = $engine->pull();

    expect($result->pulled)->toBe(1)
        ->and($result->pullErrors)->toBe(0)
        ->and(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AAB1')->value('title'))
        ->toBe('From Server');
});

it('pull updates the sync cursor after applying snapshots', function () {
    $state  = app(SyncStateStore::class);
    $cursor = now()->timestamp * 1_000_000;

    $http = mock(SyncHttpClient::class)
        ->shouldReceive('pull')
        ->once()
        ->with(null, null)
        ->andReturn(pullResult([
            'snapshots'       => [],
            'new_sync_cursor' => $cursor,
            'has_more'        => false,
        ]))
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: $state,
        snapshots: makeSnapshots(),
    );

    $engine->pull();

    expect($state->get('last_sync_cursor'))->toBe((string) $cursor);
});

it('pull uses the stored cursor for subsequent pulls', function () {
    $state  = app(SyncStateStore::class);
    $cursor = now()->timestamp * 1_000_000;
    $state->set('last_sync_cursor', (string) $cursor);

    $http = mock(SyncHttpClient::class)
        ->shouldReceive('pull')
        ->once()
        ->with($cursor, null)
        ->andReturn(pullResult([
            'snapshots' => [],
            'new_sync_cursor' => $cursor,
            'has_more' => false,
        ]))
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: $state,
        snapshots: makeSnapshots(),
    );

    $engine->pull();
});

// ── Sync ──────────────────────────────────────────────────────────────────────

it('sync calls push then pull and returns a SyncResult', function () {
    TestPost::create([
        'title' => 'Pending',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;
    $cursor     = now()->timestamp * 1_000_000;

    $http = mock(SyncHttpClient::class);
    $http->shouldReceive('push')
        ->once()
        ->andReturn(pushResult([
            'applied' => [$mutationId],
            'rejected' => [],
            'conflicts' => [],
        ]));
    $http->shouldReceive('pull')
        ->once()
        ->andReturn(pullResult([
            'snapshots' => [],
            'new_sync_cursor' => $cursor,
            'has_more' => false,
        ]));

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
    );

    $result = $engine->sync();

    expect($result->pushed)->toBe(1)
        ->and($result->failed)->toBe(0)
        ->and($result->pulled)->toBe(0);
});

it('sync status returns queue counts and stored sync state', function () {
    TestPost::create([
        'title' => 'Pending',
        'body' => null,
    ]);

    $state = app(SyncStateStore::class);
    $state->set('last_sync_cursor', '123');
    $state->set('last_sync_at', '2026-05-19T10:00:00+00:00');

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: mock(SyncHttpClient::class)->shouldNotReceive('push')->getMock(),
        state: $state,
        snapshots: makeSnapshots(),
    );

    $status = $engine->syncStatus();

    expect($status->pending)->toBe(1)
        ->and($status->failed)->toBe(0)
        ->and($status->conflicts)->toBe(0)
        ->and($status->lastSyncCursor)->toBe('123')
        ->and($status->lastSyncAt)->toBe('2026-05-19T10:00:00+00:00');
});

it('sync returns skipped when the sync lock is held', function () {
    Event::fake([TetherSyncCompleted::class]);
    $lock = Cache::lock('tether_sync_lock', 60);
    $lock->get();

    try {
        $engine = new SyncEngine(
            queue: app(PendingSyncQueue::class),
            http: mock(SyncHttpClient::class)->shouldNotReceive('push')->getMock(),
            state: app(SyncStateStore::class),
            snapshots: makeSnapshots(),
        );

        $result = $engine->sync();

        expect($result->skipped)->toBeTrue();
        Event::assertDispatched(TetherSyncCompleted::class, fn($event) => $event->result->skipped === true);
    } finally {
        $lock->release();
    }
});

it('push returns skipped when the sync lock is held', function () {
    Event::fake([TetherPushCompleted::class]);
    $lock = Cache::lock('tether_sync_lock', 60);
    $lock->get();

    try {
        $engine = new SyncEngine(
            queue: app(PendingSyncQueue::class),
            http: mock(SyncHttpClient::class)->shouldNotReceive('push')->getMock(),
            state: app(SyncStateStore::class),
            snapshots: makeSnapshots(),
        );

        $result = $engine->push();

        expect($result->skipped)->toBeTrue();
        Event::assertDispatched(TetherPushCompleted::class, fn($event) => $event->result->skipped === true);
    } finally {
        $lock->release();
    }
});

it('pull returns skipped when the sync lock is held', function () {
    Event::fake([TetherPullCompleted::class]);
    $lock = Cache::lock('tether_sync_lock', 60);
    $lock->get();

    try {
        $engine = new SyncEngine(
            queue: app(PendingSyncQueue::class),
            http: mock(SyncHttpClient::class)->shouldNotReceive('pull')->getMock(),
            state: app(SyncStateStore::class),
            snapshots: makeSnapshots(),
        );

        $result = $engine->pull();

        expect($result->skipped)->toBeTrue();
        Event::assertDispatched(TetherPullCompleted::class, fn($event) => $event->result->skipped === true);
    } finally {
        $lock->release();
    }
});

it('runs without a cache lock when sync locking is disabled', function () {
    config([
        'tether-client.sync_lock' => false,
    ]);
    TestPost::create([
        'title' => 'Pending',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;

    $lock = Cache::lock('tether_sync_lock', 60);
    $lock->get();

    try {
        $http = mock(SyncHttpClient::class)
            ->shouldReceive('push')
            ->once()
            ->andReturn(pushResult([
                'applied' => [$mutationId],
                'rejected' => [],
                'conflicts' => [],
            ]))
            ->getMock();

        $engine = new SyncEngine(
            queue: app(PendingSyncQueue::class),
            http: $http,
            state: app(SyncStateStore::class),
            snapshots: makeSnapshots(),
        );

        expect($engine->push()->pushed)->toBe(1);
    } finally {
        $lock->release();
    }
});

// ── outbound mutationMapper ───────────────────────────────────────────────────

it('push applies the outbound mutationMapper before sending to server', function () {
    TestPost::create([
        'title' => 'hello',
        'body' => null,
    ]);

    $capturedPayload = null;

    $registry = new ClientSyncRegistry();
    $registry->register(
        \Tests\Models\TestPost::class,
        mutationMapper: function (Mutation $mutation) use (&$capturedPayload): Mutation {
            $payload = $mutation->getPayload();
            $payload['title'] = strtoupper($payload['title'] ?? '');
            $capturedPayload = $payload;

            return $mutation->withPayload($payload);
        },
    );

    $mutationId = MutationLog::first()->mutation_id;

    $http = mock(SyncHttpClient::class)
        ->shouldReceive('push')
        ->once()
        ->andReturn(pushResult([
            'applied' => [$mutationId],
            'rejected' => [],
            'conflicts' => [],
        ]))
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
        registry: $registry,
        modelNamespace: 'Tests\\Models',
    );

    $engine->push();

    expect($capturedPayload['title'])->toBe('HELLO');
});

it('push sends unmodified payload when no mutationMapper is registered', function () {
    TestPost::create([
        'title' => 'original',
        'body' => null,
    ]);

    $capturedPayload = null;

    $mutationId = MutationLog::first()->mutation_id;

    $http = mock(SyncHttpClient::class)
        ->shouldReceive('push')
        ->once()
        ->with(\Mockery::on(function (array $mutations) use (&$capturedPayload): bool {
            $capturedPayload = $mutations[0]->getPayload();

            return true;
        }))
        ->andReturn(pushResult([
            'applied' => [$mutationId],
            'rejected' => [],
            'conflicts' => [],
        ]))
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
    );

    $engine->push();

    expect($capturedPayload['title'])->toBe('original');
});

it('push skips outbound mapper lookup when resolved model class does not exist', function () {
    TestPost::create([
        'title' => 'original',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;

    $registry = new ClientSyncRegistry();
    $registry->register(\Tests\Models\TestPost::class, mutationMapper: fn(Mutation $mutation): Mutation => $mutation->withPayload([
        'title' => 'mapped',
    ]));

    $capturedPayload = null;
    $http = mock(SyncHttpClient::class)
        ->shouldReceive('push')
        ->once()
        ->with(\Mockery::on(function (array $mutations) use (&$capturedPayload): bool {
            $capturedPayload = $mutations[0]->getPayload();

            return true;
        }))
        ->andReturn(pushResult([
            'applied' => [$mutationId],
            'rejected' => [],
            'conflicts' => [],
        ]))
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
        registry: $registry,
        modelNamespace: 'Tests\\Missing',
    );

    $engine->push();

    expect($capturedPayload['title'])->toBe('original');
});

it('push sends pending mutations in configured batches', function () {
    config([
        'tether-client.push_batch_size' => 2,
    ]);

    TestPost::create([
        'title' => 'One',
        'body' => null,
    ]);
    TestPost::create([
        'title' => 'Two',
        'body' => null,
    ]);
    TestPost::create([
        'title' => 'Three',
        'body' => null,
    ]);

    $mutationIds = MutationLog::orderBy('id')->pluck('mutation_id')->all();

    $http = mock(SyncHttpClient::class);
    $http->shouldReceive('push')
        ->once()
        ->with(\Mockery::on(fn(array $mutations): bool => count($mutations) === 2))
        ->andReturn(pushResult([
            'applied' => array_slice($mutationIds, 0, 2),
            'rejected' => [],
            'conflicts' => [],
        ]));
    $http->shouldReceive('push')
        ->once()
        ->with(\Mockery::on(fn(array $mutations): bool => count($mutations) === 1))
        ->andReturn(pushResult([
            'applied' => array_slice($mutationIds, 2),
            'rejected' => [],
            'conflicts' => [],
        ]));

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
    );

    expect($engine->push()->pushed)->toBe(3);
});

it('push sends one batch when push_batch_size is zero', function () {
    config([
        'tether-client.push_batch_size' => 0,
    ]);

    TestPost::create([
        'title' => 'One',
        'body' => null,
    ]);
    TestPost::create([
        'title' => 'Two',
        'body' => null,
    ]);
    TestPost::create([
        'title' => 'Three',
        'body' => null,
    ]);

    $mutationIds = MutationLog::orderBy('id')->pluck('mutation_id')->all();

    $http = mock(SyncHttpClient::class)
        ->shouldReceive('push')
        ->once()
        ->with(\Mockery::on(fn(array $mutations): bool => count($mutations) === 3))
        ->andReturn(pushResult([
            'applied' => $mutationIds,
            'rejected' => [],
            'conflicts' => [],
        ]))
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
    );

    expect($engine->push()->pushed)->toBe(3);
});

it('push sends one batch when push_batch_size is null', function () {
    config([
        'tether-client.push_batch_size' => null,
    ]);

    TestPost::create([
        'title' => 'One',
        'body' => null,
    ]);
    TestPost::create([
        'title' => 'Two',
        'body' => null,
    ]);

    $mutationIds = MutationLog::orderBy('id')->pluck('mutation_id')->all();

    $http = mock(SyncHttpClient::class)
        ->shouldReceive('push')
        ->once()
        ->with(\Mockery::on(fn(array $mutations): bool => count($mutations) === 2))
        ->andReturn(pushResult([
            'applied' => $mutationIds,
            'rejected' => [],
            'conflicts' => [],
        ]))
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
    );

    expect($engine->push()->pushed)->toBe(2);
});

it('push handles conflicts by applying server state and marking the mutation conflicted', function () {
    Event::fake([TetherConflictDetected::class]);

    TestPost::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Local',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;

    $http = mock(SyncHttpClient::class)
        ->shouldReceive('push')
        ->once()
        ->andReturn(pushResult([
            'applied' => [],
            'rejected' => [],
            'conflicts' => [[
                'mutation_id' => $mutationId,
                'reason' => 'conflict',
                'data' => [
                    'server_state' => [
                        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
                        'title' => 'Server',
                        'body' => null,
                    ],
                ],
            ]],
        ]))
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
    );

    $result = $engine->push();

    expect($result->conflicts)->toBe(1)
        ->and(MutationLog::first()->sync_status)->toBe(SyncStatus::Conflict)
        ->and(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AAB1')->value('title'))->toBe('Server');

    Event::assertDispatched(TetherConflictDetected::class, fn($event) => $event->mutationId === $mutationId);
});

it('push marks conflicts even when server state cannot be applied locally', function () {
    \Illuminate\Support\Facades\Log::spy();

    TestPost::create([
        'title' => 'Local',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;

    $http = mock(SyncHttpClient::class)
        ->shouldReceive('push')
        ->once()
        ->andReturn(pushResult([
            'applied' => [],
            'rejected' => [],
            'conflicts' => [[
                'mutation_id' => $mutationId,
                'reason' => 'conflict',
                'data' => [
                    'server_state' => [
                        'tether_id' => '01HXYZ0001AABBCCDD0011AAB9',
                        'title' => 'Server',
                    ],
                ],
            ]],
        ]))
        ->getMock();

    $snapshots = mock(SnapshotApplicator::class)
        ->shouldReceive('apply')
        ->once()
        ->andThrow(new RuntimeException('Apply failed'))
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: $snapshots,
    );

    $result = $engine->push();

    expect($result->conflicts)->toBe(1)
        ->and(MutationLog::first()->sync_status)->toBe(SyncStatus::Conflict);
});

// ── pull pagination ───────────────────────────────────────────────────────────

it('pull loops until has_more is false and applies all snapshots', function () {
    $cursor1 = now()->subMinutes(2)->timestamp * 1_000_000;
    $cursor2 = now()->subMinute()->timestamp * 1_000_000;

    $http = mock(SyncHttpClient::class);
    $http->shouldReceive('pull')
        ->once()
        ->with(null, null)
        ->andReturn(pullResult([
            'snapshots' => [
                [
                    'entity_id' => '01HXYZ0001AABBCCDD0011AAB1',
                    'model' => 'TestPost',
                    'operation' => 'upsert',
                    'payload' => [
                        'title' => 'Page 1',
                        'body' => null,
                    ],
                ],
            ],
            'new_sync_cursor' => $cursor1,
            'has_more'        => true,
        ]));
    $http->shouldReceive('pull')
        ->once()
        ->with($cursor1, null)
        ->andReturn(pullResult([
            'snapshots' => [
                [
                    'entity_id' => '01HXYZ0001AABBCCDD0011AAB2',
                    'model' => 'TestPost',
                    'operation' => 'upsert',
                    'payload' => [
                        'title' => 'Page 2',
                        'body' => null,
                    ],
                ],
            ],
            'new_sync_cursor' => $cursor2,
            'has_more'        => false,
        ]));

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
    );

    $result = $engine->pull();

    expect($result->pulled)->toBe(2)
        ->and($result->pullErrors)->toBe(0)
        ->and(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AAB1')->exists())->toBeTrue()
        ->and(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AAB2')->exists())->toBeTrue();
});

it('pull reports snapshot apply exceptions and continues processing remaining snapshots', function () {
    \Illuminate\Support\Facades\Log::spy();

    $http = mock(SyncHttpClient::class)
        ->shouldReceive('pull')
        ->once()
        ->with(null, null)
        ->andReturn(pullResult([
            'snapshots' => [
                [
                    'entity_id' => '01HXYZ0001AABBCCDD0011AAB1',
                    'model' => 'UnknownModel',
                    'operation' => 'upsert',
                    'payload' => [
                        'title' => 'Bad',
                    ],
                ],
                [
                    'entity_id' => '01HXYZ0001AABBCCDD0011AAB2',
                    'model' => 'TestPost',
                    'operation' => 'upsert',
                    'payload' => [
                        'title' => 'Good',
                        'body' => null,
                    ],
                ],
            ],
            'new_sync_cursor' => now()->timestamp * 1_000_000,
            'has_more'        => false,
        ]))
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
    );

    $result = $engine->pull();

    expect($result->pulled)->toBe(1)
        ->and($result->pullErrors)->toBe(1)
        ->and(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AAB2')->exists())->toBeTrue();
});

it('pull persists the cursor from each page immediately', function () {
    $state   = app(SyncStateStore::class);
    $cursor1 = now()->subMinute()->timestamp * 1_000_000;
    $cursor2 = now()->timestamp * 1_000_000;

    $http = mock(SyncHttpClient::class);
    $http->shouldReceive('pull')
        ->once()
        ->with(null, null)
        ->andReturn(pullResult([
            'snapshots' => [],
            'new_sync_cursor' => $cursor1,
            'has_more' => true,
        ]));
    $http->shouldReceive('pull')
        ->once()
        ->with($cursor1, null)
        ->andReturn(pullResult([
            'snapshots' => [],
            'new_sync_cursor' => $cursor2,
            'has_more' => false,
        ]));

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: $state,
        snapshots: makeSnapshots(),
    );

    $engine->pull();

    expect($state->get('last_sync_cursor'))->toBe((string) $cursor2);
});

it('pull sends the configured page size to the server', function () {
    config([
        'tether-client.pull_page_size' => 50,
    ]);

    $http = mock(SyncHttpClient::class)
        ->shouldReceive('pull')
        ->once()
        ->with(null, 50)
        ->andReturn(pullResult([
            'snapshots' => [],
            'new_sync_cursor' => null,
            'has_more' => false,
        ]))
        ->getMock();

    $engine = new SyncEngine(
        queue: app(PendingSyncQueue::class),
        http: $http,
        state: app(SyncStateStore::class),
        snapshots: makeSnapshots(),
    );

    expect($engine->pull()->pulled)->toBe(0);
});
