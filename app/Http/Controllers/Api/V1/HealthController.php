<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Health\HealthCheck;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Observability', weight: 4)]
final class HealthController extends Controller
{
    /**
     * Health check.
     *
     * `ok` / `degraded` answer 200 (the API is usable; `degraded` means
     * delivery is impaired: no workers or an open circuit). `down` answers
     * 503 (PostgreSQL or Redis unreachable).
     */
    public function __invoke(HealthCheck $health): JsonResponse
    {
        $result = $health->run();

        return response()
            ->json([...$result, 'checked_at' => now()->toIso8601ZuluString()], $result['status'] === 'down' ? 503 : 200)
            ->header('Cache-Control', 'no-store');
    }
}
