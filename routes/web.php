<?php

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessengerController;
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

    // Groups — every endpoint is membership/admin authorized.
    Route::get('/api/groups/{group}', [GroupController::class, 'show']);
    Route::post('/api/groups', [GroupController::class, 'store']);
    Route::put('/api/groups/{group}', [GroupController::class, 'update']);
    Route::post('/api/groups/{group}/members', [GroupController::class, 'addMember']);
    Route::delete('/api/groups/{group}/members/{user}', [GroupController::class, 'removeMember']);
    Route::post('/api/groups/{group}/leave', [GroupController::class, 'leave']);
    Route::delete('/api/groups/{group}', [GroupController::class, 'destroy']);
});
