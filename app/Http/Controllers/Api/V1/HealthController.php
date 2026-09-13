<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\HealthResource;

class HealthController extends Controller
{
    /**
     * Single-action controller: GET /api/v1/health.
     *
     * Stateless diagnostic endpoint — no auth, no database access.
     */
    public function __invoke(): HealthResource
    {
        return new HealthResource(null);
    }
}
