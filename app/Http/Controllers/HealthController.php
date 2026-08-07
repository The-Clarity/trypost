<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            Redis::connection()->ping();
        } catch (Throwable) {
            return response()->json(['status' => 'unavailable'], 503);
        }

        return response()->json(['status' => 'ok']);
    }
}
