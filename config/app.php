<?php

use Illuminate\Support\ServiceProvider;

return [
    'name' => env('APP_NAME', 'Stuckbema Leverans'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => 'Europe/Stockholm',
    'locale' => 'sv',
    'fallback_locale' => 'en',
    'faker_locale' => 'sv_SE',
    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',
    'providers' => ServiceProvider::defaultProviders()->toArray(),
];
