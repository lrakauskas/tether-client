<?php

namespace Tether\Client\Traits;

use Illuminate\Support\Str;
use Tether\Client\Jobs\PushJob;
use Tether\Client\MutationLogger;

/**
 * Apply this trait to any Eloquent model that should participate in Tether sync.
 *
 * Tether does not require a ULID primary key. Models keep their own primary key
 * strategy (auto-increment, UUID, etc.). A dedicated sync identity column
 * (default: `tether_id`) holds the client-generated ULID used as `entity_id`
 * in all mutation log entries.
 *
 * Usage - default (add tether_id column via tetherUlid() in migration):
 *
 *   class Post extends Model
 *   {
 *       use Syncable;
 *
 *       protected array $syncable = ['title', 'body'];
 *   }
 *
 * Usage - custom per-model column name:
 *
 *   class Post extends Model
 *   {
 *       use Syncable;
 *
 *       protected string $tetherKeyName = 'sync_id';
 *       protected array $syncable = ['title', 'body'];
 *   }
 */
trait Syncable
{
    public static function bootSyncable(): void
    {
        // Assign a ULID to the sync identity column before the record is inserted.
        static::creating(function (self $model): void {
            $col = $model->getTetherKeyName();
            if (empty($model->{$col})) {
                $model->{$col} = (string) Str::ulid();
            }
        });

        // Log a create mutation after the record is persisted.
        static::created(function (self $model): void {
            app(MutationLogger::class)->recordCreate($model, $model->getSyncableFields());
            static::dispatchAutoSync();
        });

        // Log an update mutation after the record is updated.
        static::updated(function (self $model): void {
            app(MutationLogger::class)->recordUpdate($model, $model->getSyncableFields());
            static::dispatchAutoSync();
        });

        // Log a delete mutation after the record is removed.
        static::deleted(function (self $model): void {
            app(MutationLogger::class)->recordDelete($model);
            static::dispatchAutoSync();
        });
    }

    /**
     * The column name used by Tether as the global sync identity.
     *
     * Override this method (or set $tetherKeyName) to use a different column,
     * or return $this->getKeyName() to use the primary key directly.
     */
    public function getTetherKeyName(): string
    {
        return property_exists($this, 'tetherKeyName')
            ? $this->tetherKeyName
            : config('tether-client.sync_key', 'tether_id');
    }

    /**
     * The current value of the sync identity column for this model instance.
     */
    public function getTetherKey(): ?string
    {
        $value = $this->{$this->getTetherKeyName()};

        return $value !== null ? (string) $value : null;
    }

    /**
     * Returns the whitelisted field names for sync payloads.
     *
     * Three modes:
     *   1. $syncable = ['title', 'body']   - explicit whitelist (default behaviour)
     *   2. $syncable = ['*']               - sync all fillable columns
     *   3. $syncableExcept = ['password']  - sync all fillable except listed columns
     *      (only consulted when $syncable is empty or ['*'])
     *
     * @return string[]
     */
    public function getSyncableFields(): array
    {
        $syncable = $this->syncable ?? [];

        // Explicit whitelist - use as-is.
        if (! empty($syncable) && $syncable !== ['*']) {
            return $syncable;
        }

        // Wildcard or empty - derive from fillable columns.
        $fields = $this->getFillable();

        // Exclude fields listed in $syncableExcept.
        $except = $this->syncableExcept ?? [];
        if (! empty($except)) {
            $fields = array_values(array_diff($fields, $except));
        }

        return $fields;
    }

    /**
     * Dispatch a push-only PushJob if auto_sync is enabled.
     * Called internally after every mutation event.
     */
    protected static function dispatchAutoSync(): void
    {
        if (config('tether-client.auto_sync', false)) {
            PushJob::dispatch();
        }
    }
}
