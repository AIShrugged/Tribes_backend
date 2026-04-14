<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

// test: webhook auto-deploy check
abstract class Controller extends \Illuminate\Routing\Controller
{
    use AuthorizesRequests;
}
