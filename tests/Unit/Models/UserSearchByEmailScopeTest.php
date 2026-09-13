<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use Tests\TestCase;

class UserSearchByEmailScopeTest extends TestCase
{
    public function test_finds_users_by_email_substring(): void
    {
        $john = User::factory()->create(['email' => 'john.doe@example.com']);
        $jane = User::factory()->create(['email' => 'jane.doe@example.com']);
        User::factory()->create(['email' => 'other@example.net']);

        $ids = User::query()->searchByEmail('doe@example.com')->pluck('id');

        $this->assertEqualsCanonicalizing([$john->id, $jane->id], $ids->all());
    }

    public function test_percent_sign_is_matched_literally(): void
    {
        // Without escaping, `%` in the needle would act as a wildcard and
        // match `aXb@example.com` too.
        $needle = User::factory()->create(['email' => 'a%b@example.com']);
        User::factory()->create(['email' => 'aXb@example.com']);

        $ids = User::query()->searchByEmail('a%b')->pluck('id');

        $this->assertSame([$needle->id], $ids->all());
    }

    public function test_underscore_is_matched_literally(): void
    {
        // Without escaping, `_` in the needle would act as "any single
        // character" and match `aXb@example.com` too.
        $needle = User::factory()->create(['email' => 'a_b@example.com']);
        User::factory()->create(['email' => 'aXb@example.com']);

        $ids = User::query()->searchByEmail('a_b')->pluck('id');

        $this->assertSame([$needle->id], $ids->all());
    }

    public function test_backslash_is_matched_literally(): void
    {
        // Without escaping, the trailing `\` would escape the closing `%`
        // delimiter of the LIKE pattern and break the match.
        $needle = User::factory()->create(['email' => 'a\b@example.com']);
        User::factory()->create(['email' => 'aXb@example.com']);

        $ids = User::query()->searchByEmail('a\b')->pluck('id');

        $this->assertSame([$needle->id], $ids->all());
    }

    public function test_null_needle_applies_no_filter(): void
    {
        User::factory()->count(2)->create();

        $this->assertSame(2, User::query()->searchByEmail(null)->count());
    }

    public function test_empty_needle_applies_no_filter(): void
    {
        User::factory()->count(2)->create();

        $this->assertSame(2, User::query()->searchByEmail('')->count());
    }
}
