<?php

use Illuminate\Support\Facades\Route;

// SPA admin portal (React build in public/admin).
// On Azure, Nginx serves these static files directly via try_files;
// the routes below are fallbacks for other server environments.
Route::get('/', fn () => redirect('/admin'));

Route::get('/admin', fn () => response()->file(public_path('admin/index.html')));
Route::get('/admin/{any}', fn () => response()->file(public_path('admin/index.html')))->where('any', '.*');
