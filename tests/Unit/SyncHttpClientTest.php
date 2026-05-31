<?php

use Illuminate\Support\Facades\Http;
use Tether\Client\SyncHttpClient;
use Tether\Core\Mutation\Mutation;
use Tether\Core\Sync\PullResult;
use Tether\Core\Sync\PushResult;

it('outgoing push request includes the X-Tether-Client-Version header', function () {
    Http::fake([
        '*' => Http::response([
            'applied' => [],
            'rejected' => [],
            'conflicts' => [],
        ]),
    ]);

    $client = new SyncHttpClient(
        pushUrl: 'https://example.test/tether/push',
        pullUrl: 'https://example.test/tether/pull',
        clientId: 'test-client',
    );

    $result = $client->push([]);

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-Tether-Client-Version');
    });

    expect($result)->toBeInstanceOf(PushResult::class);
});

it('outgoing pull request includes the X-Tether-Client-Version header', function () {
    Http::fake([
        '*' => Http::response([
            'snapshots' => [],
            'new_sync_cursor' => null,
            'has_more' => false,
        ]),
    ]);

    $client = new SyncHttpClient(
        pushUrl: 'https://example.test/tether/push',
        pullUrl: 'https://example.test/tether/pull',
        clientId: 'test-client',
    );

    $result = $client->pull(null);

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-Tether-Client-Version');
    });

    expect($result)->toBeInstanceOf(PullResult::class);
});

it('applies registered middleware to outgoing requests', function () {
    Http::fake([
        '*' => Http::response([
            'applied' => [],
            'rejected' => [],
            'conflicts' => [],
        ]),
    ]);

    $client = (new SyncHttpClient(
        pushUrl: 'https://example.test/tether/push',
        pullUrl: 'https://example.test/tether/pull',
        clientId: 'test-client',
    ))->withMiddleware(function (callable $handler): callable {
        return fn($request, array $options) => $handler($request->withHeader('X-Test-Middleware', 'yes'), $options);
    });

    $client->push([
        Mutation::fromArray([
            'mutation_id' => '01HXYZ0000AABBCCDD0011AAB1',
            'entity_id' => '01HXYZ0001AABBCCDD0011AAB1',
            'model' => 'TestPost',
            'operation' => 'create',
            'payload' => [
                'title' => 'Hello',
            ],
            'version' => 1,
            'timestamp' => 1,
        ]),
    ]);

    Http::assertSent(fn($request) => $request->hasHeader('X-Test-Middleware', 'yes'));
});

it('includes the pull limit when one is provided', function () {
    Http::fake([
        '*' => Http::response([
            'snapshots' => [],
            'new_sync_cursor' => null,
            'has_more' => false,
        ]),
    ]);

    $client = new SyncHttpClient(
        pushUrl: 'https://example.test/tether/push',
        pullUrl: 'https://example.test/tether/pull',
        clientId: 'test-client',
    );

    $client->pull(123, 50);

    Http::assertSent(fn($request) => $request['last_sync_cursor'] === 123 && $request['limit'] === 50);
});
