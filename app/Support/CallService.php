<?php

namespace App\Support;

use App\Events\CallEvent;
use App\Events\ConversationsUpdated;
use App\Events\MessageSent;
use App\Events\UnreadCountsUpdated;
use App\Models\Call;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Server-side call state. Every transition is persisted so a second tab,
 * a reconnect, or the stale-call sweeper can make the same decision.
 */
final class CallService
{
    public const RING_TIMEOUT_SECONDS = 30;

    /** @return array<int, array<string, mixed>> */
    public static function iceServers(): array
    {
        $servers = config('webrtc.ice_servers', []);

        return is_array($servers) ? array_values($servers) : [];
    }

    /** A user may only be ringing or active in one call at a time. */
    public static function activeCallFor(int $userId): ?Call
    {
        self::expireRingingCalls();

        return Call::query()
            ->whereIn('status', ['ringing', 'active'])
            ->where(function ($query) use ($userId) {
                $query->where('caller_id', $userId)->orWhere('callee_id', $userId);
            })
            ->latest('id')
            ->first();
    }

    /**
     * Lazy safety net for callers/callees who close every tab. The browser
     * also fires the 30s timeout, but this keeps state correct even then.
     */
    public static function expireRingingCalls(): int
    {
        $stale = Call::query()
            ->where('status', 'ringing')
            ->where('created_at', '<=', now()->subSeconds(self::RING_TIMEOUT_SECONDS))
            ->get();

        $expired = 0;
        foreach ($stale as $call) {
            $call = self::finish($call, 'missed', 'timeout');
            self::broadcast($call, 'call:end', [
                'reason' => 'timeout',
                'message' => 'No answer.',
            ]);
            $expired++;
        }

        return $expired;
    }

    /**
     * Move a ringing/active call to a terminal status exactly once and
     * write its history message. Callers broadcast the matching event.
     */
    public static function finish(
        Call $call,
        string $status,
        string $reason,
        ?int $endedBy = null,
    ): Call {
        $call->refresh();

        if (! in_array($call->status, ['ringing', 'active'], true)) {
            return $call;
        }

        $duration = $call->answered_at
            ? max(0, (int) $call->answered_at->diffInSeconds(now()))
            : null;

        // A single conditional UPDATE makes the transition atomic. Without it
        // a cancel and an accept racing each other could both observe
        // "ringing" and the loser would overwrite a terminal status.
        $updated = Call::query()
            ->whereKey($call->id)
            ->whereIn('status', ['ringing', 'active'])
            ->update([
                'status' => $status,
                'end_reason' => $reason,
                'ended_by' => $endedBy,
                'ended_at' => now(),
                'duration_seconds' => $duration,
            ]);

        if (! $updated) {
            return $call->fresh();
        }

        if (in_array($status, ['ended', 'missed', 'declined'], true)) {
            self::recordMessage($call->fresh());
        }

        if ($status === 'missed') {
            self::notifyMissed($call->fresh());
        }

        return $call->fresh();
    }

    /**
     * Remember a callee tab that started ringing. Returns true only for the
     * first tab, so the caller receives a single "Ringing..." transition.
     */
    public static function registerRingingTab(Call $call, string $tabId): bool
    {
        return DB::transaction(function () use ($call, $tabId) {
            $locked = Call::query()->whereKey($call->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->isRinging()) {
                return false;
            }

            $tabs = is_array($locked->ringing_tabs) ? $locked->ringing_tabs : [];
            $first = ! $locked->ringing_at;

            if (! in_array($tabId, $tabs, true)) {
                $tabs[] = $tabId;
            }

            $locked->forceFill([
                'ringing_tabs' => array_values($tabs),
                'ringing_at' => $locked->ringing_at ?: now(),
            ])->save();

            return $first;
        });
    }

    /**
     * A ringing tab went away. Other tabs keep ringing; the call only ends
     * once the last ringing tab is gone.
     */
    public static function leaveRinging(Call $call, int $userId, ?string $tabId): ?Call
    {
        return DB::transaction(function () use ($call, $userId, $tabId) {
            $locked = Call::query()->whereKey($call->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->isRinging() || (int) $locked->callee_id !== $userId) {
                return null;
            }

            $tabs = is_array($locked->ringing_tabs) ? $locked->ringing_tabs : [];

            if ($tabId !== null) {
                $tabs = array_values(array_filter($tabs, fn ($id) => $id !== $tabId));
            } elseif ($tabs !== []) {
                // A disconnect without a tab id cannot tell which tab vanished.
                return null;
            }

            if ($tabs !== []) {
                $locked->forceFill(['ringing_tabs' => $tabs])->save();

                return null;
            }

            return self::finish($locked, 'missed', 'disconnect', $userId);
        });
    }

