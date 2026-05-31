<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Tether\Client\Traits\Syncable;

/**
 * Default test model - uses auto-increment PK + separate tether_id sync column.
 * This represents the standard Tether usage pattern.
 */
class TestPost extends Model
{
    use Syncable;

    protected $table = 'test_posts';

    protected $fillable = ['title', 'body'];

    protected array $syncable = ['title', 'body'];
}
