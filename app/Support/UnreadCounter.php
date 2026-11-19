<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class UnreadCounter
{
    /**
     * Unread message counts per conversation for one user, in a single
     * grouped query (no per-conversation round trips).
     *
     * @return array<int, int>  conversation_id => unread count
     */
    public static function for(int $userId): array
    {
        return DB::table('messages')
            ->join('conversation_participants as cp', 'cp.conversation_id', '=', 'messages.conversation_id')
            ->where('cp.user_id', $userId)
            ->where('messages.sender_id', '!=', $userId)
            ->where('messages.is_unsent', false)
            // Call-history rows are system messages: they never create an
            // unread badge or a read receipt.
            ->where('messages.type', '!=', 'call')
            ->where(function ($query) use ($userId) {
                $query->whereNull('messages.deleted_for')
                    ->orWhereJsonDoesntContain('messages.deleted_for', $userId);
            })
            ->whereNotExists(function ($query) use ($userId) {
                $query->select(DB::raw(1))
                    ->from('message_reads')
                    ->whereColumn('message_reads.message_id', 'messages.id')
                    ->where('message_reads.user_id', $userId);
            })
            ->select('messages.conversation_id', DB::raw('count(*) as unread_count'))
            ->groupBy('messages.conversation_id')
            ->pluck('unread_count', 'conversation_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
