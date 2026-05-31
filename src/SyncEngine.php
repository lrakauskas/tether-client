<?php

namespace Tether\Client;

use Illuminate\Support\Facades\Cache;
use Tether\Client\Events\TetherConflictDetected;
use Tether\Client\Events\TetherPullCompleted;
use Tether\Client\Events\TetherPullStarted;
use Tether\Client\Events\TetherPushCompleted;
use Tether\Client\Events\TetherPushStarted;
use Tether\Client\Events\TetherSyncCompleted;
use Tether\Client\Events\TetherSyncStarted;
use Tether\Client\Support\TetherLog;
use Tether\Core\Sync\PushRejection;
use Tether\Core\Sync\Snapshot;
use Tether\Core\Sync\SyncStatus;

class SyncEngine
{
    public function __construct(
        private readonly PendingSyncQueue $queue,
        private readonly SyncHttpClient $http,
        private readonly SyncStateStore $state,
        private readonly SnapshotApplicator $snapshots,
        private readonly ?ClientSyncRegistry $registry = null,
        private readonly string $modelNamespace = 'App\\Models',
    ) {}

    /**
     * Run a full sync cycle: push pending mutations then pull server delta.
     *
     * Acquires a cache lock so only one sync cycle runs at a time. If another
     * cycle is already running the call returns immediately with skipped = true.
     */
    public function sync(): SyncResult
    {
        TetherLog::debug('Starting sync cycle', 1);
        event(new TetherSyncStarted());

        $result = $this->withLock(function () {
            $pushResult = $this->doPush();
            $pullResult = $this->doPull();

            return new SyncResult(
                pushed: $pushResult['pushed'],
                failed: $pushResult['failed'],
                pulled: $pullResult['applied'],
                conflicts: $pushResult['conflicts'],
                rejections: $pushResult['rejections'],
                pullErrors: $pullResult['errors'],
            );
        });

        if ($result === null) {
            $skipped = new SyncResult(skipped: true);
            TetherLog::debug('Skipped sync cycle because lock is held', 2);
            event(new TetherSyncCompleted($skipped));

            return $skipped;
        }

        $this->state->set('last_sync_at', now()->toIso8601String());

        event(new TetherSyncCompleted($result));
        TetherLog::debug('Completed sync cycle', 1, [
            'pushed' => $result->pushed,
            'failed' => $result->failed,
            'pulled' => $result->pulled,
            'conflicts' => $result->conflicts,
        ]);

        return $result;
    }

    /**
     * Push all pending local mutations to the server.
     *
     * Acquires a cache lock so only one sync cycle runs at a time. If another
     * cycle is already running the call returns immediately with skipped = true.
     */
    public function push(): SyncResult
    {
        TetherLog::debug('Starting push cycle', 1);
        event(new TetherPushStarted());

        $raw = $this->withLock(fn() => $this->doPush());

        if ($raw === null) {
            $skipped = new SyncResult(skipped: true);
            TetherLog::debug('Skipped push cycle because lock is held', 2);
            event(new TetherPushCompleted($skipped));

            return $skipped;
        }

        $result = new SyncResult(
            pushed: $raw['pushed'],
            failed: $raw['failed'],
            conflicts: $raw['conflicts'],
            rejections: $raw['rejections'],
        );

        event(new TetherPushCompleted($result));
        TetherLog::debug('Completed push cycle', 1, [
            'pushed' => $result->pushed,
            'failed' => $result->failed,
            'conflicts' => $result->conflicts,
        ]);

        return $result;
    }

    /**
     * Pull the latest server state snapshot and apply it locally.
     *
     * Acquires a cache lock so only one sync cycle runs at a time. If another
     * cycle is already running the call returns immediately with skipped = true.
     */
    public function pull(): SyncResult
    {
        TetherLog::debug('Starting pull cycle', 1);
        event(new TetherPullStarted());

        $raw = $this->withLock(fn() => $this->doPull());

        if ($raw === null) {
            $skipped = new SyncResult(skipped: true);
            TetherLog::debug('Skipped pull cycle because lock is held', 2);
            event(new TetherPullCompleted($skipped));

            return $skipped;
        }

        $result = new SyncResult(
            pulled: $raw['applied'],
            pullErrors: $raw['errors'],
        );

        event(new TetherPullCompleted($result));
        TetherLog::debug('Completed pull cycle', 1, [
            'pulled' => $result->pulled,
            'pull_errors' => $result->pullErrors,
        ]);

        return $result;
    }

    /**
     * Return the current sync status for display or diagnostic purposes.
     */
    public function syncStatus(): SyncStatus
    {
        return new SyncStatus(
            pending: $this->queue->count(),
            failed: $this->queue->failedCount(),
            conflicts: $this->queue->conflictCount(),
            lastSyncCursor: $this->state->get('last_sync_cursor'),
            lastSyncAt: $this->state->get('last_sync_at'),
        );
    }

