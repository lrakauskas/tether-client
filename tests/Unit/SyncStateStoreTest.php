<?php

use Tether\Client\SyncStateStore;

it('returns the default value when key does not exist', function () {
    $store = app(SyncStateStore::class);

    expect($store->get('last_sync_cursor', 0))->toBe(0)
        ->and($store->get('missing_key', 'fallback'))->toBe('fallback');
});

it('returns null when key does not exist and no default is given', function () {
    $store = app(SyncStateStore::class);

    expect($store->get('nonexistent'))->toBeNull();
});

it('stores a value and retrieves it', function () {
    $store = app(SyncStateStore::class);

    $store->set('last_sync_cursor', '42');

    expect($store->get('last_sync_cursor'))->toBe('42');
});

it('overwrites an existing value', function () {
    $store = app(SyncStateStore::class);

    $store->set('last_sync_cursor', '10');
    $store->set('last_sync_cursor', '99');

    expect($store->get('last_sync_cursor'))->toBe('99');
});

it('stores multiple independent keys', function () {
    $store = app(SyncStateStore::class);

    $store->set('cursor_a', '1');
    $store->set('cursor_b', '2');

    expect($store->get('cursor_a'))->toBe('1')
        ->and($store->get('cursor_b'))->toBe('2');
});