    /**
     * Server-side disconnect safety net. The browser normally calls /end,
     * /reject or /cancel from pagehide; this covers tabs that vanish before
     * the request leaves (crash, killed process, lost network).
     *
     * A closing tab may only end a call it actually owns. Without this check
     * closing a second tab of the same user would hang up the first tab's
     * active call.
     */
    public static function endForUser(int $userId, ?string $tabId = null, string $reason = 'disconnect'): void
    {
        $call = self::activeCallFor($userId);
        if (! $call) {
            return;
        }

        if ($tabId !== null) {
            if ($call->isRinging()) {
                // The caller owns the ring from the tab that sent the invite.
                if ((int) $call->caller_id === $userId
                    && $call->caller_tab_id !== null
                    && $call->caller_tab_id !== $tabId) {
                    return;
                }
            } else {
                $ownerTab = (int) $call->caller_id === $userId
                    ? $call->caller_tab_id
                    : $call->callee_tab_id;

                if ($ownerTab !== null && $ownerTab !== $tabId) {
                    return;
                }
            }
        }

        if ($call->isRinging()) {
            if ((int) $call->caller_id === $userId) {
                $call = self::finish($call, 'missed', 'cancelled', $userId);
                self::broadcast($call, 'call:cancel', ['reason' => 'cancelled']);
            } else {
                $finished = self::leaveRinging($call, $userId, $tabId);

                if ($finished) {
                    self::broadcast($finished, 'call:end', [
                        'reason' => 'disconnect',
                        'message' => 'Call ended.',
                    ]);
                }
            }

            return;
        }

        $call = self::finish($call, 'ended', $reason, $userId);
        self::broadcast($call, 'call:end', [
            'reason' => $reason,
            'message' => 'Call ended.',
        ]);
    }

    /** Find or create the direct conversation that owns the call history. */
    public static function directConversation(User $a, User $b): Conversation
    {
        $key = Conversation::directKey((int) $a->id, (int) $b->id);

        return DB::transaction(function () use ($key, $a, $b) {
            $conversation = Conversation::firstOrCreate(
                ['direct_key' => $key],
                ['type' => 'direct'],
            );

            $conversation->participants()->syncWithoutDetaching([$a->id, $b->id]);

            return $conversation;
        });
    }

    public static function payload(Call $call): array
    {
        $call->loadMissing(['caller.profile', 'callee.profile']);

        $user = fn (?User $user) => $user ? [
            'id' => (int) $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'avatar' => $user->avatarUrl(),
        ] : null;

        return [
            'id' => (int) $call->id,
            'status' => $call->status,
            'conversation_id' => $call->conversation_id ? (int) $call->conversation_id : null,
            'caller_id' => (int) $call->caller_id,
            'callee_id' => (int) $call->callee_id,
            'caller' => $user($call->caller),
            'callee' => $user($call->callee),
            'started_at' => $call->created_at?->toISOString(),
            'ringing_at' => $call->ringing_at?->toISOString(),
            'answered_at' => $call->answered_at?->toISOString(),
            'ended_at' => $call->ended_at?->toISOString(),
            'duration_seconds' => $call->duration_seconds,
            'end_reason' => $call->end_reason,
            'caller_tab_id' => $call->caller_tab_id,
            'callee_tab_id' => $call->callee_tab_id,
        ];
    }

    public static function messageLabel(Call $call): string
    {
        return match ($call->status) {
            'declined' => 'Declined audio call',
            'missed' => 'Missed audio call',
            default => 'Audio call, '.self::formatDuration((int) ($call->duration_seconds ?? 0)),
        };
    }

    public static function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    public static function recordMessage(Call $call): ?Message
    {
        if (! $call->conversation_id) {
            return null;
        }

        $existing = Message::where('call_id', $call->id)->first();
        if ($existing) {
            return $existing;
        }

        $message = Message::create([
            'conversation_id' => $call->conversation_id,
            'sender_id' => $call->caller_id,
            'body' => self::messageLabel($call),
            'type' => 'call',
            'call_id' => $call->id,
        ]);

        $message->load(['sender.profile', 'reads.user.profile', 'attachments', 'call.caller.profile', 'call.callee.profile']);

        broadcast(new MessageSent($message));

        foreach ([$call->caller_id, $call->callee_id] as $userId) {
            broadcast(new ConversationsUpdated((int) $userId));
            broadcast(new UnreadCountsUpdated((int) $userId, UnreadCounter::for((int) $userId)));
        }

        return $message;
    }

    public static function notifyMissed(Call $call): void
    {
        $call->loadMissing('caller.profile');

        Notifier::send((int) $call->callee_id, 'missed_call', [
            'call_id' => (int) $call->id,
            'conversation_id' => $call->conversation_id ? (int) $call->conversation_id : null,
            'name' => $call->caller?->name ?? 'Someone',
            'username' => $call->caller?->username,
            'avatar' => $call->caller?->avatarUrl(),
            'created_at' => $call->created_at?->toISOString(),
        ]);
    }

    public static function broadcast(Call $call, string $event, array $extra = [], ?int $onlyUserId = null): void
    {
        broadcast(new CallEvent($call->fresh(), $event, $extra, $onlyUserId));
    }
}
