<?php

namespace Tether\Client;

use Illuminate\Support\Facades\DB;

/**
 * Persists key/value sync state (e.g. last_sync_cursor) to the database.
 * Backed by the tether_sync_state table so state survives process restarts.
 */
class SyncStateStore
{
    public function get(string $key, mixed $default = null): mixed
    {
        $connection = config('tether-client.connection');

        $row = DB::connection($connection)
            ->table('tether_sync_state')
            ->where('key', $key)
            ->value('value');

        return $row ?? $default;
    }

    public function set(string $key, string $value): void
    {
        $connection = config('tether-client.connection');

        DB::connection($connection)
            ->table('tether_sync_state')
            ->upsert(
                [[
                    'key' => $key,
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['key'],
                ['value', 'updated_at'],
            );
    }
}
