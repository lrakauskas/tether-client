<?php

namespace Tether\Client;

use Illuminate\Database\Eloquent\Model;
use Tether\Client\Models\MutationLog;
use Tether\Client\Support\TetherLog;
use Tether\Core\Enums\OperationType;
use Tether\Core\Enums\SyncStatus;
use Tether\Core\Identity\UlidGenerator;

class MutationLogger
{
    public function __construct(
        private readonly UlidGenerator $ulid,
    ) {}

    /**
     * Record a create mutation for the given model instance.
     * The payload is the full snapshot of whitelisted fields.
     */
    /**
     * @param string[] $syncableFields
     */
    public function recordCreate(Model $model, array $syncableFields): void
    {
        $this->record($model, OperationType::Create, $syncableFields);
    }

    /**
     * Record an update mutation for the given model instance.
     * The payload is the full snapshot of whitelisted fields (not a diff).
     */
    /**
     * @param string[] $syncableFields
     */
    public function recordUpdate(Model $model, array $syncableFields): void
    {
        $this->record($model, OperationType::Update, $syncableFields);
    }

    /**
     * Record a delete mutation for the given model instance.
     * The payload is empty - only entity_id is required to process the deletion.
     */
    public function recordDelete(Model $model): void
    {
        $this->record($model, OperationType::Delete, []);
    }

    /**
     * @param string[] $syncableFields
     */
    private function record(Model $model, OperationType $operation, array $syncableFields): void
    {
        $entityId = $this->tetherKeyFor($model);

        $payload = match ($operation) {
            OperationType::Delete => [],
            default               => $this->buildPayload($model, $syncableFields),
        };

        $version = $this->nextVersion($entityId);

        $mutationId = $this->ulid->generate();
        $timestamp = (int) now()->getPreciseTimestamp(3);

        MutationLog::create([
            'mutation_id' => $mutationId,
            'entity_id'   => $entityId,
            'model'       => class_basename($model),
            'operation'   => $operation,
            'payload'     => $payload,
            'version'     => $version,
            'timestamp'   => $timestamp,
            'sync_status' => SyncStatus::Pending,
        ]);

        TetherLog::debug('Recorded local mutation', 2, [
            'mutation_id' => $mutationId,
            'entity_id' => $entityId,
            'model' => class_basename($model),
            'operation' => $operation->value,
            'version' => $version,
            'timestamp' => $timestamp,
        ]);
    }

    /**
     * Build the sync payload by extracting only the whitelisted fields.
     *
     * @param  string[]  $syncableFields
     * @return array<string, mixed>
     */
    private function buildPayload(Model $model, array $syncableFields): array
    {
        return collect($model->getAttributes())
            ->only($syncableFields)
            ->all();
    }

    /**
     * Derive the next monotonic version for an entity from the mutation log.
     */
    private function nextVersion(string $entityId): int
    {
        $max = MutationLog::where('entity_id', $entityId)->max('version');

        return ($max ?? 0) + 1;
    }

    private function tetherKeyFor(Model $model): string
    {
        if (! method_exists($model, 'getTetherKey')) {
            throw new \InvalidArgumentException('Tether mutations can only be logged for models using the Syncable trait.');
        }

        return (string) $model->getTetherKey();
    }
}
