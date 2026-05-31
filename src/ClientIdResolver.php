<?php

namespace Tether\Client;

use Tether\Core\Identity\UlidGenerator;

/**
 * Resolves the stable client identifier for this installation.
 *
 * Resolution order:
 *   1. Developer-registered callable (set via TetherClientServiceProvider::resolveClientIdUsing())
 *   2. 'tether-client.client_id' config / TETHER_CLIENT_ID env
 *   3. Auto-generated ULID persisted in tether_sync_state (key: client_id)
 *
 * The resolved value is memoized in memory for the lifetime of the request.
 */
class ClientIdResolver
{
    private static ?\Closure $resolver = null;

    private ?string $resolved = null;

    public function __construct(
        private readonly SyncStateStore $store,
        private readonly UlidGenerator $ulids,
    ) {}

    /**
     * Register a custom callable to resolve the client ID at runtime.
     * Must be called before the first sync (e.g. in AppServiceProvider::boot()).
     */
    public static function resolveUsing(callable $resolver): void
    {
        self::$resolver = \Closure::fromCallable($resolver);
    }

    /**
     * Clear any registered resolver (useful in tests).
     */
    public static function forgetResolver(): void
    {
        self::$resolver = null;
    }

    /**
     * Resolve and return the client ID, memoizing the result.
     */
    public function resolve(): string
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        // 1. Developer-registered callable
        if (self::$resolver !== null) {
            $this->resolved = (string) (self::$resolver)();

            return $this->resolved;
        }

        // 2. Config / env
        $configured = config('tether-client.client_id', '');
        if ($configured !== '') {
            $this->resolved = (string) $configured;

            return $this->resolved;
        }

        // 3. Auto-generate and persist
        $persisted = $this->store->get('client_id');
        if ($persisted !== null) {
            $this->resolved = (string) $persisted;

            return $this->resolved;
        }

        $generated = $this->ulids->generate();
        $this->store->set('client_id', $generated);
        $this->resolved = $generated;

        return $this->resolved;
    }
}
