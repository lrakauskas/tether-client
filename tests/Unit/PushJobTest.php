<?php

use Illuminate\Support\Facades\Queue;
use Tether\Client\Jobs\PushJob;

// ── Deduplication ─────────────────────────────────────────────────────────────

it('has a unique job id per dispatch when auto_sync_throttle is 0', function () {
    config([
        'tether-client.auto_sync_throttle' => 0,
    ]);

    $a = new PushJob();
    $b = new PushJob();

    expect($a->uniqueId())->not->toBe($b->uniqueId());
});

it('shares the same unique id across dispatches when auto_sync_throttle is set', function () {
    config([
        'tether-client.auto_sync_throttle' => 30,
        'tether-client.client_id'          => 'test-client',
    ]);

    $a = new PushJob();
    $b = new PushJob();

    expect($a->uniqueId())->toBe($b->uniqueId())
        ->and($a->uniqueId())->toBe('tether-push-test-client');
});

it('returns 0 for uniqueFor when auto_sync_throttle is 0', function () {
    config([
        'tether-client.auto_sync_throttle' => 0,
    ]);

    expect((new PushJob())->uniqueFor())->toBe(0);
});

it('returns the configured value for uniqueFor when auto_sync_throttle is set', function () {
    config([
        'tether-client.auto_sync_throttle' => 45,
    ]);

    expect((new PushJob())->uniqueFor())->toBe(45);
});

it('uses the configured queue name', function () {
    Queue::fake();

    config([
        'tether-client.auto_sync_queue' => 'sync',
    ]);

    PushJob::dispatch();

    Queue::assertPushedOn('sync', PushJob::class);
});
