<?php

declare(strict_types=1);

namespace App\Services\Lessons;

use HTMLPurifier;
use HTMLPurifier_Config;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Stateless markdown → sanitised HTML converter shared by the lesson
 * page renderers (lesson material in MaterialRenderer, practice task
 * statements in PracticeStatementRenderer): the baseline CommonMark
 * plus GFM tables and autolinks.
 *
 * CommonMark leaves raw HTML from the source intact, so the fragment
 * is filtered through HTMLPurifier with its default config before it
 * is returned: `<script>`, `<iframe>`, javascript: URLs, inline event
 * handlers and similar XSS vectors are stripped even if raw HTML ever
 * gets into the markdown source — the output is safe to embed with
 * `v-html` on the client.
 */
class MarkdownHtmlRenderer
{
    public function render(string $markdown): string
    {
        $converter = new MarkdownConverter($this->buildEnvironment());

        return (string) $this->purifier()->purify((string) $converter->convert($markdown));
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
     * Build a fresh HTMLPurifier instance on every call. The
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
