<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return $request->expectsJson()
                ? response()->json(['ok' => true, 'message' => 'Email already verified.'])
                : redirect()->route('profile.edit')->with('status', 'Email already verified.');
        }

        $request->user()->sendEmailVerificationNotification();

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'message' => 'Verification link sent.'])
            : back()->with('status', 'Verification link sent.');
    }
}
