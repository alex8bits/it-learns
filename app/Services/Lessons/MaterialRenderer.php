<?php

declare(strict_types=1);

namespace App\Services\Lessons;

use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Renders lesson material markdown to HTML on the server.
 *
 * Lesson `material` is the canonical markdown source authored in
 * docs/mysql/*.md and stored verbatim in `lessons.material`. We do
 * not store the rendered HTML next to the source (no denormalisation);
 * instead the conversion happens here and the result is cached by
 * lesson id under a forever cache, invalidated by UpdateLesson on
 * any material/title change.
 *
 * The rendered HTML is sanitised through HTMLPurifier with its
 * default config before being returned to the caller: CommonMark
 * render leaves raw HTML from the markdown source intact (the
 * `html_input` default is `safe` only at the schema level — actual
 * filtering here is a defense-in-depth gate before `v-html` on the
 * client, see resources/js/Pages/Lessons/Show.vue «Материал»).
 * HTMLPurifier strips `<script>`, `<iframe>`, javascript: URLs,
 * inline event handlers (`onclick`, `onerror`, ...) and similar
 * XSS vectors even if a future admin preview path or content
 * import ever manages to inject raw HTML into `lessons.material`.
 * Extensions are limited to the baseline CommonMark + GFM tables +
 * autolinks.
 */
class MaterialRenderer
{
    private const CACHE_KEY_PREFIX = 'lesson:material_html:';

    public function __construct(private CacheRepository $cache) {}

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

        $converter = new MarkdownConverter($this->buildEnvironment());
        $rawHtml = (string) $converter->convert($material);
        $rendered = (string) $this->purifier()->purify($rawHtml);

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

    private function buildEnvironment(): Environment
    {
        $environment = new Environment;
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        $environment->addExtension(new AutolinkExtension);

        return $environment;
    }

    /**
     * Build a fresh HTMLPurifier instance on every cache miss. The
     * configuration is small and the constructor is cheap, and we
     * never want to share a long-lived purifier across requests
     * (HTMLPurifier keeps request-scoped state in some attributes).
     */
    private function purifier(): HTMLPurifier
    {
        $config = HTMLPurifier_Config::createDefault();

        return new HTMLPurifier($config);
    }
}
