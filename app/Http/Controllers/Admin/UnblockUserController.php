<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\UnblockUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UnblockUserController extends Controller
{
    /**
     * Single-action controller: POST /admin/users/{user}/unblock.
     * Mirrors `BlockUserController`; the actual state flip is performed
     * by `App\Actions\Admin\UnblockUser` (T02).
     */
    public function __invoke(User $user, Request $request): RedirectResponse
    {
        $this->authorize('unblock', $user);
        app(UnblockUser::class)->execute($user, $request->user());

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', 'Пользователь разблокирован');
    }
}
