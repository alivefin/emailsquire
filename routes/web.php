<?php

use Illuminate\Support\Facades\Route;

// Everything else is the SPA, except the API, the health route and built or module assets (public/build,
// public/modules/<name>): a missing asset answers 404, never the HTML shell.
Route::view('/{path?}', 'app')->where('path', '^(?!api/|up$|build/|modules/).*$');
