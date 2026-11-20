<?php

namespace App\Support;

use Illuminate\Support\Facades\Broadcast;
use Throwable;

class ReverbConnectionTerminator
{
    public static function forUser(int $userId): void
    {
        if (config('broadcasting.default') !== 'reverb') {
            return;
        }

        try {
            $broadcaster = Broadcast::connection('reverb');

            if (method_exists($broadcaster, 'getPusher')) {
                $broadcaster->getPusher()->terminateUserConnections((string) $userId);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
