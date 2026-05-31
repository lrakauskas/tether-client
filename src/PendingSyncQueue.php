<?php

namespace Tether\Client;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tether\Client\Models\MutationLog;
use Tether\Client\Support\TetherLog;
use Tether\Core\Enums\SyncStatus;
use Tether\Core\Mutation\Mutation;

class PendingSyncQueue
{
    /**
     * Retrieve all pending (unsynced) mutations in insertion order,
     * as core Mutation value objects.
     *
     * @return Collection<int, Mutation>
     */
    public function pending(): Collection
    {
        return MutationLog::where('sync_status', SyncStatus::Pending)
            ->orderBy('id')
            ->get()
            ->map(fn(MutationLog $log) => $log->toMutation());
    }

    /**
     * Mark a mutation as successfully synced.
     */
    public function markSynced(string $mutationId): void
    {
        MutationLog::where('mutation_id', $mutationId)->update([
            'sync_status' => SyncStatus::Synced,
            'synced_at'   => now(),
        ]);

        TetherLog::debug('Marked mutation synced', 2, [
            'mutation_id' => $mutationId,
        ]);
    }

    /**
     * Mark a mutation as failed, persisting the structured rejection from the server.
     * Increments retry_count each time so exhausted mutations can be identified.
     *
     * @param  array<string, mixed>  $data
     */
    public function markFailed(string $mutationId, string $reason = 'error', array $data = []): void
    {
        MutationLog::where('mutation_id', $mutationId)->update([
            'sync_status'      => SyncStatus::Failed,
            'rejection_reason' => $reason,
            'rejection_data'   => $data ?: null,
            'retry_count'      => DB::raw('retry_count + 1'),
        ]);

        TetherLog::debug('Marked mutation failed', 2, [
            'mutation_id' => $mutationId,
            'reason' => $reason,
        ]);
    }

    /**
     * Re-queue failed mutations that are eligible for retry.
     *
     * Only mutations rejected with reason 'error' (transient server/network failures)
     * are eligible. Mutations failed due to 'validation_failed', 'not_found', etc.
     * are considered permanent and are never re-queued.
     *
     * Mutations whose retry_count has reached $maxAttempts are also excluded -
     * they stay Failed permanently.
     */
    public function retryFailed(int $maxAttempts): void
    {
        $updated = MutationLog::where('sync_status', SyncStatus::Failed)
            ->where('rejection_reason', 'error')
            ->where('retry_count', '<', $maxAttempts)
            ->update([
                'sync_status'      => SyncStatus::Pending,
                'rejection_reason' => null,
                'rejection_data'   => null,
            ]);

        TetherLog::debug('Retried failed mutations', 2, [
            'max_attempts' => $maxAttempts,
            'mutation_count' => $updated,
        ]);
    }

    /**
     * Mark a mutation as permanently conflicted. The server state was applied
     * locally; this mutation will not be retried.
     *
     * @param  array<string, mixed>  $serverState  The server record returned in the conflict response
     */
    public function markConflict(string $mutationId, array $serverState = []): void
    {
        MutationLog::where('mutation_id', $mutationId)->update([
            'sync_status'      => SyncStatus::Conflict,
            'rejection_reason' => 'conflict',
            'rejection_data'   => $serverState ?: null,
        ]);

        TetherLog::debug('Marked mutation conflicted', 2, [
            'mutation_id' => $mutationId,
        ]);
    }

    /**
     * Count of mutations currently waiting to be synced.
     */
    public function count(): int
    {
        return MutationLog::where('sync_status', SyncStatus::Pending)->count();
    }

    /**
     * Count of mutations that have permanently failed (exhausted retries or
     * rejected due to validation / not_found / conflict reasons).
     */
    public function failedCount(): int
    {
        return MutationLog::where('sync_status', SyncStatus::Failed)->count();
    }

    /**
     * Count of mutations that were rejected due to a server-side conflict.
     */
    public function conflictCount(): int
    {
        return MutationLog::where('sync_status', SyncStatus::Conflict)->count();
    }
}
