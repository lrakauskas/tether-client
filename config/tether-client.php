<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The database connection Tether uses to store the mutation log.
    | Set to null to use your application's default connection.
    |
    */
    'connection' => env('TETHER_DB_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | Mutation Log Table Name
    |--------------------------------------------------------------------------
    |
    | The name of the table that stores all mutation log entries.
    |
    */
    'table' => env('TETHER_TABLE', 'tether_mutation_logs'),

    /*
    |--------------------------------------------------------------------------
    | Default Sync Key Column
    |--------------------------------------------------------------------------
    |
    | The default column name used as the sync identity on Syncable models.
    | This column holds a client-generated ULID and is separate from the
    | model's primary key. Override per-model using $tetherKeyName or
    | getTetherKeyName().
    |
    */
    'sync_key' => env('TETHER_SYNC_KEY', 'tether_id'),

    /*
    |--------------------------------------------------------------------------
    | Model Namespace
    |--------------------------------------------------------------------------
    |
    | The namespace prefix used when resolving model classes from incoming
    | server mutations (during pull). For example, a mutation with model
    | "Post" will resolve to "App\Models\Post" by default.
    |
    */
    'model_namespace' => env('TETHER_MODEL_NAMESPACE', 'App\\Models'),

    /*
    |--------------------------------------------------------------------------
    | Server Routes
    |--------------------------------------------------------------------------
    |
    | Full URLs for the Tether server sync endpoints. Configure these to match
    | wherever the server package's routes are registered. You can even
    | point push and pull at different hosts if needed.
    |
    | These should match POST /{route_prefix}/push and /{route_prefix}/pull on
    | the server (default prefix is 'tether').
    |
    | Example:
    |   'push' => env('TETHER_SERVER_PUSH_URL', 'https://server.test/tether/push'),
    |   'pull' => env('TETHER_SERVER_PULL_URL', 'https://server.test/tether/pull'),
    |
    */
    'server_routes' => [
        'push' => env('TETHER_SERVER_PUSH_URL', ''),
        'pull' => env('TETHER_SERVER_PULL_URL', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Client ID
    |--------------------------------------------------------------------------
    |
    | A unique identifier for this client. Sent with every sync request so the
    | server can track which client submitted each mutation.
    |
    */
    'client_id' => env('TETHER_CLIENT_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | Client ID Resolver (class string)
    |--------------------------------------------------------------------------
    |
    | An optional invokable class that returns the client ID at runtime.
    | This is config-cache safe (class string only - no closures here).
    |
    | For closure-based resolution, use in AppServiceProvider::boot():
    |   \Tether\Client\ClientIdResolver::resolveUsing(fn() => ...);
    |
    | This config key is checked only when no callable has been registered
    | via resolveUsing() and 'client_id' is empty.
    |
    | Example: 'client_id_resolver' => \App\Tether\DeviceIdResolver::class,
    |
    */
    'client_id_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Auto Sync
    |--------------------------------------------------------------------------
    |
    | When enabled, a PushJob is dispatched to the queue automatically after
    | every Syncable model create/update/delete. The job performs a push-only
    | sync (never pull, to avoid feedback loops).
    |
    | Requires a configured queue worker. Set to false for manual sync only.
    |
    */
    'auto_sync' => env('TETHER_AUTO_SYNC', false),

    /*
    |--------------------------------------------------------------------------
    | Auto Sync Queue
    |--------------------------------------------------------------------------
    |
    | The queue name to dispatch PushJob onto. Null uses the default queue.
    |
    */
    'auto_sync_queue' => env('TETHER_AUTO_SYNC_QUEUE', null),

    /*
    |--------------------------------------------------------------------------
    | Auto Sync Debounce (seconds)
    |--------------------------------------------------------------------------
    |
    | When set to a positive integer, PushJob implements job-level deduplication
    | via ShouldBeUniqueUntilProcessing. If a PushJob is already pending in the
    | queue, subsequent dispatches within this window are silently dropped -
    | coalescing rapid back-to-back mutations into a single sync attempt.
    |
    | The deduplication lock is released as soon as the job starts processing,
    | so a new sync can be dispatched immediately after the first one begins.
    |
    | Set to 0 (the default) to disable deduplication and queue every dispatch.
    |
    | Requires a cache driver that supports atomic locks (Redis, database, file).
    |
    */
    'auto_sync_throttle' => (int) env('TETHER_AUTO_SYNC_THROTTLE', 0),

    /*
    |--------------------------------------------------------------------------
    | Pull Page Size
    |--------------------------------------------------------------------------
    |
    | When set, each pull request will ask the server for at most this many
    | snapshots. If the server responds with has_more: true the client will
    | automatically issue additional pull requests until all pages are consumed.
    |
    | Set to null (the default) to disable pagination and pull all changed
    | records in a single request - the behaviour prior to this feature.
    |
    */
    'pull_page_size' => env('TETHER_PULL_PAGE_SIZE', null),

    /*
    |--------------------------------------------------------------------------
    | Push Batch Size
    |--------------------------------------------------------------------------
    |
    | Maximum number of pending mutations sent in a single push request.
    | When the pending queue exceeds this size, multiple requests are made
    | sequentially until all mutations are pushed.
    |
    | Set to 0 or null to disable batching (all pending mutations in one request).
    |
    */
    'push_batch_size' => env('TETHER_PUSH_BATCH_SIZE', 100),

    /*
    |--------------------------------------------------------------------------
    | Max Retry Attempts
    |--------------------------------------------------------------------------
    |
    | How many times a failed mutation (rejection reason 'error') will be
    | automatically re-queued and retried on subsequent push calls.
    |
    | Once a mutation's retry_count reaches this value it remains in the Failed
    | state permanently. Set to 0 to disable automatic retry entirely.
    |
    */
    'max_retry_attempts' => env('TETHER_MAX_RETRY_ATTEMPTS', 3),

    /*
    |--------------------------------------------------------------------------
    | Sync Lock
    |--------------------------------------------------------------------------
    |
    | Tether uses Laravel's atomic cache lock (Cache::lock) to prevent multiple
    | sync cycles running concurrently. This requires your application's default
    | cache driver to support atomic locks:
    |
    |   memcached, redis, dynamodb, database, file, or array
    |
    | Set to false to disable the lock entirely. Only do this if your cache
    | driver does not support atomic locks, or if you are certain concurrent
    | sync cycles cannot occur in your environment (e.g. single-process apps).
    |
    */
    'sync_lock' => env('TETHER_SYNC_LOCK', true),

    /*
    |--------------------------------------------------------------------------
    | Debug Level
    |--------------------------------------------------------------------------
    |
    | Controls Tether's package-level debug logging.
    | 0 = off, 1 = lifecycle summaries, 2 = decisions/outcomes,
    | 3 = verbose diagnostics.
    |
    | All Tether debug log messages are prefixed with [TETHER].
    |
    */
    'debug_level' => env('TETHER_DEBUG_LEVEL', 0),
];
