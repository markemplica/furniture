<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

class HealthController extends Controller
{
    public function __invoke()
    {
        $checks = [
            'database' => fn () => DB::select('select 1'),
            'cache' => function () {
                $key = 'health-check:'.bin2hex(random_bytes(8));
                Cache::put($key, true, 10);
                Cache::forget($key);
            },
            'queue' => fn () => Queue::connection()->size(config('queue.connections.'.config('queue.default').'.queue', 'default')),
        ];
        $status = [];

        foreach ($checks as $name => $check) {
            try {
                $check();
                $status[$name] = true;
            } catch (Throwable) {
                $status[$name] = false;
            }
        }

        $healthy = ! in_array(false, $status, true);

        return response()->json(['status' => $healthy ? 'ok' : 'degraded', 'checks' => $status], $healthy ? 200 : 503);
    }
}
