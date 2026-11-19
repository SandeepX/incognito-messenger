<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Group extends Model
{
    protected $fillable = ['name', 'avatar_path', 'description', 'creator_id'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function memberRecords(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    public function isMember(int $userId): bool
    {
        return $this->members()->whereKey($userId)->exists();
    }

    public function isAdmin(int $userId): bool
    {
        return $this->members()
            ->whereKey($userId)
            ->wherePivot('role', 'admin')
            ->exists();
    }
}
