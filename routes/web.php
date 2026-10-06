<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'TaxiScanner Backend',
        'message' => 'API is running',
    ]);
});