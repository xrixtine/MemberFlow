<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Provides $this->authorize(...) for policy checks inside controllers.
    use AuthorizesRequests;
}
