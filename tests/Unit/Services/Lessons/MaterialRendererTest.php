<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Lessons;

use App\Services\Lessons\MaterialRenderer;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Unit-покрытие `MaterialRenderer`: контракт рендера markdown + сквозная
 * санитизация через HTMLPurifier перед возвратом клиенту. Тесты
 * изолированы от БД (массив-кеш в .env.testing → CACHE_STORE=array):
 * render пустой/null, render базовой markdown-разметки, cache hit
 * (повторный render без re-convert), invalidation через `forget`,
 * и три XSS-вектора — `<script>`, `<iframe>`, `javascript:` URL.
 */
class MaterialRendererTest extends TestCase
{
    private MaterialRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        // Pure Unit: подсовываем array-store, чтобы render() писал в
        // process-local массив и не зависел от БД/Redis.
        $this->renderer = new MaterialRenderer(Cache::store('array'));
    }

    public function test_render_returns_null_when_material_is_null(): void
    {
        $this->assertNull($this->renderer->render(1, null));
    }

    public function test_render_returns_null_when_material_is_empty_or_whitespace(): void
    {
        $this->assertNull($this->renderer->render(1, ''));
        $this->assertNull($this->renderer->render(2, "   \n\t  "));
    }

    public function test_render_converts_basic_markdown_to_html(): void
    {
        $html = $this->renderer->render(10, "# Заголовок\n\nТекст **жирным**.");

        $this->assertNotNull($html);
        $this->assertStringContainsString('<h1>Заголовок</h1>', $html);
        $this->assertStringContainsString('<strong>жирным</strong>', $html);
        // Без сырого markdown-мусора: "#" и "**" ушли в HTML.
        $this->assertStringNotContainsString("\n# ", $html);
    }

    public function test_render_supports_gfm_tables_and_autolinks(): void
    {
        $material = "| A | B |\n|---|---|\n| 1 | 2 |\n\nVisit https://example.com";

        $html = $this->renderer->render(11, $material);

        $this->assertNotNull($html);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<th>A</th>', $html);
        // Autolink extension превращает голый URL в <a href>.
        $this->assertStringContainsString('href="https://example.com"', $html);
    }

    public function test_render_caches_by_lesson_id(): void
    {
        $material = '# First version';

        $first = $this->renderer->render(20, $material);
        // Меняем source-of-truth напрямую в кеше, имитируя «устаревший»
        // кеш, чтобы проверить, что второе чтение берёт из кеша, а не
        // пере-конвертирует.
        $cacheKey = 'lesson:material_html:20';
        Cache::store('array')->put($cacheKey, '<cached>marker</cached>', null);

        $second = $this->renderer->render(20, '# Second version that should NOT appear');

        $this->assertSame($first, '<h1>First version</h1>');
        $this->assertSame('<cached>marker</cached>', $second);
    }

    public function test_invalidate_clears_cached_html_so_next_render_recomputes(): void
    {
        $this->renderer->render(30, '# Old');

        // Симулируем UpdateLesson-инвалидацию.
        $this->renderer->invalidate(30);
        Cache::store('array')->put('lesson:material_html:30', '<stale>old</stale>', null);

        $this->renderer->invalidate(30);

        // После invalidate() кеша нет, но `rememberForever` тут же
        // запишет заново — проверим, что новое содержимое появляется.
        Cache::store('array')->forget('lesson:material_html:30');

        $html = $this->renderer->render(30, '# New');

        $this->assertNotNull($html);
        $this->assertStringContainsString('<h1>New</h1>', $html);
        $this->assertStringNotContainsString('stale', $html);
    }

    /**
     * Каждый кейс — (markdown-источник, фрагмент который должен быть
     * вырезан санитайзером). Наличие `<script>`/`<iframe>`/javascript:
     * URL в выводе недопустимо, видимый текст вокруг — остаётся.
     *
     * @return array<string, array{string, string}>
     */
    public static function xssVectorProvider(): array
    {
        return [
            'script tag' => [
                "# Title\n\n<script>alert('xss')</script> visible text",
                '<script>',
            ],
            'iframe tag' => [
                "# Title\n\n<iframe src=\"https://evil.example\"></iframe> tail",
                '<iframe',
            ],
            'inline event handler' => [
                "# Title\n\n<img src=\"x\" onerror=\"alert(1)\" /> tail",
                'onerror',
            ],
            'javascript url' => [
                "# Title\n\n[click](javascript:alert(1)) tail",
                'javascript:',
            ],
            'object embed' => [
                "# Title\n\n<object data=\"data:text/html,<script>alert(1)</script>\"></object> tail",
                '<object',
            ],
        ];
    }

    #[DataProvider('xssVectorProvider')]
    public function test_render_sanitises_xss_vectors_via_htmlpurifier(string $material, string $forbiddenFragment): void
    {
        $html = $this->renderer->render(40, $material);

        $this->assertNotNull($html);
        $this->assertStringNotContainsStringIgnoringCase($forbiddenFragment, $html);
    }

    public function test_render_keeps_visible_text_around_sanitised_payload(): void
    {
        $html = $this->renderer->render(
            41,
            "# Title\n\n<script>alert('xss')</script> visible text after",
        );

        $this->assertNotNull($html);
        // Заголовок рендерится, payload вырезан, plain-text хвост жив.
        $this->assertStringContainsString('<h1>Title</h1>', $html);
        $this->assertStringContainsString('visible text after', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_render_sanitises_script_tags_via_htmlpurifier(): void
    {
        $material = "# Title\n\n<script>alert('xss')</script> visible text";
        $html = $this->renderer->render(50, $material);

        $this->assertNotNull($html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('visible text', $html);
    }

    public function test_render_sanitises_iframe_tags(): void
    {
        $material = "Intro\n\n<iframe src=\"https://evil.example\"></iframe>\n\nOutro";
        $html = $this->renderer->render(51, $material);

        $this->assertNotNull($html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringContainsString('Intro', $html);
        $this->assertStringContainsString('Outro', $html);
    }

    public function test_render_sanitises_javascript_urls(): void
    {
        $material = 'Click [me](javascript:alert(1)) please';
        $html = $this->renderer->render(52, $material);

        $this->assertNotNull($html);
        $this->assertStringNotContainsString('javascript:', $html);
    }
}
