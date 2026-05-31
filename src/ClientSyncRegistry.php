<?php

namespace Tether\Client;

use Tether\Client\Support\TetherLog;
use Tether\Core\Mutation\Mutation;
use Tether\Core\Sync\Snapshot;

class ClientSyncRegistry
{
    /**
     * @var array<class-string, ClientSyncRegistration>
     */
    private array $registrations = [];

    /**
     * Register transform callbacks for a model class.
     *
     * @param  class-string  $modelClass
     * @param  (callable(Snapshot $payloadMapper): Snapshot)|null  $payloadMapper
     *         Transform inbound server snapshot data before it is written to the local database.
     * @param  (callable(Mutation $mutationMapper): Mutation)|null  $mutationMapper
     *         Transform an outbound mutation before it is sent to the server.
     */
    public function register(
        string $modelClass,
        ?callable $payloadMapper = null,
        ?callable $mutationMapper = null,
    ): void {
        $this->registrations[$modelClass] = new ClientSyncRegistration(
            payloadMapper: $payloadMapper,
            mutationMapper: $mutationMapper,
        );

        TetherLog::debug('Registered client sync model', 2, [
            'model_class' => $modelClass,
            'has_payload_mapper' => $payloadMapper !== null,
            'has_mutation_mapper' => $mutationMapper !== null,
        ]);
    }

    /**
     * @param  class-string  $modelClass
     */
    public function getPayloadMapper(string $modelClass): ?callable
    {
        $mapper = $this->registrations[$modelClass]->payloadMapper ?? null;

        return is_callable($mapper) ? $mapper : null;
    }

    /**
     * @param  class-string  $modelClass
     */
    public function getMutationMapper(string $modelClass): ?callable
    {
        $mapper = $this->registrations[$modelClass]->mutationMapper ?? null;

        return is_callable($mapper) ? $mapper : null;
    }
}
