<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Metrics\MetricsReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MetricsController extends Controller
{
    /**
     * Real-time metrics.
     *
     * Per channel, over a sliding window (`?window=` minutes, 1-60, default 5):
     * sent / failed counts, success and attempt failure rates, throughput,
     * provider and end-to-end latency percentiles, queue depth per priority
     * (pending, delayed, reserved, age of the oldest pending job) and the
     * circuit breaker state. Plus the number of notifications in each
     * non-final status.
     */
    public function __invoke(Request $request, MetricsReport $report): JsonResponse
    {
        $validated = $request->validate([
            'window' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ]);

        return response()->json($report->build((int) ($validated['window'] ?? 5)));
    }
}
