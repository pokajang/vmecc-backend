<?php

use App\Support\E2eEnvironmentGuard;
use App\Support\E2eRunLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/concurrency-probe', function (Request $request) {
    $runId = (string) config('e2e.run_id');
    abort_unless($runId !== '' && hash_equals($runId, (string) $request->header('X-E2E-Run-ID')), 404);

    E2eEnvironmentGuard::assertCurrentEnvironmentIsSafe();
    E2eRunLock::fromConfig()->assertOwned();

    $delayMilliseconds = max(100, min(1500, (int) $request->query('delay_ms', 1000)));
    $startedAt = microtime(true);
    usleep($delayMilliseconds * 1000);

    return response()->json([
        'run_id' => $runId,
        'owner_pid' => getmypid(),
        'started_at' => $startedAt,
        'ended_at' => microtime(true),
        'delay_ms' => $delayMilliseconds,
    ]);
})->name('e2e.concurrency-probe');
