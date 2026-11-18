<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class MessengerController extends Controller
{
    public function index(): View
    {
        return view('messenger', [
            'me' => auth()->user()->loadMissing('profile'),
        ]);
    }
}
