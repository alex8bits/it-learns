<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stage 2 placeholder. Real payment management lands in Stage 3.
 *
 * Intentionally does NOT call `$this->authorize(...)`: the only related
 * Policy would be `CoursePolicy` (no `Payment` model exists yet), and
 * it is a stub returning `false` for every method (T03). The `role:admin`
 * middleware on the surrounding route group is the single source of truth
 * for access to this stub.
 */
class PaymentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Payments/Index', [
            'stage' => 3,
        ]);
    }

    /**
     * The `{payment}` parameter is plain `int` — Route Model Binding is
     * not used because the `Payment` model does not exist in Stage 2.
     */
    public function show(int $payment): Response
    {
        return Inertia::render('Admin/Payments/Show', [
            'stage' => 3,
            'paymentId' => $payment,
        ]);
    }
}
