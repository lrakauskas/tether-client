<?php

use Tests\Models\TestPost;
use Illuminate\Database\Eloquent\Model;
use Tether\Client\MutationLogger;
use Tether\Client\Models\MutationLog;
use Tether\Core\Enums\OperationType;
use Tether\Core\Enums\SyncStatus;
use Tether\Core\Identity\UlidGenerator;

beforeEach(function () {
    $this->logger = new MutationLogger(new UlidGenerator());
});

it('records a create mutation with correct fields', function () {
    $post = TestPost::create([
        'title' => 'Hello',
        'body' => 'World',
    ]);

    $log = MutationLog::first();

    expect(MutationLog::count())->toBe(1)
        ->and($log->entity_id)->toBe($post->tether_id)
        ->and($log->model)->toBe('TestPost')
        ->and($log->operation)->toBe(OperationType::Create)
        ->and($log->version)->toBe(1)
        ->and($log->sync_status)->toBe(SyncStatus::Pending)
        ->and($log->payload)->toHaveKey('title', 'Hello')
        ->and($log->payload)->toHaveKey('body', 'World');
});

it('records a full snapshot payload - not a diff', function () {
    $post = TestPost::create([
        'title' => 'Original',
        'body' => 'Body',
    ]);
    $post->update([
        'title' => 'Updated',
    ]);

    $updateLog = MutationLog::where('operation', OperationType::Update)->first();

    // Full snapshot: both title and body present, not just changed field
    expect($updateLog->payload)->toHaveKey('title', 'Updated')
        ->and($updateLog->payload)->toHaveKey('body', 'Body');
});

it('records an update mutation with incremented version', function () {
    $post = TestPost::create([
        'title' => 'v1',
        'body' => null,
    ]);
    $post->update([
        'title' => 'v2',
    ]);

    $logs = MutationLog::orderBy('id')->get();

    expect($logs[0]->version)->toBe(1)
        ->and($logs[1]->version)->toBe(2);
});

it('records a delete mutation with empty payload', function () {
    $post = TestPost::create([
        'title' => 'To delete',
        'body' => null,
    ]);
    $post->delete();

    $deleteLog = MutationLog::where('operation', OperationType::Delete)->first();

    expect($deleteLog->entity_id)->toBe($post->tether_id)
        ->and($deleteLog->payload)->toBeEmpty()
        ->and($deleteLog->version)->toBe(2);
});

it('only includes whitelisted fields in the payload', function () {
    TestPost::create([
        'title' => 'Hello',
        'body' => 'World',
    ]);

    $log = MutationLog::first();

    // 'title' and 'body' are in $syncable - id, tether_id, and timestamps are not
    expect(array_keys($log->payload))->toContain('title', 'body')
        ->and($log->payload)->not->toHaveKey('id')
        ->and($log->payload)->not->toHaveKey('tether_id')
        ->and($log->payload)->not->toHaveKey('created_at');
    expect($log->payload)->not->toHaveKey('updated_at');
});

it('assigns a unique mutation_id to each log entry', function () {
    $post = TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);
    $post->update([
        'title' => 'Updated',
    ]);

    $ids = MutationLog::pluck('mutation_id');

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->not->toBe($ids[1]);
});

it('records the mutation timestamp as a millisecond epoch integer', function () {
    $before = (int) now()->getPreciseTimestamp(3);
    TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);
    $after = (int) now()->getPreciseTimestamp(3);

    $ts = MutationLog::first()->timestamp;

    expect($ts)->toBeGreaterThanOrEqual($before)
        ->and($ts)->toBeLessThanOrEqual($after);
});

it('rejects models that do not use the Syncable trait', function () {
    expect(fn() => $this->logger->recordCreate(new class extends Model {}, ['title']))
        ->toThrow(InvalidArgumentException::class, 'Tether mutations can only be logged');
});
