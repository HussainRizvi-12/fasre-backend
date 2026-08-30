<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::get('/portal', function () {
    return view('portal');
});

Route::get('/app', function () {
    return view('portal');
});
