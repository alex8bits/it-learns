<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AdminAuditAction;
use App\Models\AiPromptVersion;
use App\Models\Course;
use App\Models\Setting;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Append-only versioning of AI prompts (concept.md §9.2.4, variant A).
 *
 * The source of truth is `ai_prompt_versions`: the active version for a
 * key is `MAX(version_number)`, never a pointer column. A rollback is a
 * new version carrying an older body, so the history stays linear.
 */
class PromptVersionService
{
    public function __construct(private AdminAuditLogger $audit) {}

    /**
     * Atomically append a new version of a prompt and refresh the fast-read
     * cache, recording an audit entry in the same transaction.
     *
     * The cache target depends on the key: the global prompt refreshes the
     * `settings` row keyed by PromptKeys::GLOBAL_SYSTEM_PROMPT, course keys
     * (`course.{id}.ai_course_prompt`) refresh the `courses.ai_course_prompt`
     * column of the referenced course. A course that no longer exists makes
     * the cache update a silent no-op — the version still lands in the
     * history alone.
     *
     * The `version_number` race between two concurrent admins is bounded by
     * the unique `(prompt_key, version_number)` index: the loser fails with
     * a QueryException instead of silently rewriting history.
     */
    public function createNewVersion(string $promptKey, string $body, ?string $comment, User $author): AiPromptVersion
    {
        return DB::transaction(function () use ($promptKey, $body, $comment, $author): AiPromptVersion {
            $next = (int) (AiPromptVersion::query()->where('prompt_key', $promptKey)->max('version_number') ?? 0) + 1;

            $version = AiPromptVersion::create([
                'prompt_key' => $promptKey,
                'body' => $body,
                'version_number' => $next,
                'comment' => $comment,
                'created_by' => $author->id,
            ]);

            $this->syncCache($promptKey, $body, $author);

            $this->audit->log(AdminAuditAction::PromptVersionCreated, $version, [
                'prompt_key' => $promptKey,
                'version' => $next,
                'comment' => $comment,
            ]);

            return $version;
        });
    }

    /**
     * Roll back to an older version by re-issuing its body as a brand-new
     * version — the existing rows are never mutated (linear history).
     */
    public function rollbackTo(AiPromptVersion $version, User $author): AiPromptVersion
    {
        return $this->createNewVersion(
            $version->prompt_key,
            $version->body,
            "Rollback to v{$version->version_number}",
            $author,
        );
    }

    /**
     * Version history for a key, newest first, with authors eager-loaded
     * (rule #9: no N+1 for collections handed to a view).
     *
     * @return Collection<int, AiPromptVersion>
     */
    public function getHistory(string $promptKey, int $limit = 50): Collection
    {
        return AiPromptVersion::query()
            ->where('prompt_key', $promptKey)
            ->with('author')
            ->orderByDesc('version_number')
            ->limit($limit)
            ->get();
    }

    /**
     * Refresh the fast-read cache of the active prompt body inside the
     * caller's transaction.
     *
     * The global prompt is cached in the `settings` row keyed by
     * PromptKeys::GLOBAL_SYSTEM_PROMPT. Course keys cache into the
     * `courses.ai_course_prompt` column of the referenced course; a missing
     * course matches zero rows and is a silent no-op — the history row is
     * valuable on its own, and live controllers guarantee the course via
     * route model binding.
     */
    private function syncCache(string $promptKey, string $body, User $author): void
    {
        if ($promptKey === PromptKeys::GLOBAL_SYSTEM_PROMPT) {
            Setting::updateOrCreate(
                ['key' => $promptKey],
                ['value' => $body, 'updated_by' => $author->id],
            );

            return;
        }

        if (preg_match('/^course\.(\d+)\.ai_course_prompt$/', $promptKey, $matches) === 1) {
            Course::query()->whereKey((int) $matches[1])->update(['ai_course_prompt' => $body]);
        }
    }
}
