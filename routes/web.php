<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health.ready');

require __DIR__.'/webhook.php';
require __DIR__.'/auth.php';
require __DIR__.'/app.php';
