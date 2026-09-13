<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ChangeUserRole;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminUpdateUserRequest;
use App\Http\Requests\Admin\AdminUserIndexRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * Paginated user list with optional email / role filters. Eager-loads
     * the Spatie `roles` relation so the Vue table can render the role
     * label without an N+1 round trip per row.
     */
    public function index(AdminUserIndexRequest $request): Response
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->validated();

        $users = User::query()
            ->with('roles')
            ->when(
                $filters['email'] ?? null,
                fn ($query, $needle) => $query->where('email', 'like', "%{$needle}%"),
            )
            ->when(
                $filters['role'] ?? null,
                fn ($query, $role) => $query->role($role),
            )
            ->orderBy('id', 'desc')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => $filters,
        ]);
    }

    public function show(User $user): Response
    {
        $this->authorize('view', $user);
        $user->load('roles');

        return Inertia::render('Admin/Users/Show', ['user' => $user]);
    }

    public function edit(User $user): Response
    {
        $this->authorize('update', $user);
        $user->load('roles');

        return Inertia::render('Admin/Users/Edit', ['user' => $user]);
    }

    /**
     * Change the target user's Spatie role via `ChangeUserRole` Action.
     * The Action runs inside its own `DB::transaction` and writes the
     * audit-log row atomically with the role swap (see T02).
     */
    public function update(AdminUpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('changeRole', $user);

        $newRole = UserRole::from((string) $request->validated('role'));
        app(ChangeUserRole::class)->execute($user, $newRole, $request->user());

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', "Роль пользователя изменена на {$newRole->label()}");
    }
}
