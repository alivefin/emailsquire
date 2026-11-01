<?php

use Illuminate\Support\Facades\Route;

// Throws inside a group of its own, which Laravel's router then never leaves.
Route::prefix('inner')->group(function () {
    Route::get('first', fn () => 'registered before the throw');

    throw new RuntimeException('The throwing-routes fixture fails half way.');
});
