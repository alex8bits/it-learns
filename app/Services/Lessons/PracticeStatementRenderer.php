<?php

declare(strict_types=1);

namespace App\Services\Lessons;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Renders a practice task statement markdown to HTML on the server —
 * the statement-side mirror of MaterialRenderer. The markdown source
 * authored in docs/courses/mysql/lessons/*.md is stored verbatim in
 * `practice_tasks.statement` (no denormalisation): the formatted HTML
 * is produced here, cached forever per task id and invalidated by
 * UpdatePracticeTask on any task change. The output is sanitised
 * (MarkdownHtmlRenderer) and safe to embed with `v-html` on the
 * client (resources/js/Pages/Lessons/Show.vue, карточки практики).
 */
class PracticeStatementRenderer
{
    private const CACHE_KEY_PREFIX = 'practice_task:statement_html:';

    public function __construct(
        private CacheRepository $cache,
        private MarkdownHtmlRenderer $markdown,
    ) {}

    /**
     * Render a task statement to HTML. Returns null when the statement
     * is null/empty; otherwise a sanitised HTML fragment.
     *
     * The cache is read-then-write explicitly (instead of
     * `rememberForever`) so that a transient empty render cannot poison
     * the cache with an empty string — the same guard as
     * MaterialRenderer::render().
     */
    public function render(int $taskId, ?string $statement): ?string
    {
        if ($statement === null || trim($statement) === '') {
            return null;
        }

        $cacheKey = self::CACHE_KEY_PREFIX.$taskId;

        $cached = $this->cache->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $rendered = $this->markdown->render($statement);

        if (trim($rendered) === '') {
            // Defense-in-depth: the sanitizer stripped the whole
            // markdown (rare). Don't cache the empty fragment — the
            // next call re-renders.
            return null;
        }

        $this->cache->forever($cacheKey, $rendered);

        return $rendered;
    }

    /**
     * Drop the cached HTML for a task. Called by UpdatePracticeTask on
     * any task change so the next read re-renders.
     */
    public function invalidate(int $taskId): void
    {
        $this->cache->forget(self::CACHE_KEY_PREFIX.$taskId);
    }
}
