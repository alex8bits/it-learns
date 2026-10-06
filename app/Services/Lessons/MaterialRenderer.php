<?php

declare(strict_types=1);

namespace App\Services\Lessons;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Renders lesson material markdown to HTML on the server.
 *
 * Lesson `material` is the canonical markdown source authored in
 * docs/courses/mysql/lessons/*.md and stored verbatim in
 * `lessons.material`. We do not store the rendered HTML next to the
 * source (no denormalisation); instead the conversion happens here
 * (through the shared sanitising MarkdownHtmlRenderer) and the result
 * is cached by lesson id under a forever cache, invalidated by
 * UpdateLesson on any material/title change. The sanitisation gate
 * before `v-html` on the client is documented in
 * {@see MarkdownHtmlRenderer}.
 */
class MaterialRenderer
{
    private const CACHE_KEY_PREFIX = 'lesson:material_html:';

    public function __construct(
        private CacheRepository $cache,
        private MarkdownHtmlRenderer $markdown,
    ) {}

    /**
     * Render lesson material to HTML. Returns null when material is
     * null/empty (the lesson has no material); otherwise an HTML
     * fragment with no wrapping <html>/<body>, sanitised through
     * HTMLPurifier so it is safe to embed with `v-html` on the
     * client.
     *
     * The cache is read-then-write explicitly (instead of `rememberForever`)
     * so that a transient empty render cannot poison the cache with an
     * empty string — once a non-empty HTML is cached for a lesson id, it
     * is reused forever (until `invalidate()` runs after an admin update).
     * If a previous run somehow cached `""` for this id, we treat it as
     * a miss and re-render.
     */
    public function render(int $lessonId, ?string $material): ?string
    {
        if ($material === null || trim($material) === '') {
            return null;
        }

        $cacheKey = self::CACHE_KEY_PREFIX.$lessonId;

        $cached = $this->cache->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $rendered = $this->markdown->render($material);

        if (trim($rendered) === '') {
            // Defense-in-depth: sanitizer stripped the whole markdown
            // (rare; e.g. an XSS-only source). Don't cache the empty
            // fragment — next call will re-render and may produce
            // something different if material changes upstream.
            return null;
        }

        $this->cache->forever($cacheKey, $rendered);

        return $rendered;
    }

    /**
     * Drop the cached HTML for a lesson. Called by UpdateLesson on
     * any change of `material` so the next read re-renders.
     */
    public function invalidate(int $lessonId): void
    {
        $this->cache->forget(self::CACHE_KEY_PREFIX.$lessonId);
    }
}
