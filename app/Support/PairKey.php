<?php

namespace App\Support;

final class PairKey
{
    public static function for(int $a, int $b): string
    {
        $ids = [$a, $b];
        sort($ids);

        return implode('-', $ids);
    }
}
