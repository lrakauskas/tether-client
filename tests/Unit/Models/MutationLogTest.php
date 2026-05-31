<?php

use Tether\Client\Models\MutationLog;
use Tether\Core\Enums\OperationType;
use Tether\Core\Enums\SyncStatus;
use Tether\Core\Mutation\Mutation;

it('stores a mutation row with the correct casts', function () {
    MutationLog::create([
        'mutation_id' => '01HXYZ0001AABBCCDD0011AABB',
        'entity_id'   => '01HXYZ0002AABBCCDD0011AABB',
        'model'       => 'Post',
        'operation'   => OperationType::Create,
        'payload'     => [
            'title' => 'Hello',
        ],
        'version'     => 1,
        'timestamp'   => 1_700_000_000_000,
        'sync_status' => SyncStatus::Pending,
    ]);

    $log = MutationLog::first();

    expect($log->mutation_id)->toBe('01HXYZ0001AABBCCDD0011AABB')
        ->and($log->entity_id)->toBe('01HXYZ0002AABBCCDD0011AABB')
        ->and($log->operation)->toBe(OperationType::Create)
        ->and($log->sync_status)->toBe(SyncStatus::Pending)
        ->and($log->payload)->toBe([
            'title' => 'Hello',
        ])
        ->and($log->version)->toBe(1)
        ->and($log->timestamp)->toBe(1_700_000_000_000);
});

it('converts to a Mutation value object via toMutation()', function () {
    MutationLog::create([
        'mutation_id' => '01HXYZ0001AABBCCDD0011AABB',
        'entity_id'   => '01HXYZ0002AABBCCDD0011AABB',
        'model'       => 'Post',
        'operation'   => OperationType::Update,
        'payload'     => [
            'title' => 'Updated',
        ],
        'version'     => 2,
        'timestamp'   => 1_700_000_001_000,
        'sync_status' => SyncStatus::Pending,
    ]);

    $mutation = MutationLog::first()->toMutation();

    expect($mutation)->toBeInstanceOf(Mutation::class)
        ->and($mutation->getMutationId())->toBe('01HXYZ0001AABBCCDD0011AABB')
        ->and($mutation->getOperation())->toBe(OperationType::Update)
        ->and($mutation->getPayload())->toBe([
            'title' => 'Updated',
        ])
        ->and($mutation->getVersion())->toBe(2);
});

it('enforces uniqueness on mutation_id', function () {
    $row = [
        'mutation_id' => '01HXYZ0001AABBCCDD0011AABB',
        'entity_id'   => '01HXYZ0002AABBCCDD0011AABB',
        'model'       => 'Post',
        'operation'   => OperationType::Create,
        'payload'     => [],
        'version'     => 1,
        'timestamp'   => 1_700_000_000_000,
        'sync_status' => SyncStatus::Pending,
    ];

    MutationLog::create($row);
    MutationLog::create($row);
})->throws(\Illuminate\Database\QueryException::class);

it('defaults sync_status to pending', function () {
    MutationLog::create([
        'mutation_id' => '01HXYZ0001AABBCCDD0011AABB',
        'entity_id'   => '01HXYZ0002AABBCCDD0011AABB',
        'model'       => 'Post',
        'operation'   => OperationType::Create,
        'payload'     => [],
        'version'     => 1,
        'timestamp'   => 1_700_000_000_000,
        'sync_status' => SyncStatus::Pending,
    ]);

    expect(MutationLog::first()->sync_status)->toBe(SyncStatus::Pending);
});
