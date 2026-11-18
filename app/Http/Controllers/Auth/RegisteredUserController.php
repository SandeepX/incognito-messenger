<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        // Wrapped in a transaction: a user without a profile would break
        // the messenger UI (username/avatar), so create both or neither.
        $user = \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            $user = User::create($request->only('name', 'email', 'password'));

            UserProfile::create([
                'user_id' => $user->id,
                'username' => $request->input('username'),
            ]);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/')->with('status', 'Welcome to Incognito Messenger!');
    }
}
