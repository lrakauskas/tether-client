<?php

use Tests\Models\TestPost;
use Tether\Client\Models\MutationLog;
use Tether\Client\PendingSyncQueue;
use Tether\Core\Enums\SyncStatus;
use Tether\Core\Mutation\Mutation;

beforeEach(function () {
    $this->queue = new PendingSyncQueue();
});

it('returns pending mutations as Mutation value objects', function () {
    TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);

    $pending = $this->queue->pending();

    expect($pending)->toHaveCount(1)
        ->and($pending->first())->toBeInstanceOf(Mutation::class);
});

it('returns pending mutations in insertion order', function () {
    $post = TestPost::create([
        'title' => 'First',
        'body' => null,
    ]);
    $post->update([
        'title' => 'Second',
    ]);

    $ids = $this->queue->pending()->map->getMutationId()->values();
    $dbIds = MutationLog::orderBy('id')->pluck('mutation_id')->values();

    expect($ids->toArray())->toBe($dbIds->toArray());
});

it('count() returns the number of pending mutations', function () {
    TestPost::create([
        'title' => 'A',
        'body' => null,
    ]);
    TestPost::create([
        'title' => 'B',
        'body' => null,
    ]);

    expect($this->queue->count())->toBe(2);
});

it('markSynced() changes sync_status to synced', function () {
    TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;

    $this->queue->markSynced($mutationId);

    expect(MutationLog::first()->sync_status)->toBe(SyncStatus::Synced)
        ->and(MutationLog::first()->synced_at)->not->toBeNull();
});

it('markSynced() removes the mutation from pending()', function () {
    TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;

    $this->queue->markSynced($mutationId);

    expect($this->queue->pending())->toBeEmpty()
        ->and($this->queue->count())->toBe(0);
});

it('markFailed() changes sync_status to failed', function () {
    TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;

    $this->queue->markFailed($mutationId);

    expect(MutationLog::first()->sync_status)->toBe(SyncStatus::Failed);
});

it('markFailed() removes the mutation from pending()', function () {
    TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;

    $this->queue->markFailed($mutationId);

    expect($this->queue->pending())->toBeEmpty()
        ->and($this->queue->count())->toBe(0);
});

it('does not return synced or failed mutations in pending()', function () {
    $a = TestPost::create([
        'title' => 'A',
        'body' => null,
    ]);
    $b = TestPost::create([
        'title' => 'B',
        'body' => null,
    ]);

    $logs = MutationLog::orderBy('id')->get();
    $this->queue->markSynced($logs[0]->mutation_id);
    $this->queue->markFailed($logs[1]->mutation_id);

    expect($this->queue->pending())->toBeEmpty();
});

// ── markFailed retry_count ────────────────────────────────────────────────────

it('markFailed() increments retry_count on each call', function () {
    TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;

    expect(MutationLog::first()->retry_count)->toBe(0);

    $this->queue->markFailed($mutationId);
    expect(MutationLog::first()->retry_count)->toBe(1);

    // Reset to pending manually so we can fail it again
    MutationLog::where('mutation_id', $mutationId)->update([
        'sync_status' => 'pending',
    ]);

    $this->queue->markFailed($mutationId);
    expect(MutationLog::first()->retry_count)->toBe(2);
});

// ── retryFailed ───────────────────────────────────────────────────────────────

it('retryFailed() resets error-failed mutations to pending when under max attempts', function () {
    TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;

    $this->queue->markFailed($mutationId, 'error');

    $this->queue->retryFailed(maxAttempts: 3);

    expect(MutationLog::first()->sync_status)->toBe(SyncStatus::Pending)
        ->and(MutationLog::first()->rejection_reason)->toBeNull()
        ->and(MutationLog::first()->rejection_data)->toBeNull();
});

it('retryFailed() does not reset mutations that have reached max attempts', function () {
    TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;

    // Simulate 3 prior failures
    MutationLog::where('mutation_id', $mutationId)->update([
        'sync_status'      => 'failed',
        'rejection_reason' => 'error',
        'retry_count'      => 3,
    ]);

    $this->queue->retryFailed(maxAttempts: 3);

    expect(MutationLog::first()->sync_status)->toBe(SyncStatus::Failed);
});

it('retryFailed() does not reset validation_failed mutations', function () {
    TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;

    $this->queue->markFailed($mutationId, 'validation_failed');

    $this->queue->retryFailed(maxAttempts: 3);

    expect(MutationLog::first()->sync_status)->toBe(SyncStatus::Failed);
});

it('retryFailed() does not reset not_found mutations', function () {
    TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;

    $this->queue->markFailed($mutationId, 'not_found');

    $this->queue->retryFailed(maxAttempts: 3);

    expect(MutationLog::first()->sync_status)->toBe(SyncStatus::Failed);
});

it('retryFailed() only resets error-failed mutations and leaves others untouched', function () {
    $postA = TestPost::create([
        'title' => 'A',
        'body' => null,
    ]);
    $postB = TestPost::create([
        'title' => 'B',
        'body' => null,
    ]);

    $logs = MutationLog::orderBy('id')->get();
    $this->queue->markFailed($logs[0]->mutation_id, 'error');
    $this->queue->markFailed($logs[1]->mutation_id, 'validation_failed');

    $this->queue->retryFailed(maxAttempts: 3);

    expect(MutationLog::where('mutation_id', $logs[0]->mutation_id)->value('sync_status'))
        ->toBe(SyncStatus::Pending);
    expect(MutationLog::where('mutation_id', $logs[1]->mutation_id)->value('sync_status'))
        ->toBe(SyncStatus::Failed);
});

// ── markConflict + conflict counts ───────────────────────────────────────────

it('markConflict() sets sync_status to conflict', function () {
    TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;

    $this->queue->markConflict($mutationId, [
        'title' => 'Server State',
    ]);

    expect(MutationLog::first()->sync_status)->toBe(SyncStatus::Conflict)
        ->and(MutationLog::first()->rejection_reason)->toBe('conflict')
        ->and(MutationLog::first()->rejection_data)->toBe([
            'title' => 'Server State',
        ]);
});

it('markConflict() removes the mutation from pending()', function () {
    TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);
    $mutationId = MutationLog::first()->mutation_id;

    $this->queue->markConflict($mutationId);

    expect($this->queue->pending())->toHaveCount(0);
});

it('failedCount() returns the number of failed mutations', function () {
    TestPost::create([
        'title' => 'A',
        'body' => null,
    ]);
    TestPost::create([
        'title' => 'B',
        'body' => null,
    ]);

    $logs = MutationLog::orderBy('id')->get();
    $this->queue->markFailed($logs[0]->mutation_id, 'error');

    expect($this->queue->failedCount())->toBe(1);
});

it('conflictCount() returns the number of conflicted mutations', function () {
    TestPost::create([
        'title' => 'A',
        'body' => null,
    ]);
    TestPost::create([
        'title' => 'B',
        'body' => null,
    ]);

    $logs = MutationLog::orderBy('id')->get();
    $this->queue->markConflict($logs[0]->mutation_id);

    expect($this->queue->conflictCount())->toBe(1)
        ->and($this->queue->count())->toBe(1); // B is still pending
});
