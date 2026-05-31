<?php

namespace Tether\Client;

use Illuminate\Database\Eloquent\Model;
use Tether\Client\Support\TetherLog;
use Tether\Core\Sync\Snapshot;

class SnapshotApplicator
{
    public function __construct(
        private readonly string $modelNamespace,
        private readonly string $syncKeyColumn,
        private readonly ClientSyncRegistry $registry,
    ) {}

    /**
     * Apply a single state snapshot from the server to the local database.
     */
    public function apply(Snapshot $snapshot): void
    {
        $modelClass = rtrim($this->modelNamespace, '\\') . '\\' . $snapshot->model;

        if (! class_exists($modelClass)) {
            throw new \RuntimeException("Snapshot model class not found: {$modelClass}");
        }

        // Apply the client-side payload mapper when registered.
        $mapper = $this->registry->getPayloadMapper($modelClass);
        if ($mapper !== null) {
            $snapshot = ($mapper)($snapshot);

            TetherLog::debug('Applied snapshot payload mapper', 3, [
                'model_class' => $modelClass,
                'entity_id' => $snapshot->entityId,
            ]);
        }

        $entityId = $snapshot->entityId;
        $operation = $snapshot->operation;
        $payload = $snapshot->payload;

        // Run inside withoutEvents to prevent Syncable (and other observers) from
        // firing, avoiding double-logging or ULID re-assignment during inbound sync.
        Model::withoutEvents(function () use ($modelClass, $entityId, $operation, $payload): void {
            if ($operation === 'delete') {
                $modelClass::where($this->syncKeyColumn, $entityId)->delete();

                TetherLog::debug('Applied delete snapshot', 2, [
                    'model_class' => $modelClass,
                    'entity_id' => $entityId,
                ]);

                return;
            }

            // Remove sync key from the data set - it is provided separately in the
            // search array and merging it again is redundant.
            $data = $payload;
            unset($data[$this->syncKeyColumn]);

            $instance = $modelClass::firstWhere($this->syncKeyColumn, $entityId)
                ?? new $modelClass();

            // forceFill bypasses $fillable; server data is trusted.
            $instance->forceFill(array_merge([
                $this->syncKeyColumn => $entityId,
            ], $data));
            $instance->save();

            TetherLog::debug('Applied upsert snapshot', 2, [
                'model_class' => $modelClass,
                'entity_id' => $entityId,
                'operation' => $operation,
            ]);
        });
    }
}
