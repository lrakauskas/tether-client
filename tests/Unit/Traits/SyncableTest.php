<?php

use Tests\Models\TestPost;
use Illuminate\Support\Facades\Queue;
use Tether\Client\Jobs\PushJob;
use Tether\Client\Models\MutationLog;
use Tether\Core\Enums\OperationType;

// ── Default behaviour: auto-increment PK + separate tether_id ────────────────

it('automatically assigns a ULID to tether_id on creating', function () {
    $post = TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);

    expect($post->tether_id)->toBeString()
        ->toHaveLength(26)
        ->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

it('does not overwrite tether_id if already set before create', function () {
    $post = new TestPost([
        'title' => 'Hello',
        'body' => null,
    ]);
    $post->tether_id = '01HXYZ0001AABBCCDD0011AABB';
    $post->save();

    expect($post->tether_id)->toBe('01HXYZ0001AABBCCDD0011AABB');
});

it('leaves the primary key as an integer (auto-increment)', function () {
    $post = TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);

    expect($post->id)->toBeInt();
});

it('uses tether_id as entity_id in mutation log, not the PK', function () {
    $post = TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);

    $log = MutationLog::first();

    expect($log->entity_id)->toBe($post->tether_id)
        ->and($log->entity_id)->not->toBe((string) $post->id);
});

it('returns tether_id as the default tether key name', function () {
    $post = new TestPost();

    expect($post->getTetherKeyName())->toBe('tether_id');
});

it('uses the configured sync key as the default tether key name', function () {
    config()->set('tether-client.sync_key', 'sync_id');

    $post = new TestPost();

    expect($post->getTetherKeyName())->toBe('sync_id');
});

it('returns the configured sync key value from getTetherKey()', function () {
    config()->set('tether-client.sync_key', 'sync_id');

    $model = new class extends \Illuminate\Database\Eloquent\Model {
        use \Tether\Client\Traits\Syncable;

        protected $table = 'test_posts';
    };

    $model->sync_id = '01HXYZ0001AABBCCDD0011AABB';

    expect($model->getTetherKey())->toBe('01HXYZ0001AABBCCDD0011AABB');
});

it('returns the tether_id value from getTetherKey()', function () {
    $post = TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);

    expect($post->getTetherKey())->toBe($post->tether_id);
});

it('generates a different tether_id for each model instance', function () {
    $a = TestPost::create([
        'title' => 'A',
        'body' => null,
    ]);
    $b = TestPost::create([
        'title' => 'B',
        'body' => null,
    ]);

    expect($a->tether_id)->not->toBe($b->tether_id);
});

// ── Property-based column name override ───────────────────────────────────────

it('respects a custom tetherKeyName property on the model', function () {
    config()->set('tether-client.sync_key', 'global_sync_id');

    $model = new class extends \Illuminate\Database\Eloquent\Model {
        use \Tether\Client\Traits\Syncable;

        protected $table = 'test_posts';

        protected string $tetherKeyName = 'sync_id';
    };

    expect($model->getTetherKeyName())->toBe('sync_id');
});

// ── Mutation events ───────────────────────────────────────────────────────────

it('writes a create mutation log entry after model is created', function () {
    TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);

    expect(MutationLog::count())->toBe(1)
        ->and(MutationLog::first()->operation)->toBe(OperationType::Create);
});

it('writes an update mutation log entry after model is updated', function () {
    $post = TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);
    $post->update([
        'title' => 'Updated',
    ]);

    expect(MutationLog::where('operation', OperationType::Update)->count())->toBe(1);
});

it('writes a delete mutation log entry after model is deleted', function () {
    $post = TestPost::create([
        'title' => 'Hello',
        'body' => null,
    ]);
    $post->delete();

    expect(MutationLog::where('operation', OperationType::Delete)->count())->toBe(1);
});

// ── Syncable fields ───────────────────────────────────────────────────────────

it('returns only whitelisted fields from getSyncableFields()', function () {
    $post = new TestPost();

    expect($post->getSyncableFields())->toBe(['title', 'body']);
});

it('returns an empty array from getSyncableFields() when $syncable is not defined', function () {
    $model = new class extends \Illuminate\Database\Eloquent\Model {
        use \Tether\Client\Traits\Syncable;

        protected $table = 'test_posts';
    };

    expect($model->getSyncableFields())->toBe([]);
});

// ── Wildcard fields ───────────────────────────────────────────────────────────

it('returns all fillable fields when $syncable = [\'*\']', function () {
    $model = new class extends \Illuminate\Database\Eloquent\Model {
        use \Tether\Client\Traits\Syncable;

        protected $table    = 'test_posts';

        protected $fillable = ['title', 'body'];

        protected array $syncable = ['*'];
    };

    expect($model->getSyncableFields())->toBe(['title', 'body']);
});

it('returns fillable minus excluded fields when $syncableExcept is set', function () {
    $model = new class extends \Illuminate\Database\Eloquent\Model {
        use \Tether\Client\Traits\Syncable;

        protected $table    = 'test_posts';

        protected $fillable = ['title', 'body', 'secret'];

        protected array $syncable = ['*'];

        protected array $syncableExcept = ['secret'];
    };

    expect($model->getSyncableFields())->toBe(['title', 'body']);
});

it('explicit whitelist in $syncable takes precedence over $syncableExcept', function () {
    $model = new class extends \Illuminate\Database\Eloquent\Model {
        use \Tether\Client\Traits\Syncable;

        protected $table    = 'test_posts';

        protected $fillable = ['title', 'body', 'secret'];

        protected array $syncable = ['title'];

        protected array $syncableExcept = ['body']; // should be ignored
    };

    // $syncable is an explicit list - use it as-is
    expect($model->getSyncableFields())->toBe(['title']);
});

it('dispatches a push job after local writes when auto sync is enabled', function () {
    Queue::fake();
    config([
        'tether-client.auto_sync' => true,
    ]);

    TestPost::create([
        'title' => 'Auto',
        'body' => null,
    ]);

    Queue::assertPushed(PushJob::class);
});
