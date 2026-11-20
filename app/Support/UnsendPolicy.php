<?php

namespace App\Support;

final class UnsendPolicy
{
    public const NOT_SENDER = 'Only the sender can delete a message for everyone.';

    public const SEEN_MESSAGE = 'Seen messages can only be deleted for you.';

    public static function canDeleteForEveryone(bool $isSender, bool $hasBeenSeen): bool
    {
        return $isSender && ! $hasBeenSeen;
    }

    public static function failureReason(bool $isSender, bool $hasBeenSeen): ?string
    {
        if (! $isSender) {
            return self::NOT_SENDER;
        }

        if ($hasBeenSeen) {
            return self::SEEN_MESSAGE;
        }

        return null;
    }
}
