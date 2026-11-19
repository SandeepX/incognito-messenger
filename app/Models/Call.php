<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Call extends Model
{
    protected $fillable = [
        'caller_id',
        'callee_id',
        'conversation_id',
        'status',
        'end_reason',
        'ended_by',
        'ringing_at',
        'answered_at',
        'ended_at',
        'duration_seconds',
        'caller_tab_id',
        'callee_tab_id',
        'ringing_tabs',
    ];

    protected $casts = [
        'ringing_at' => 'datetime',
        'answered_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_seconds' => 'integer',
        'ringing_tabs' => 'array',
    ];

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    public function callee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'callee_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    public function message(): HasOne
    {
        return $this->hasOne(Message::class);
    }

    public function isParticipant(int $userId): bool
    {
        return $userId === (int) $this->caller_id || $userId === (int) $this->callee_id;
    }

    public function otherParticipant(int $userId): ?User
    {
        if ($userId === (int) $this->caller_id) {
            return $this->callee;
        }

        if ($userId === (int) $this->callee_id) {
            return $this->caller;
        }

        return null;
    }

    public function isRinging(): bool
    {
        return $this->status === 'ringing';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
