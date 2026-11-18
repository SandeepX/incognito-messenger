<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    // Laravel 13's skeleton no longer includes these; the messenger uses
    // $this->authorize() and $request->validate() throughout.
    use AuthorizesRequests, ValidatesRequests;
}
