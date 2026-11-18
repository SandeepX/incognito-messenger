<?php

namespace App\Support;

/** Stable key for a pair of users, independent of request direction. */
final class PairKey
{
    public static function for(int $a, int $b): string
    {
        $ids = [$a, $b];
        sort($ids);

        return implode('-', $ids);
    }
}
