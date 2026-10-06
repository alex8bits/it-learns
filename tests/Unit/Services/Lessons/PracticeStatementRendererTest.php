<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Lessons;

use App\Services\Lessons\MarkdownHtmlRenderer;
use App\Services\Lessons\PracticeStatementRenderer;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Unit-покрытие `PracticeStatementRenderer`: рендер markdown-формулировок
 * практических заданий в санитизированный HTML для v-html — зеркало
 * MaterialRendererTest. Тесты изолированы от БД (array-кеш): null на
 * пустой statement, реальный формат формулировок (списки + инлайн-код),
 * cache hit по task id, защита от cache poisoning пустой строкой,
 * invalidation и XSS-вектор `<script>`.
 */
class PracticeStatementRendererTest extends TestCase
{
    private PracticeStatementRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        // Pure Unit: array-store, чтобы render() писал в process-local
        // массив; конвертация — через реальный MarkdownHtmlRenderer
        // (сквозной контракт до санитизированного HTML, без моков).
        $this->renderer = new PracticeStatementRenderer(Cache::store('array'), new MarkdownHtmlRenderer);
    }

    public function test_render_returns_null_when_statement_is_null_or_blank(): void
    {
        $this->assertNull($this->renderer->render(1, null));
        $this->assertNull($this->renderer->render(2, ''));
        $this->assertNull($this->renderer->render(3, "  \n\t "));
    }

    public function test_render_formats_the_real_statement_shape_lists_and_inline_code(): void
    {
        $statement = 'Создайте таблицу `suppliers` — столбцы строго в этом порядке:'."\n\n"
            .'- `id` — INT, первичный ключ с автонумерацией;'."\n"
            .'- `name` — VARCHAR(100), обязательный.'."\n\n"
            .'Выведите все столбцы и все строки таблицы.';

        $html = $this->renderer->render(10, $statement);

        $this->assertNotNull($html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li><code>id</code> — INT, первичный ключ с автонумерацией;</li>', $html);
        $this->assertStringContainsString('<li><code>name</code> — VARCHAR(100), обязательный.</li>', $html);
        $this->assertStringContainsString('Выведите все столбцы', $html);
        // Markdown-синтаксис ушёл в HTML: сырых маркеров списка нет.
        $this->assertStringNotContainsString("\n- ", $html);
    }

    public function test_render_sanitises_raw_html_before_returning(): void
    {
        $html = $this->renderer->render(11, 'Формулировка <script>alert(1)</script> с кодом `x`.');

        $this->assertNotNull($html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<code>x</code>', $html);
    }

    public function test_render_caches_by_task_id(): void
    {
        $first = $this->renderer->render(20, 'Первая версия');
        // Меняем source-of-truth напрямую в кеше, имитируя «устаревший»
        // кеш: второе чтение должно взять из кеша, а не пере-конвертировать.
        Cache::store('array')->put('practice_task:statement_html:20', '<cached>marker</cached>', null);

        $second = $this->renderer->render(20, 'Вторая версия, которой не должно быть');

        $this->assertNotNull($first);
        $this->assertStringContainsString('<p>Первая версия</p>', $first);
        $this->assertSame('<cached>marker</cached>', $second);
    }

    public function test_render_does_not_cache_empty_html(): void
    {
        // Cache-poisoning guard: пустой рендер не должен попадать в кеш.
        $this->assertNull($this->renderer->render(60, ''));
        $this->assertNull(Cache::store('array')->get('practice_task:statement_html:60'));

        $html = $this->renderer->render(60, 'Настоящая формулировка');

        $this->assertNotNull($html);
        $this->assertSame($html, Cache::store('array')->get('practice_task:statement_html:60'));
    }

    public function test_invalidate_clears_cached_html_so_next_render_recomputes(): void
    {
        $this->renderer->render(30, 'Старая формулировка');
        $this->assertNotNull(Cache::store('array')->get('practice_task:statement_html:30'));

        // Симулируем UpdatePracticeTask-инвалидацию.
        $this->renderer->invalidate(30);

        $this->assertNull(Cache::store('array')->get('practice_task:statement_html:30'));

        $html = $this->renderer->render(30, 'Новая формулировка');
        $this->assertStringContainsString('Новая формулировка', (string) $html);
    }
}
