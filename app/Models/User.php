<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Search users by an email substring (admin users index).
     *
     * LIKE wildcards (`%`, `_`) and the escape character `\` inside the
     * needle are escaped via `addcslashes($email, '\%_')` so they are
     * matched literally instead of acting as wildcards. The `ESCAPE`
     * clause names the backslash as the LIKE escape character; in the SQL
     * text it is written as `ESCAPE '\\'` because MySQL/MariaDB string
     * literals treat `\` as an escape themselves (NO_BACKSLASH_ESCAPES is
     * not enabled). The escaping works identically with native and
     * emulated prepared statements (config/database.php does not disable
     * PDO::ATTR_EMULATE_PREPARES); both modes are covered by
     * UserSearchByEmailScopeTest, including the backslash needle case.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeSearchByEmail(Builder $query, ?string $email = null): Builder
    {
        if ($email === null || $email === '') {
            return $query;
        }

        $needle = addcslashes($email, '\\%_');

        return $query->whereRaw("email LIKE ? ESCAPE '\\\\'", ["%{$needle}%"]);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
