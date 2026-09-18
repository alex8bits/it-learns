<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Enums\AdminAuditAction;
use App\Models\AdminAuditLog;
use App\Models\AiPromptVersion;
use App\Models\Course;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\PromptKeys;
use App\Services\Ai\PromptVersionService;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\TestCase;

class PromptVersionServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        // The rollback test registers a throwing `creating` hook on the
        // audit log; drop it so it does not leak into other tests.
        AdminAuditLog::flushEventListeners();

        parent::tearDown();
    }

    public function test_first_version_for_a_key_is_number_one_authored_by_the_author(): void
    {
        $author = User::factory()->create();

        $version = $this->service()->createNewVersion(
            PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'First body',
            'initial import',
            $author,
        );

        $this->assertSame(1, $version->version_number);
        $this->assertSame(PromptKeys::GLOBAL_SYSTEM_PROMPT, $version->prompt_key);
        $this->assertSame('First body', $version->body);
        $this->assertEquals($author->id, $version->created_by);
        $this->assertDatabaseHas('ai_prompt_versions', [
            'id' => $version->id,
            'prompt_key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'version_number' => 1,
            'created_by' => $author->id,
        ]);
    }

    public function test_repeated_calls_increment_version_numbers(): void
    {
        $author = User::factory()->create();

        $first = $this->service()->createNewVersion(PromptKeys::GLOBAL_SYSTEM_PROMPT, 'Body 1', null, $author);
        $second = $this->service()->createNewVersion(PromptKeys::GLOBAL_SYSTEM_PROMPT, 'Body 2', null, $author);
        $third = $this->service()->createNewVersion(PromptKeys::GLOBAL_SYSTEM_PROMPT, 'Body 3', null, $author);

        $this->assertSame(1, $first->version_number);
        $this->assertSame(2, $second->version_number);
        $this->assertSame(3, $third->version_number);
    }

    public function test_version_numbers_are_per_key(): void
    {
        $author = User::factory()->create();

        $global = $this->service()->createNewVersion(PromptKeys::GLOBAL_SYSTEM_PROMPT, 'Global', null, $author);
        $course = $this->service()->createNewVersion(PromptKeys::forCourse(7), 'Course', null, $author);

        $this->assertSame(1, $global->version_number);
        $this->assertSame(1, $course->version_number);
    }

    public function test_global_key_creates_settings_cache_row(): void
    {
        $author = User::factory()->create();

        $this->service()->createNewVersion(
            PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'Cached body',
            'first edit',
            $author,
        );

        $this->assertDatabaseHas('settings', [
            'key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'value' => 'Cached body',
            'updated_by' => $author->id,
        ]);
    }

    public function test_global_key_updates_existing_settings_cache_row(): void
    {
        $previous = User::factory()->create();
        Setting::factory()->create([
            'key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'value' => 'Old cached body',
            'updated_by' => $previous->id,
        ]);
        $author = User::factory()->create();

        $this->service()->createNewVersion(
            PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'New cached body',
            'second edit',
            $author,
        );

        $this->assertDatabaseCount('settings', 1);
        $this->assertDatabaseHas('settings', [
            'key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'value' => 'New cached body',
            'updated_by' => $author->id,
        ]);
    }

    public function test_course_key_creates_version_without_touching_settings(): void
    {
        $author = User::factory()->create();
        $courseKey = PromptKeys::forCourse(7);

        $version = $this->service()->createNewVersion($courseKey, 'Course body', 'course edit', $author);

        $this->assertSame($courseKey, $version->prompt_key);
        $this->assertSame(1, $version->version_number);
        $this->assertDatabaseCount('settings', 0);
    }

    public function test_course_key_syncs_the_course_prompt_cache_column(): void
    {
        $author = User::factory()->create();
        $course = Course::factory()->create();
        $courseKey = PromptKeys::forCourse($course->id);

        $version = $this->service()->createNewVersion($courseKey, 'Уточняющий промпт курса', 'course edit', $author);

        $this->assertSame(1, $version->version_number);
        $this->assertSame('Уточняющий промпт курса', $course->refresh()->ai_course_prompt);
        $this->assertDatabaseHas('ai_prompt_versions', [
            'prompt_key' => $courseKey,
            'version_number' => 1,
        ]);
        $this->assertDatabaseCount('settings', 0);
    }

    public function test_course_key_overwrites_the_previous_cached_course_prompt(): void
    {
        $author = User::factory()->create();
        $course = Course::factory()->withPrompt()->create();
        $courseKey = PromptKeys::forCourse($course->id);

        $this->service()->createNewVersion($courseKey, 'Новый уточняющий промпт', 'reworded', $author);

        $this->assertSame('Новый уточняющий промпт', $course->refresh()->ai_course_prompt);
    }

    public function test_course_key_for_a_missing_course_writes_history_as_a_silent_noop(): void
    {
        $author = User::factory()->create();
        $courseKey = PromptKeys::forCourse(999999);

        $version = $this->service()->createNewVersion($courseKey, 'Orphaned body', 'history only', $author);

        $this->assertSame($courseKey, $version->prompt_key);
        $this->assertSame(1, $version->version_number);
        $this->assertDatabaseHas('ai_prompt_versions', [
            'prompt_key' => $courseKey,
            'version_number' => 1,
        ]);
        $this->assertDatabaseCount('settings', 0);
    }

    public function test_each_change_writes_exactly_one_audit_log_entry(): void
    {
        $author = User::factory()->create();
        $this->actingAs($author);

        $this->service()->createNewVersion(PromptKeys::GLOBAL_SYSTEM_PROMPT, 'Body 1', null, $author);
        $this->service()->createNewVersion(PromptKeys::GLOBAL_SYSTEM_PROMPT, 'Body 2', null, $author);

        $this->assertDatabaseCount('admin_audit_logs', 2);
    }

    public function test_audit_log_records_subject_and_exact_meta(): void
    {
        $author = User::factory()->create();
        $this->actingAs($author);

        $version = $this->service()->createNewVersion(
            PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'Audited body',
            'tweak tone',
            $author,
        );

        $log = AdminAuditLog::query()->where('subject_id', $version->id)->firstOrFail();

        $this->assertSame(AdminAuditAction::PromptVersionCreated, $log->action);
        $this->assertSame($author->id, $log->admin_id);
        $this->assertSame((new AiPromptVersion)->getMorphClass(), $log->subject_type);
        $this->assertSame([
            'prompt_key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'version' => 1,
            'comment' => 'tweak tone',
        ], $log->meta);
    }

    public function test_course_key_audit_log_records_subject_and_exact_meta(): void
    {
        $author = User::factory()->create();
        $this->actingAs($author);
        $course = Course::factory()->create();
        $courseKey = PromptKeys::forCourse($course->id);

        $version = $this->service()->createNewVersion(
            $courseKey,
            'Course audited body',
            'course tweak',
            $author,
        );

        $log = AdminAuditLog::query()->where('subject_id', $version->id)->firstOrFail();

        $this->assertSame(AdminAuditAction::PromptVersionCreated, $log->action);
        $this->assertSame($author->id, $log->admin_id);
        $this->assertSame((new AiPromptVersion)->getMorphClass(), $log->subject_type);
        $this->assertSame([
            'prompt_key' => $courseKey,
            'version' => 1,
            'comment' => 'course tweak',
        ], $log->meta);
    }

    public function test_audit_log_meta_keeps_null_comment_as_null(): void
    {
        $author = User::factory()->create();

        $version = $this->service()->createNewVersion(PromptKeys::GLOBAL_SYSTEM_PROMPT, 'Body', null, $author);

        $log = AdminAuditLog::query()->where('subject_id', $version->id)->firstOrFail();

        $this->assertSame([
            'prompt_key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'version' => 1,
            'comment' => null,
        ], $log->meta);
    }

    public function test_rollback_reissues_old_body_as_a_new_version(): void
    {
        $author = User::factory()->create();
        $service = $this->service();

        $v1 = $service->createNewVersion(PromptKeys::GLOBAL_SYSTEM_PROMPT, 'First body', 'v1', $author);
        $v2 = $service->createNewVersion(PromptKeys::GLOBAL_SYSTEM_PROMPT, 'Second body', 'v2', $author);

        $rolledBack = $service->rollbackTo($v1, $author);

        $this->assertSame('First body', $rolledBack->body);
        $this->assertSame(3, $rolledBack->version_number);
        $this->assertSame('Rollback to v1', $rolledBack->comment);
        $this->assertEquals($author->id, $rolledBack->created_by);
        $this->assertSame('First body', Setting::findByKey(PromptKeys::GLOBAL_SYSTEM_PROMPT)?->value);
    }

    public function test_rollback_never_mutates_existing_version_rows(): void
    {
        $author = User::factory()->create();
        $service = $this->service();

        $v1 = $service->createNewVersion(PromptKeys::GLOBAL_SYSTEM_PROMPT, 'First body', 'v1', $author);
        $v2 = $service->createNewVersion(PromptKeys::GLOBAL_SYSTEM_PROMPT, 'Second body', 'v2', $author);

        $service->rollbackTo($v1, $author);

        $this->assertSame('First body', $v1->fresh()->body);
        $this->assertSame(1, $v1->fresh()->version_number);
        $this->assertSame('Second body', $v2->fresh()->body);
        $this->assertSame(2, $v2->fresh()->version_number);
        $this->assertSame(3, AiPromptVersion::query()->count());
    }

    public function test_rollback_of_course_version_leaves_settings_untouched(): void
    {
        $author = User::factory()->create();
        $courseKey = PromptKeys::forCourse(9);
        $service = $this->service();

        $v1 = $service->createNewVersion($courseKey, 'Course body v1', null, $author);
        $service->createNewVersion($courseKey, 'Course body v2', null, $author);

        $rolledBack = $service->rollbackTo($v1, $author);

        $this->assertSame('Course body v1', $rolledBack->body);
        $this->assertSame('Rollback to v1', $rolledBack->comment);
        $this->assertDatabaseCount('settings', 0);
    }

    public function test_rollback_of_course_version_restores_the_course_prompt_cache(): void
    {
        $author = User::factory()->create();
        $course = Course::factory()->create();
        $courseKey = PromptKeys::forCourse($course->id);
        $service = $this->service();

        $v1 = $service->createNewVersion($courseKey, 'Course body v1', 'v1', $author);
        $service->createNewVersion($courseKey, 'Course body v2', 'v2', $author);
        $this->assertSame('Course body v2', $course->refresh()->ai_course_prompt);

        $rolledBack = $service->rollbackTo($v1, $author);

        $this->assertSame(3, $rolledBack->version_number);
        $this->assertSame('Rollback to v1', $rolledBack->comment);
        $this->assertSame('Course body v1', $course->refresh()->ai_course_prompt);
    }

    public function test_transaction_rolls_back_version_and_settings_when_audit_fails(): void
    {
        $author = User::factory()->create();
        $this->actingAs($author);
        Setting::factory()->create([
            'key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'value' => 'Original cached body',
            'updated_by' => null,
        ]);

        AdminAuditLog::creating(static function (): void {
            throw new RuntimeException('boom');
        });

        try {
            $this->service()->createNewVersion(
                PromptKeys::GLOBAL_SYSTEM_PROMPT,
                'Doomed body',
                'never lands',
                $author,
            );
            $this->fail('Expected RuntimeException to bubble out of the service.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertDatabaseCount('ai_prompt_versions', 0);
        $this->assertDatabaseCount('admin_audit_logs', 0);
        $this->assertDatabaseHas('settings', [
            'key' => PromptKeys::GLOBAL_SYSTEM_PROMPT,
            'value' => 'Original cached body',
            'updated_by' => null,
        ]);
    }

    public function test_get_history_returns_versions_newest_first_with_authors_eager_loaded(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $courseKey = PromptKeys::forCourse(3);

        $this->service()->createNewVersion(PromptKeys::GLOBAL_SYSTEM_PROMPT, 'Body 1', null, $first);
        $this->service()->createNewVersion(PromptKeys::GLOBAL_SYSTEM_PROMPT, 'Body 2', null, $second);
        $this->service()->createNewVersion($courseKey, 'Other key', null, $first);

        $history = $this->service()->getHistory(PromptKeys::GLOBAL_SYSTEM_PROMPT);

        $this->assertInstanceOf(Collection::class, $history);
        $this->assertSame([2, 1], $history->pluck('version_number')->all());

        // preventLazyLoading is active in the testing environment, so
        // touching `author` would throw unless getHistory eager-loaded it.
        $history->each(function (AiPromptVersion $version): void {
            $this->assertTrue($version->relationLoaded('author'));
        });
        $this->assertTrue($history[0]->author->is($second));
        $this->assertTrue($history[1]->author->is($first));
    }

    public function test_get_history_applies_the_limit_to_the_newest_versions(): void
    {
        $author = User::factory()->create();
        $service = $this->service();
        $service->createNewVersion(PromptKeys::GLOBAL_SYSTEM_PROMPT, 'Body 1', null, $author);
        $service->createNewVersion(PromptKeys::GLOBAL_SYSTEM_PROMPT, 'Body 2', null, $author);
        $service->createNewVersion(PromptKeys::GLOBAL_SYSTEM_PROMPT, 'Body 3', null, $author);

        $history = $service->getHistory(PromptKeys::GLOBAL_SYSTEM_PROMPT, 2);

        $this->assertSame([3, 2], $history->pluck('version_number')->all());
    }

    public function test_get_history_returns_empty_collection_for_unknown_key(): void
    {
        $author = User::factory()->create();
        $this->service()->createNewVersion(PromptKeys::GLOBAL_SYSTEM_PROMPT, 'Body', null, $author);

        $history = $this->service()->getHistory('ai.unknown_prompt');

        $this->assertCount(0, $history);
    }

    private function service(): PromptVersionService
    {
        return app(PromptVersionService::class);
    }
}
