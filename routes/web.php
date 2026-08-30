<?php

use Illuminate\Support\Facades\Route;

// Redirect root to the central Filament v3 Admin Portal
Route::get('/', function () {
    return redirect('/admin');
});
