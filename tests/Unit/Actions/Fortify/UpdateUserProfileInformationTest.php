<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Fortify;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Tests\TestCase;

class UpdateUserProfileInformationTest extends TestCase
{
    public function test_it_updates_name_and_email(): void
    {
        $user = User::factory()->create();

        app(UpdatesUserProfileInformation::class)->update($user, [
            'name' => 'Updated Name',
            'email' => 'updated@example.test',
        ]);

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('Updated Name', $fresh->name);
        $this->assertSame('updated@example.test', $fresh->email);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Name',
            'email' => 'updated@example.test',
        ]);
    }

    public function test_it_throws_validation_exception_on_empty_name(): void
    {
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);

        app(UpdatesUserProfileInformation::class)->update($user, [
            'name' => '',
            'email' => 'updated@example.test',
        ]);
    }

    public function test_it_throws_validation_exception_on_invalid_email(): void
    {
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);

        app(UpdatesUserProfileInformation::class)->update($user, [
            'name' => 'Updated Name',
            'email' => 'not-an-email',
        ]);
    }

    public function test_it_throws_validation_exception_on_email_taken_by_another_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create([
            'email' => 'taken@example.test',
        ]);

        $this->expectException(ValidationException::class);

        app(UpdatesUserProfileInformation::class)->update($user, [
            'name' => 'Updated Name',
            'email' => $other->email,
        ]);
    }

    public function test_it_allows_keeping_own_current_email(): void
    {
        $user = User::factory()->create([
            'email' => 'keep@example.test',
        ]);

        app(UpdatesUserProfileInformation::class)->update($user, [
            'name' => 'Renamed User',
            'email' => 'keep@example.test',
        ]);

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('Renamed User', $fresh->name);
        $this->assertSame('keep@example.test', $fresh->email);
    }
}
