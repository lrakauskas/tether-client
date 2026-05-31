<?php

namespace Tether\Client\Support;

use Illuminate\Support\Facades\Log;

class TetherLog
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function debug(string $message, int $requiredLevel = 1, array $context = []): void
    {
        if ((int) config('tether-client.debug_level', 0) < $requiredLevel) {
            return;
        }

        Log::debug('[TETHER] ' . $message, $context);
    }
}
