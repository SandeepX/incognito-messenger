<?php

use App\Http\Controllers\MessageController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('messenger'));

// Instant "log in as" for testing: Alice in one browser, Bob in another
// (or an incognito window) to see the two sides of a conversation.
Route::get('/login/{name}', function (string $name) {
    $user = \App\Models\User::where('name', ucfirst($name))->firstOrFail();
    Auth::login($user);

    return redirect('/')->with('status', "Logged in as {$user->name}");
});

Route::post('/logout', function () {
    Auth::logout();

    return redirect('/');
})->middleware('auth')->name('logout');

// Conversation history (HTTP backfill) + sending (which broadcasts)
Route::get('/messages/{user}', [MessageController::class, 'index'])
    ->middleware('auth');
Route::post('/messages', [MessageController::class, 'store'])
    ->middleware('auth')->middleware('throttle:120,1');
