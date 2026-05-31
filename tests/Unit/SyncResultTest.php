<?php

use Tether\Client\SyncResult;
use Tether\Core\Sync\PushRejection;

it('filters rejections by reason', function () {
    $validation = new PushRejection('mutation-1', 'validation_failed', [
        'title' => ['Required'],
    ]);
    $error = new PushRejection('mutation-2', 'error', []);

    $result = new SyncResult(rejections: [$validation, $error]);

    expect($result->rejectionsByReason('validation_failed'))->toBe([$validation])
        ->and($result->rejectionsByReason('error'))->toBe([$error])
        ->and($result->rejectionsByReason('not_found'))->toBe([]);
});

it('returns validation errors from validation failed rejections', function () {
    $validation = new PushRejection('mutation-1', 'validation_failed', [
        'title' => ['Required'],
    ]);
    $error = new PushRejection('mutation-2', 'error', []);

    $result = new SyncResult(rejections: [$validation, $error]);

    expect($result->validationErrors())->toBe([$validation]);
});
