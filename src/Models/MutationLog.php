<?php

namespace Tether\Client\Models;

use Illuminate\Database\Eloquent\Model;
use Tether\Core\Enums\OperationType;
use Tether\Core\Enums\SyncStatus;
use Tether\Core\Mutation\Mutation;

/**
 * @property string $mutation_id
 * @property string $entity_id
 * @property string $model
 * @property OperationType $operation
 * @property array<string, mixed> $payload
 * @property int $version
 * @property int $timestamp
 */
class MutationLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'operation'      => OperationType::class,
            'sync_status'    => SyncStatus::class,
            'payload'        => 'array',
            'rejection_data' => 'array',
            'version'        => 'integer',
            'timestamp'      => 'integer',
            'synced_at'      => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return config('tether-client.table', 'tether_mutation_logs');
    }

    public function getConnectionName(): ?string
    {
        return config('tether-client.connection') ?? parent::getConnectionName();
    }

    /**
     * Convert this log row into a core Mutation value object.
     */
    public function toMutation(): Mutation
    {
        return new Mutation(
            mutationId: $this->mutation_id,
            entityId: $this->entity_id,
            model: $this->model,
            operation: $this->operation,
            payload: $this->payload,
            version: $this->version,
            timestamp: $this->timestamp,
        );
    }
}
