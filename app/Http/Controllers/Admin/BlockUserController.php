<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\BlockUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BlockUserController extends Controller
{
    /**
     * Single-action controller: POST /admin/users/{user}/block.
     *
     * Resolved from the container to stay consistent with T02's wiring
     * of Action classes (which accept `AdminAuditLogger` via the same
     * singleton).
     */
    public function __invoke(User $user, Request $request): RedirectResponse
    {
        $this->authorize('block', $user);
        app(BlockUser::class)->execute($user, $request->user());

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', 'Пользователь заблокирован');
    }
}
