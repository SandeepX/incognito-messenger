<?php

namespace App\Support;

/**
 * Pure rule for "delete for everyone": only the sender may remove a message
 * for both sides, and only while the recipient has not read it yet.
 *
 * HTTP status codes stay in the controller; this class only answers whether
 * the action is allowed and why not.
 */
final class UnsendPolicy
{
    public const NOT_SENDER = 'Only the sender can delete a message for everyone.';

    public const SEEN_MESSAGE = 'Seen messages can only be deleted for you.';

    public static function canDeleteForEveryone(bool $isSender, bool $hasBeenSeen): bool
    {
        return $isSender && ! $hasBeenSeen;
    }

    /** Null when allowed, otherwise the user-facing rejection reason. */
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
