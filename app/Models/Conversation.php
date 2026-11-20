<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = ['type', 'group_id', 'direct_key'];

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot('last_read_at')
            ->withTimestamps();
    }

    public function participantRecords(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function messageRequest(): HasOne
    {
        return $this->hasOne(MessageRequest::class);
    }

    public function isPendingRequest(): bool
    {
        return $this->messageRequest()->where('status', 'pending')->exists();
    }

    public static function directKey(int $a, int $b): string
    {
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
