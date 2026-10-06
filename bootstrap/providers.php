<?php

use App\Providers\AppServiceProvider;
use App\Providers\TaxiScannerServiceProvider;
use Illuminate\View\ViewServiceProvider;

return [
    AppServiceProvider::class,
    TaxiScannerServiceProvider::class,
    // Explicitly register View so ResponseFactory (response()->json) works
    // even if framework provider discovery is interrupted on serverless.
    ViewServiceProvider::class,
];
