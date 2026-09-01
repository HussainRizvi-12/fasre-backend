<?php

use Illuminate\Support\Facades\Route;

// SPA admin portal (React build in public/admin).
// On Azure, Nginx serves these static files directly via try_files;
// the routes below are fallbacks for other server environments.
Route::get('/', fn () => redirect('/admin'));

// Fallback for unauthenticated non-JSON requests: Laravel's auth middleware
// redirects to the named "login" route. The SPA handles the login UI.
Route::get('/login', fn () => redirect('/admin'))->name('login');

Route::get('/admin', fn () => response()->file(public_path('admin/index.html')));
Route::get('/admin/{any}', fn () => response()->file(public_path('admin/index.html')))->where('any', '.*');
