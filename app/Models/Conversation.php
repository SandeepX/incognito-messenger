<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = ['type', 'group_id', 'direct_key'];

    public function participants(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    public function participantRecords(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function group(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function messageRequest(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(MessageRequest::class);
    }

    /** True while this thread is still an unaccepted message request. */
    public function isPendingRequest(): bool
    {
        return $this->messageRequest()->where('status', 'pending')->exists();
    }

    public static function directKey(int $a, int $b): string
    {
        // Sorted so Alice→Bob and Bob→Alice resolve to the same thread.
        $ids = [$a, $b];
        sort($ids);

        return implode('-', $ids);
    }

    public function otherParticipant(int $userId): ?User
    {
        return $this->participants()->whereKeyNot($userId)->first();
    }

    public function isParticipant(int $userId): bool
    {
        return $this->participants()->whereKey($userId)->exists();
    }
}
