<?php

namespace Tether\Client;

final readonly class ClientSyncRegistration
{
    /**
     * @param callable|null $payloadMapper
     * @param callable|null $mutationMapper
     */
    public function __construct(
        public mixed $payloadMapper = null,
        public mixed $mutationMapper = null,
    ) {}
}
