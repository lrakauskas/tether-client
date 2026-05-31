<?php

namespace Tests\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tether\Client\Traits\Syncable;

class TestPostWithSoftDeletes extends Model
{
    use Syncable;
    use SoftDeletes;

    protected $table = 'test_posts_soft';

    protected $fillable = ['title', 'body', 'tether_id'];
}
