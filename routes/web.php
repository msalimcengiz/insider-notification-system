<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'docs' => url('/docs/api'),
    'health' => url('/api/v1/health'),
]));
