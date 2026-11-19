<?php

namespace App\Http\Controllers;

use App\Models\Call;
use App\Models\Conversation;
use App\Models\User;
use App\Support\CallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CallController extends Controller
{
    /** Authenticated browser configuration (STUN/TURN + ringtone prefs). */
    public function config(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('profile');

        return response()->json([
            'ice_servers' => CallService::iceServers(),
            'ringtone' => $user->callRingtoneEnabled(),
            'ringtone_volume' => $user->messageSoundsVolume(),
            'allow_calls' => $user->callPolicy(),
        ]);
    }

    public function invite(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
            'tab_id' => ['required', 'string', 'max:64'],
        ]);

        $me = $request->user()->loadMissing('profile');
        $target = User::with('profile')->findOrFail((int) $data['user_id']);

        abort_if($target->id === $me->id, 422, 'You cannot call yourself.');
        abort_if($me->blocksEitherWay((int) $target->id), 403, 'You cannot call this user.');
        abort_unless($target->acceptsCallsFrom($me), 403, 'This user is not accepting audio calls.');

        $conversation = $this->resolveConversation($me, $target, $data['conversation_id'] ?? null);
        abort_unless($me->isFriendsWith((int) $target->id), 403, 'You can only call accepted friends.');

        // Serialize invitations per user pair so two simultaneous calls cannot
        // both pass the "is this person free?" check and start ringing.
        $outcome = DB::transaction(function () use ($me, $target, &$conversation, $data) {
            User::whereKey($me->id)->lockForUpdate()->first();
            $lockedTarget = User::whereKey($target->id)->lockForUpdate()->firstOrFail();

            // The caller is already busy: this request must not ring anybody.
            if (CallService::activeCallFor((int) $me->id)) {
                return ['outcome' => 'caller_busy'];
            }

            // A ringing/active callee is busy. The caller sees the call:busy event.
            if (CallService::activeCallFor((int) $lockedTarget->id)) {
                return ['outcome' => 'busy'];
            }

            // Presence is based on the existing 30s heartbeat. Offline callees
            // still get a missed-call notification and a chat history entry.
            if (! $lockedTarget->last_active_at || $lockedTarget->last_active_at->lt(now()->subSeconds(90))) {
                return ['outcome' => 'unavailable'];
            }

            // Only a call that actually rings needs a history thread.
            $conversation = $conversation ?: CallService::directConversation($me, $target);

            return [
                'outcome' => 'ringing',
                'call' => Call::create([
                    'caller_id' => $me->id,
                    'callee_id' => $target->id,
                    'conversation_id' => $conversation->id,
                    'status' => 'ringing',
                    'caller_tab_id' => $data['tab_id'],
                ]),
            ];
        });

        if ($outcome['outcome'] === 'caller_busy') {
            return response()->json([
                'message' => 'You are already in a call.',
                'reason' => 'caller_busy',
            ], 409);
        }

        if ($outcome['outcome'] === 'busy') {
            $call = $this->terminalCall($me, $target, $conversation, 'busy', 'busy');
            CallService::broadcast($call, 'call:busy', [
                'reason' => 'busy',
                'message' => 'User is busy.',
            ], (int) $me->id);

            return response()->json([
                'message' => 'User is busy.',
                'reason' => 'busy',
                'call' => CallService::payload($call),
            ], 409);
        }

        if ($outcome['outcome'] === 'unavailable') {
            // Offline callers still get a missed-call row in the chat.
            $conversation = $conversation ?: CallService::directConversation($me, $target);
            $call = $this->terminalCall($me, $target, $conversation, 'missed', 'unavailable');
            CallService::recordMessage($call);
            CallService::notifyMissed($call);
            CallService::broadcast($call, 'call:busy', [
                'reason' => 'unavailable',
                'message' => 'User is unavailable.',
            ], (int) $me->id);

            return response()->json([
                'message' => 'User is unavailable.',
                'reason' => 'unavailable',
                'call' => CallService::payload($call),
            ], 409);
        }

        $call = $outcome['call'];
        CallService::broadcast($call, 'call:invite', [
            'role' => 'callee',
        ], (int) $target->id);

        return response()->json(['call' => CallService::payload($call)], 201);
    }

    /** Callee tabs acknowledge the ring so the caller can show "Ringing...". */
    public function ringing(Request $request, Call $call): JsonResponse
    {
        $this->assertParticipant($call, (int) $request->user()->id);
        abort_unless((int) $call->callee_id === (int) $request->user()->id, 403);

        $data = $request->validate([
            'tab_id' => ['nullable', 'string', 'max:64'],
        ]);

        $call->refresh();
        if (! $call->isRinging()) {
            return response()->json(['ok' => true, 'status' => $call->status]);
        }

        if ($call->created_at->lte(now()->subSeconds(CallService::RING_TIMEOUT_SECONDS))) {
            $call = CallService::finish($call, 'missed', 'timeout');
            CallService::broadcast($call, 'call:end', ['reason' => 'timeout', 'message' => 'No answer.']);

            return response()->json(['ok' => false, 'status' => $call->status], 410);
        }

        // The first tab to acknowledge moves the caller to "Ringing...".
        // Every tab is tracked so closing one does not stop the others.
        $first = CallService::registerRingingTab($call, (string) ($data['tab_id'] ?? 'default'));

        if ($first) {
            CallService::broadcast($call->fresh(), 'call:ringing', [], (int) $call->caller_id);
        }

        return response()->json(['ok' => true, 'status' => $call->status]);
    }

    /** A ringing callee tab is going away; keep ringing while others remain. */
    public function leave(Request $request, Call $call): JsonResponse
    {
        $me = (int) $request->user()->id;
        $this->assertParticipant($call, $me);
        abort_unless((int) $call->callee_id === $me, 403);

        $data = $request->validate([
            'tab_id' => ['nullable', 'string', 'max:64'],
        ]);

        $call->refresh();
        if (! $call->isRinging()) {
            return response()->json(['call' => CallService::payload($call)]);
        }

        $finished = CallService::leaveRinging($call, $me, $data['tab_id'] ?? null);

        if ($finished) {
            CallService::broadcast($finished, 'call:end', [
                'reason' => 'disconnect',
                'message' => 'Call ended.',
            ]);
        }

        return response()->json(['call' => CallService::payload($finished ?? $call->fresh())]);
    }

    public function accept(Request $request, Call $call): JsonResponse
    {
        $me = (int) $request->user()->id;
        $this->assertParticipant($call, $me);
        abort_unless((int) $call->callee_id === $me, 403);

        $data = $request->validate([
            'tab_id' => ['required', 'string', 'max:64'],
        ]);

        $call->refresh();
        abort_unless($call->isRinging(), 409, 'This call is no longer ringing.');

        if ($call->created_at->lte(now()->subSeconds(CallService::RING_TIMEOUT_SECONDS))) {
            $call = CallService::finish($call, 'missed', 'timeout');
            CallService::broadcast($call, 'call:end', ['reason' => 'timeout', 'message' => 'No answer.']);

            return response()->json(['message' => 'This call has ended.', 'call' => CallService::payload($call)], 410);
        }

        // Accept only a call that is still ringing at write time. A caller
        // cancel or the 30s sweeper may have ended it between the checks.
        $accepted = Call::query()
            ->whereKey($call->id)
            ->where('status', 'ringing')
            ->update([
                'status' => 'active',
                'answered_at' => now(),
                'callee_tab_id' => $data['tab_id'],
            ]);

        if (! $accepted) {
            $call->refresh();

            return response()->json([
                'message' => 'This call is no longer ringing.',
                'call' => CallService::payload($call),
            ], 409);
        }

        $call->refresh();

        CallService::broadcast($call, 'call:accept', [
            'accepted_tab_id' => $data['tab_id'],
        ]);

        return response()->json(['call' => CallService::payload($call)]);
    }

    public function reject(Request $request, Call $call): JsonResponse
    {
        $me = (int) $request->user()->id;
        $this->assertParticipant($call, $me);
        abort_unless((int) $call->callee_id === $me, 403);

        $call->refresh();
        if (! $call->isRinging()) {
            return response()->json(['call' => CallService::payload($call)]);
        }

        $call = CallService::finish($call, 'declined', 'declined', $me);
        CallService::broadcast($call, 'call:reject', ['reason' => 'declined']);

        return response()->json(['call' => CallService::payload($call)]);
    }

    /**
     * A participant reports that the callee is busy. The server still owns
     * the transition: it only ends a ringing call and never trusts a client
     * claim about somebody else's state.
     */
    public function busy(Request $request, Call $call): JsonResponse
    {
        $me = (int) $request->user()->id;
        $this->assertParticipant($call, $me);

        $call->refresh();
        if (! $call->isRinging()) {
            return response()->json(['call' => CallService::payload($call)]);
        }

        // The participant check and the ringing-state check above are the
        // server-side validation. No client whisper can change call state.
        $call = CallService::finish($call, 'busy', 'busy', $me);
        CallService::broadcast($call, 'call:busy', ['reason' => 'busy']);

        return response()->json(['call' => CallService::payload($call)]);
    }

    public function cancel(Request $request, Call $call): JsonResponse
    {
        $me = (int) $request->user()->id;
        $this->assertParticipant($call, $me);
        abort_unless((int) $call->caller_id === $me, 403);

        $call->refresh();
        if (! $call->isRinging()) {
            return response()->json(['call' => CallService::payload($call)]);
        }

        $call = CallService::finish($call, 'missed', 'cancelled', $me);
        CallService::broadcast($call, 'call:cancel', ['reason' => 'cancelled']);

        return response()->json(['call' => CallService::payload($call)]);
    }

    /** Either participant may report that the 30s ring timer elapsed. */
    public function timeout(Request $request, Call $call): JsonResponse
    {
        $me = (int) $request->user()->id;
        $this->assertParticipant($call, $me);

        $call->refresh();
        if (! $call->isRinging()) {
            return response()->json(['call' => CallService::payload($call)]);
        }

        $call = CallService::finish($call, 'missed', 'timeout', $me);
        CallService::broadcast($call, 'call:end', ['reason' => 'timeout', 'message' => 'No answer.']);

        return response()->json(['call' => CallService::payload($call)]);
    }

    public function end(Request $request, Call $call): JsonResponse
    {
        $me = (int) $request->user()->id;
        $this->assertParticipant($call, $me);

        $reason = (string) $request->input('reason', 'hangup');
        $reason = in_array($reason, ['hangup', 'disconnect', 'network'], true) ? $reason : 'hangup';

        $call->refresh();

        if ($call->isRinging()) {
            if ((int) $call->caller_id === $me) {
                $call = CallService::finish($call, 'missed', 'cancelled', $me);
                CallService::broadcast($call, 'call:cancel', ['reason' => 'cancelled']);
            } else {
                $call = CallService::finish($call, 'declined', 'declined', $me);
                CallService::broadcast($call, 'call:reject', ['reason' => 'declined']);
            }

            return response()->json(['call' => CallService::payload($call)]);
        }

        if (! $call->isActive()) {
            return response()->json(['call' => CallService::payload($call)]);
        }

        $call = CallService::finish($call, 'ended', $reason, $me);
        CallService::broadcast($call, 'call:end', [
            'reason' => $reason,
            'message' => 'Call ended.',
        ]);

        return response()->json(['call' => CallService::payload($call)]);
    }

    /** Relay SDP / ICE only between the two participants of an active call. */
    public function signal(Request $request, Call $call): JsonResponse
    {
        $me = (int) $request->user()->id;
        $this->assertParticipant($call, $me);

        $data = $request->validate([
            'type' => ['required', 'string', 'in:offer,answer,ice-candidate'],
            'payload' => ['required', 'array'],
        ]);

        $call->refresh();
        abort_unless($call->isActive(), 409, 'This call is not active.');

        $payload = $data['payload'];
        if (in_array($data['type'], ['offer', 'answer'], true)) {
            // SDP is carried as base64 so CRLF line endings survive the
            // broadcast layer untouched.
            abort_unless(
                isset($payload['type'], $payload['sdp'])
                    && $payload['type'] === $data['type']
                    && is_string($payload['sdp'])
                    && strlen($payload['sdp']) <= 45000
                    && preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $payload['sdp']) === 1,
                422,
                'Invalid session description.',
            );
        } else {
            abort_unless(
                ! isset($payload['candidate']) || (is_string($payload['candidate']) && strlen($payload['candidate']) <= 4000),
                422,
                'Invalid ICE candidate.',
            );
        }

        $peerId = (int) $call->caller_id === $me ? (int) $call->callee_id : (int) $call->caller_id;
        $peerTab = (int) $call->caller_id === $me ? $call->callee_tab_id : $call->caller_tab_id;

        CallService::broadcast($call, 'call:'.$data['type'], [
            'signal' => $payload,
            'to_tab_id' => $peerTab,
            'from' => $me,
        ], $peerId);

        return response()->json(['ok' => true]);
    }

    private function assertParticipant(Call $call, int $userId): void
    {
        abort_unless($call->isParticipant($userId), 403, 'You are not part of this call.');
    }

    private function resolveConversation(User $me, User $target, ?int $conversationId): ?Conversation
    {
        $isFriend = $me->isFriendsWith((int) $target->id);

        if ($conversationId) {
            $conversation = Conversation::find($conversationId);
            $valid = $conversation
                && $conversation->type === 'direct'
                && $conversation->isParticipant((int) $me->id)
                && $conversation->isParticipant((int) $target->id)
                && ! $conversation->isPendingRequest();

            if ($valid) {
                return $conversation;
            }

            abort_unless($isFriend, 403, 'You can only call accepted friends.');
        }

        $conversation = Conversation::where('direct_key', Conversation::directKey((int) $me->id, (int) $target->id))->first();
        if ($conversation && ! $conversation->isPendingRequest()) {
            return $conversation;
        }

        // A new thread is only created when the call actually needs history.
        return null;
    }

    private function terminalCall(User $caller, User $callee, ?Conversation $conversation, string $status, string $reason): Call
    {
        return Call::create([
            'caller_id' => $caller->id,
            'callee_id' => $callee->id,
            'conversation_id' => $conversation?->id,
            'status' => $status,
            'end_reason' => $reason,
            'ended_by' => $caller->id,
            'ended_at' => now(),
        ]);
    }
}
