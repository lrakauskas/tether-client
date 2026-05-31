<?php

namespace Tether\Client\Facades;

use Illuminate\Support\Facades\Facade;
use Tether\Client\SyncEngine;

/**
 * @method static \Tether\Client\SyncResult sync()
 * @method static \Tether\Client\SyncResult push()
 * @method static \Tether\Client\SyncResult pull()
 * @method static \Tether\Core\Sync\SyncStatus syncStatus()
 *
 * @see \Tether\Client\SyncEngine
 */
class TetherClient extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SyncEngine::class;
    }
}
