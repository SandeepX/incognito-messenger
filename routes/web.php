<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\FriendController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageRequestController;
use App\Http\Controllers\MessengerController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserSearchController;
use Illuminate\Support\Facades\Route;

// ---------------- Public: authentication ----------------
Route::get('/register', [RegisteredUserController::class, 'create'])->middleware('guest')->name('register');
Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('guest');

Route::get('/login', [SessionController::class, 'create'])->middleware('guest')->name('login');
Route::post('/login', [SessionController::class, 'store'])->middleware(['guest', 'throttle:6,1']);
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');

// Avatars are public images, but the filename pattern prevents traversal.
Route::get('/avatars/{filename}', [AvatarController::class, 'show'])
    ->where('filename', '[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|gif|webp)')
    ->name('avatars.show');

// ---------------- Authenticated: the messenger ----------------
Route::middleware('auth')->group(function () {
    Route::get('/', [MessengerController::class, 'index'])->name('home');

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar');

    // User discovery + 1:1 threads
    Route::get('/api/users', [UserSearchController::class, 'index']);
    Route::post('/api/conversations/direct/{user}', [ConversationController::class, 'storeDirect']);
    Route::get('/api/conversations', [ConversationController::class, 'index']);
    Route::get('/api/conversations/{conversation}', [ConversationController::class, 'show']);

    // Messages — authorization happens inside the controller + policy.
    Route::post('/api/conversations/{conversation}/messages', [MessageController::class, 'store'])
        ->middleware('throttle:120,1');
    Route::post('/api/messages/{message}/unsend', [MessageController::class, 'unsend'])
        ->middleware('throttle:60,1');

    // Presence — connect/disconnect plus a lightweight 30s heartbeat.
    Route::get('/api/presence', [PresenceController::class, 'index']);
    Route::post('/api/presence/heartbeat', [PresenceController::class, 'heartbeat'])
        ->middleware('throttle:120,1');
    Route::post('/api/presence/disconnect', [PresenceController::class, 'disconnect']);

    // Groups — every endpoint is membership/admin authorized.
    Route::get('/api/groups/{group}', [GroupController::class, 'show']);
    Route::post('/api/groups', [GroupController::class, 'store']);
    Route::put('/api/groups/{group}', [GroupController::class, 'update']);
    Route::post('/api/groups/{group}/members', [GroupController::class, 'addMember']);
    Route::delete('/api/groups/{group}/members/{user}', [GroupController::class, 'removeMember']);
    Route::post('/api/groups/{group}/leave', [GroupController::class, 'leave']);
    Route::delete('/api/groups/{group}', [GroupController::class, 'destroy']);

    // Friends — every action is policy/ownership checked in the controller.
    Route::get('/api/friends', [FriendController::class, 'index']);
    Route::post('/api/friends', [FriendController::class, 'store']);
    Route::post('/api/friends/{friend_request}/accept', [FriendController::class, 'accept']);
    Route::post('/api/friends/{friend_request}/reject', [FriendController::class, 'reject']);
    Route::delete('/api/friends/user/{user}', [FriendController::class, 'destroy']);
    Route::delete('/api/friends/{friend_request}', [FriendController::class, 'cancel']);

    // Message requests — the only way a non-friend may initiate a thread.
    Route::get('/api/message-requests', [MessageRequestController::class, 'index']);
    Route::post('/api/message-requests', [MessageRequestController::class, 'store']);
    Route::get('/api/message-requests/{message_request}', [MessageRequestController::class, 'show']);
    Route::post('/api/message-requests/{message_request}/accept', [MessageRequestController::class, 'accept']);
    Route::post('/api/message-requests/{message_request}/delete', [MessageRequestController::class, 'delete']);
    Route::delete('/api/message-requests/{message_request}', [MessageRequestController::class, 'cancel']);

    // Notes — visible only to accepted friends (or their author).
    Route::get('/api/notes', [NoteController::class, 'index']);
    Route::post('/api/notes', [NoteController::class, 'store']);
    Route::get('/api/notes/{note}', [NoteController::class, 'show']);
    Route::put('/api/notes/{note}', [NoteController::class, 'update']);
    Route::delete('/api/notes/{note}', [NoteController::class, 'destroy']);

    // Notifications — owner-only, persisted + pushed over user.{id}.
    Route::get('/api/notifications', [NotificationController::class, 'index']);
    Route::post('/api/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/api/notifications/{notification}/read', [NotificationController::class, 'markRead']);

    // Attachments — authorize before returning a short-lived signed URL.
    Route::get('/api/attachments/{attachment}/download', [AttachmentController::class, 'download'])->name('attachments.download');
    Route::get('/api/attachments/{attachment}/preview', [AttachmentController::class, 'preview'])->name('attachments.preview');
});
