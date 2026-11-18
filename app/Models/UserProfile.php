<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $fillable = [
        'user_id',
        'username',
        'avatar_path',
        'status',
        'bio',
        'show_active_status',
        'message_sounds',
    ];

    protected $casts = [
        'show_active_status' => 'boolean',
        'message_sounds' => 'boolean',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
