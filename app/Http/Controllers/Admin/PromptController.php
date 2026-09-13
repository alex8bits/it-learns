<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stage 2 placeholder. AI prompt management lands in Stage 4.
 *
 * Mirrors the policy-stub pattern of `PaymentController` and
 * `CourseController`: no `authorize()` call because `PromptPolicy`
 * returns `false` for every method (T03), and `role:admin` already
 * scopes the route.
 */
class PromptController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Prompts/Index', [
            'stage' => 4,
        ]);
    }
}
