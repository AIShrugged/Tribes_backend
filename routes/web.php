<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dbg', function () {
    return [
        'secure' => request()->secure(),
        'scheme' => request()->getScheme(),
        'x_forwarded_proto' => request()->header('x-forwarded-proto'),
        'host' => request()->getHost(),
        'remote_addr' => request()->server('REMOTE_ADDR'),
        'client_ip' => request()->getClientIp(),
        'ips' => request()->ips(),
    ];
});