    /**
     * Internal: execute the push logic.
     *
     * @return array{pushed: int, failed: int, conflicts: int, rejections: list<PushRejection>}
     */
    private function doPush(): array
    {
        $maxAttempts = (int) config('tether-client.max_retry_attempts', 3);
        $this->queue->retryFailed($maxAttempts);

        $pending = $this->queue->pending();

        if ($pending->isEmpty()) {
            TetherLog::debug('No pending mutations to push', 2);

            return [
                'pushed' => 0,
                'failed' => 0,
                'conflicts' => 0,
                'rejections' => [],
            ];
        }

        $configuredBatchSize = config('tether-client.push_batch_size', 100);
        $batchSize = $configuredBatchSize === null ? $pending->count() : (int) $configuredBatchSize;
        if ($batchSize <= 0) {
            $batchSize = $pending->count();
        }
        $totalPushed    = 0;
        $totalFailed    = 0;
        $totalConflicts = 0;
        $allRejections  = [];

        TetherLog::debug('Preparing pending mutations for push', 1, [
            'pending_count' => $pending->count(),
            'batch_size' => $batchSize,
        ]);

        foreach ($pending->chunk($batchSize) as $chunk) {
            $payload = [];

            foreach ($chunk as $mutation) {
                if ($this->registry !== null) {
                    $modelClass = rtrim($this->modelNamespace, '\\') . '\\' . class_basename($mutation->getModel());

                    if (class_exists($modelClass)) {
                        $mapper = $this->registry->getMutationMapper($modelClass);

                        if ($mapper !== null) {
                            TetherLog::debug('Applying outbound mutation mapper', 3, [
                                'mutation_id' => $mutation->getMutationId(),
                                'model_class' => $modelClass,
                            ]);

                            $mutation = ($mapper)($mutation);
                        }
                    }
                }

                $payload[] = $mutation;
            }

            $response = $this->http->push($payload);

            TetherLog::debug('Processing push batch response', 2, [
                'batch_count' => count($payload),
                'applied_count' => count($response->applied),
                'rejected_count' => count($response->rejected),
                'conflict_count' => count($response->conflicts),
            ]);

            foreach ($response->applied as $mutationId) {
                $this->queue->markSynced($mutationId);
                $totalPushed++;
            }

            foreach ($response->rejected as $rejection) {
                if ($rejection->reason === 'duplicate') {
                    $this->queue->markSynced($rejection->mutationId);
                    $totalPushed++;
                    continue;
                }

                $this->queue->markFailed(
                    mutationId: $rejection->mutationId,
                    reason: $rejection->reason,
                    data: is_array($rejection->data) ? $rejection->data : [],
                );
                $totalFailed++;
                $allRejections[] = $rejection;
            }

            foreach ($response->conflicts as $conflict) {
                $serverState = $conflict->serverState();
                $mutationDataForConflict = collect($pending)
                    ->first(fn($m) => $m->getMutationId() === $conflict->mutationId);

                // Apply the server's current state locally so the client converges.
                if (! empty($serverState)) {
                    try {
                        $snapshotEntry = new Snapshot(
                            entityId: $serverState[config('tether-client.sync_key', 'tether_id')] ?? '',
                            model: $mutationDataForConflict?->getModel() ?? '',
                            operation: 'upsert',
                            payload: $serverState,
                        );

                        $this->snapshots->apply($snapshotEntry);
                    } catch (\Throwable $e) {
                        report($e);
                        TetherLog::debug('Failed to apply conflict server state', 2, [
                            'mutation_id' => $conflict->mutationId,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }

                $this->queue->markConflict($conflict->mutationId, $serverState);
                $totalConflicts++;

                event(new TetherConflictDetected(
                    mutationId: $conflict->mutationId,
                    model: $mutationDataForConflict?->getModel() ?? '',
                    entityId: $mutationDataForConflict?->getEntityId() ?? '',
                    serverState: $serverState,
                ));

                TetherLog::debug('Processed push conflict', 2, [
                    'mutation_id' => $conflict->mutationId,
                    'model' => $mutationDataForConflict?->getModel() ?? '',
                    'entity_id' => $mutationDataForConflict?->getEntityId() ?? '',
                ]);
            }
        }

        return [
            'pushed'     => $totalPushed,
            'failed'     => $totalFailed,
            'conflicts'  => $totalConflicts,
            'rejections' => $allRejections,
        ];
    }

    /**
     * Internal: execute the pull logic.
     *
     * @return array{applied: int, errors: int}
     */
    private function doPull(): array
    {
        $stored = $this->state->get('last_sync_cursor');
        $cursor = $stored !== null ? (int) $stored : null;

        $limit        = config('tether-client.pull_page_size') ? (int) config('tether-client.pull_page_size') : null;
        $applied      = 0;
        $failedApplies = 0;

        do {
            TetherLog::debug('Pulling snapshots', 1, [
                'last_sync_cursor' => $cursor,
                'limit' => $limit,
            ]);

            $response = $this->http->pull($cursor, $limit);

            foreach ($response->snapshots as $snapshot) {
                try {
                    $this->snapshots->apply($snapshot);
                    $applied++;
                } catch (\Throwable $e) {
                    report($e);
                    $failedApplies++;
                }
            }

            if ($response->newSyncCursor !== null) {
                $cursor = $response->newSyncCursor;
                $this->state->set('last_sync_cursor', (string) $cursor);

                TetherLog::debug('Updated sync cursor', 2, [
                    'last_sync_cursor' => $cursor,
                ]);
            }
        } while ($response->hasMore);

        return [
            'applied' => $applied,
            'errors' => $failedApplies,
        ];
    }

    /**
     * Run a callback inside a cache lock.
     * Returns null (without calling the callback) if the lock cannot be acquired,
     * meaning another sync cycle is already in progress.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function withLock(callable $callback): mixed
    {
        if (! config('tether-client.sync_lock', true)) {
            return $callback();
        }

        $lock = Cache::lock('tether_sync_lock', 60);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
