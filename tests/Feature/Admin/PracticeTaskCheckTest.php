<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PracticeTaskCheckTest extends TestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate(UserRole::User->value, 'web');
        Role::findOrCreate(UserRole::Admin->value, 'web');

        // Изоляция файлов практики в уникальном temp-каталоге
        // (паттерн SubmitPracticeTaskTest) — никогда в реальный
        // storage/framework/practice.
        $this->storagePath = sys_get_temp_dir().'/practice-check-'.Str::uuid()->toString();
        config(['practice.storage_path' => $this->storagePath]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);

        parent::tearDown();
    }

    public function test_admin_checks_a_matching_reference_query(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('admin.practice-tasks.check'), [
            'code' => 'SELECT id, title, year FROM books',
            'seed_sql' => <<<'SQL'
                CREATE TABLE books (id INTEGER PRIMARY KEY, title TEXT, year INTEGER);
                INSERT INTO books (title, year) VALUES ('SQL Basics', 2020);
                INSERT INTO books (title, year) VALUES ('Advanced SQL', 2021);
                SQL,
            'expected_rows' => '[{"id": 1, "title": "SQL Basics", "year": 2020}, {"id": 2, "title": "Advanced SQL", "year": 2021}]',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'passed')
            ->assertJsonPath('matched', true)
            ->assertJsonCount(2, 'result.rows')
            ->assertJsonPath('result.columns', ['id', 'title', 'year']);
    }

    public function test_mismatching_reference_query_reports_failure(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('admin.practice-tasks.check'), [
            'code' => 'SELECT id, title, year FROM books',
            'seed_sql' => 'CREATE TABLE books (id INTEGER PRIMARY KEY, title TEXT, year INTEGER); INSERT INTO books (title, year) VALUES (\'SQL Basics\', 2020), (\'Advanced SQL\', 2021);',
            'expected_rows' => '[{"id": 1, "title": "SQL Basics", "year": 2020}]',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('matched', false)
            ->assertJsonCount(2, 'result.rows');
    }

    public function test_check_without_expected_rows_runs_rows_only(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('admin.practice-tasks.check'), [
            'code' => 'SELECT id, title, year FROM books',
            'seed_sql' => 'CREATE TABLE books (id INTEGER PRIMARY KEY, title TEXT, year INTEGER); INSERT INTO books (title, year) VALUES (\'SQL Basics\', 2020);',
        ]);

        // No reference result supplied: the harness reports Failed
        // informationally, `matched` marks the "rows only" mode.
        $response->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('matched', null)
            ->assertJsonCount(1, 'result.rows');
    }

    public function test_regular_user_is_forbidden(): void
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::User->value);

        $this->actingAs($user)
            ->postJson(route('admin.practice-tasks.check'), ['code' => 'SELECT 1'])
            ->assertForbidden();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
