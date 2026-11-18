<?php

namespace App\Support;

use App\Events\NotificationCreated;
use App\Models\Notification;

/** Persists an important notification and pushes it to the user's private channel. */
final class Notifier
{
    public static function send(int $userId, string $type, array $data): Notification
    {
        $notification = Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'data' => $data,
        ]);

        broadcast(new NotificationCreated($notification));

        return $notification;
    }
}
