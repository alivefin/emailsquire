<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('ping', fn (Request $request) => response()->json([
    'module' => 'example',
    'admin' => (bool) $request->user()?->is_server_admin,
]));
