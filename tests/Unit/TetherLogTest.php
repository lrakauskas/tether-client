<?php

use Illuminate\Support\Facades\Log;
use Tether\Client\Support\TetherLog;

it('suppresses debug logs when debug level is off', function () {
    config([
        'tether-client.debug_level' => 0,
    ]);
    Log::spy();

    TetherLog::debug('Hidden message', 1, [
        'cursor' => 123,
    ]);

    Log::shouldNotHaveReceived('debug');
});

it('suppresses debug logs above the configured level', function () {
    config([
        'tether-client.debug_level' => 1,
    ]);
    Log::spy();

    TetherLog::debug('Verbose message', 2, [
        'cursor' => 123,
    ]);

    Log::shouldNotHaveReceived('debug');
});

it('writes prefixed debug logs at the configured level', function () {
    config([
        'tether-client.debug_level' => 2,
    ]);
    Log::spy();

    TetherLog::debug('Visible message', 2, [
        'cursor' => 123,
    ]);

    Log::shouldHaveReceived('debug')
        ->once()
        ->with('[TETHER] Visible message', [
            'cursor' => 123,
        ]);
});
