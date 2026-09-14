<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ChangeUserRole;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionTier;
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
     * label without an N+1 round trip per row. The email search escapes
     * LIKE wildcards so user input is matched literally (see
     * `User::scopeSearchByEmail`).
     */
    public function index(AdminUserIndexRequest $request): Response
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->validated();

        $users = User::query()
            ->with('roles')
            ->searchByEmail(isset($filters['email']) ? (string) $filters['email'] : null)
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

    /**
     * User card: profile fields plus the Spatie `roles` relation and the
     * Stage 3 payment history — `subscriptions` and `payments` are
     * eager-loaded (N+1 is forbidden by the shared `loadMissing` call).
     * The enum option lists are passed alongside so the Vue page can
     * render Russian labels without duplicating the enum values in JS
     * constants.
     */
    public function show(User $user): Response
    {
        $this->authorize('view', $user);
        $user->loadMissing(['roles', 'subscriptions', 'payments']);

        return Inertia::render('Admin/Users/Show', [
            'user' => $user,
            'paymentStatuses' => PaymentStatus::options(),
            'subscriptionStatuses' => SubscriptionStatus::options(),
            'tiers' => SubscriptionTier::options(),
        ]);
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
