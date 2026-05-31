<?php

use Tests\Models\TestPost;
use Tests\Models\TestPostWithSoftDeletes;
use Tether\Client\ClientSyncRegistry;
use Tether\Client\SnapshotApplicator;
use Tether\Core\Sync\Snapshot;

function makeApplicator(?callable $mapper = null, string $modelClass = TestPost::class): SnapshotApplicator
{
    $registry = new ClientSyncRegistry();

    if ($mapper !== null) {
        $registry->register($modelClass, $mapper);
    }

    return new SnapshotApplicator(
        modelNamespace: 'Tests\\Models',
        syncKeyColumn: 'tether_id',
        registry: $registry,
    );
}

function testSnapshot(array $data): Snapshot
{
    return Snapshot::fromArray($data);
}

// ── upsert ────────────────────────────────────────────────────────────────────

it('creates a new record when tether_id does not exist locally', function () {
    $applicator = makeApplicator();

    $applicator->apply(testSnapshot([
        'entity_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'model'     => 'TestPost',
        'operation' => 'upsert',
        'payload'   => [
            'title' => 'Hello',
            'body' => null,
        ],
    ]));

    expect(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AAB1')->exists())->toBeTrue()
        ->and(TestPost::first()->title)->toBe('Hello');
});

it('updates an existing record when tether_id already exists locally', function () {
    // Create directly via forceFill to bypass $fillable on TestPost
    $post = new TestPost();
    $post->forceFill([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Original',
        'body' => null,
    ]);
    $post->save();

    $applicator = makeApplicator();

    $applicator->apply(testSnapshot([
        'entity_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'model'     => 'TestPost',
        'operation' => 'upsert',
        'payload'   => [
            'title' => 'Updated',
            'body' => null,
        ],
    ]));

    expect(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AAB1')->value('title'))->toBe('Updated')
        ->and(TestPost::count())->toBe(1);
});

// ── delete ────────────────────────────────────────────────────────────────────

it('deletes a local record when operation is delete', function () {
    $post = new TestPost();
    $post->forceFill([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'ToDelete',
        'body' => null,
    ]);
    $post->save();

    $applicator = makeApplicator();

    $applicator->apply(testSnapshot([
        'entity_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'model'     => 'TestPost',
        'operation' => 'delete',
        'payload'   => [],
    ]));

    expect(TestPost::where('tether_id', '01HXYZ0001AABBCCDD0011AAB1')->exists())->toBeFalse();
});

it('soft-deletes when model uses SoftDeletes and operation is delete', function () {
    TestPostWithSoftDeletes::create([
        'tether_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'title' => 'Gone',
    ]);

    $applicator = makeApplicator(modelClass: TestPostWithSoftDeletes::class);

    $applicator->apply(testSnapshot([
        'entity_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'model'     => 'TestPostWithSoftDeletes',
        'operation' => 'delete',
        'payload'   => [],
    ]));

    expect(TestPostWithSoftDeletes::where('tether_id', '01HXYZ0001AABBCCDD0011AAB1')->exists())->toBeFalse()
        ->and(TestPostWithSoftDeletes::withTrashed()->where('tether_id', '01HXYZ0001AABBCCDD0011AAB1')->exists())->toBeTrue();
});

// ── payload mapper ────────────────────────────────────────────────────────────

it('applies the payload mapper before upserting', function () {
    $mapper = fn(Snapshot $snapshot) => $snapshot->withPayload(array_merge($snapshot->payload, [
        'title' => strtoupper($snapshot->payload['title']),
    ]));

    $applicator = makeApplicator($mapper);

    $applicator->apply(testSnapshot([
        'entity_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'model'     => 'TestPost',
        'operation' => 'upsert',
        'payload'   => [
            'title' => 'hello',
            'body' => null,
        ],
    ]));

    expect(TestPost::first()->title)->toBe('HELLO');
});

it('uses the raw payload when no mapper is registered', function () {
    $applicator = makeApplicator();

    $applicator->apply(testSnapshot([
        'entity_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'model'     => 'TestPost',
        'operation' => 'upsert',
        'payload'   => [
            'title' => 'raw',
            'body' => null,
        ],
    ]));

    expect(TestPost::first()->title)->toBe('raw');
});

// ── error handling ────────────────────────────────────────────────────────────

it('throws when model class cannot be resolved from namespace', function () {
    $applicator = makeApplicator();

    expect(fn() => $applicator->apply(testSnapshot([
        'entity_id' => '01HXYZ0001AABBCCDD0011AAB1',
        'model'     => 'NonExistentModel',
        'operation' => 'upsert',
        'payload'   => [],
    ])))->toThrow(\RuntimeException::class);
});
