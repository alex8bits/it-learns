<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Diagnostic payload for GET /api/v1/health.
 *
 * Rule #8 (AGENTS.md): any API response — even a service one — goes
 * through a Resource, never through raw arrays or json_encode.
 */
class HealthResource extends JsonResource
{
    /**
     * The payload is static; there is no underlying model, hence `null`.
     *
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => 'ok',
            'time' => now()->toIso8601String(),
        ];
    }
}
