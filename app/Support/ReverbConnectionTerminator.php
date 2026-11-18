<?php

namespace App\Support;

use Illuminate\Support\Facades\Broadcast;
use Throwable;

/**
 * Reverb authorizes a socket when it subscribes, but an already-open socket
 * is not re-authorized when a user is removed from a group. Removing the
 * database membership stops future subscription approvals; this service also
 * closes existing sockets so a removed member cannot keep listening with an
 * old, still-valid subscription.
 */
class ReverbConnectionTerminator
{
    public static function forUser(int $userId): void
    {
        // The test suite (and any non-Reverb deployment) has no Pusher API to
        // call. Channel callbacks and HTTP policies are still tested there.
        if (config('broadcasting.default') !== 'reverb') {
            return;
        }

        try {
            $broadcaster = Broadcast::connection('reverb');

            if (method_exists($broadcaster, 'getPusher')) {
                $broadcaster->getPusher()->terminateUserConnections((string) $userId);
            }
        } catch (Throwable $exception) {
            // A failed disconnect must never roll back the membership change;
            // the server-side authorization checks remain the real barrier.
            report($exception);
        }
    }
}
