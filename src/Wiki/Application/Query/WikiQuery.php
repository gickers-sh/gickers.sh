<?php

declare(strict_types=1);

namespace App\Wiki\Application\Query;

use App\Wiki\Domain\Model\WikiPage;
use App\Wiki\Domain\WikiSlug;
use App\Wiki\Domain\WikiStore;

final readonly class WikiQuery
{
    public function __construct(
        private WikiStore $wikiStore,
    ) {
    }

    /**
     * @return list<WikiPage>
     */
    public function all(?string $category = null): array
    {
        $pages = $this->wikiStore->all();
        if (null === $category || '' === trim($category)) {
            return $pages;
        }

        $needle = strtolower(trim($category));

        return array_values(array_filter(
            $pages,
            static fn (WikiPage $p): bool => strtolower($p->category()) === $needle,
        ));
    }

    public function find(WikiSlug|string $slug): ?WikiPage
    {
        $slugObj = $slug instanceof WikiSlug ? $slug : WikiSlug::fromInput($slug);

        return $this->wikiStore->bySlug($slugObj);
    }

    /**
     * Groups all wiki pages by category while preserving order.
     *
     * @return array<string, list<WikiPage>>
     */
    public function groupedByCategory(): array
    {
        $groups = [];
        foreach ($this->wikiStore->all() as $page) {
            $groups[$page->category()][] = $page;
        }

        return $groups;
    }

    /**
     * Searches wiki pages across title, slug, category, and content, returning
     * matched pages with a brief context snippet.
     *
     * @return list<array{page: WikiPage, snippet: string}>
     */
    public function search(string $query): array
    {
        $q = trim($query);
        if ('' === $q) {
            return [];
        }

        $lowerQ = mb_strtolower($q);
        $results = [];

        foreach ($this->wikiStore->all() as $page) {
            $inTitle = str_contains(mb_strtolower($page->title()), $lowerQ);
            $inSlug = str_contains(mb_strtolower($page->slugString()), $lowerQ);
            $inCat = str_contains(mb_strtolower($page->category()), $lowerQ);
            $inContent = str_contains(mb_strtolower($page->content()), $lowerQ);

            if (!$inTitle && !$inSlug && !$inCat && !$inContent) {
                continue;
            }

            $results[] = [
                'page' => $page,
                'snippet' => self::extractSnippet($page->content(), $lowerQ),
            ];
        }

        return $results;
    }

    private static function extractSnippet(string $content, string $lowerQuery): string
    {
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $trimmed = trim($line);
            if ('' !== $trimmed && str_contains(mb_strtolower($trimmed), $lowerQuery)) {
                return mb_strlen($trimmed) > 120 ? mb_substr($trimmed, 0, 117).'...' : $trimmed;
            }
        }

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $trimmed = trim(ltrim($line, '#*>- '));
            if ('' !== $trimmed) {
                return mb_strlen($trimmed) > 120 ? mb_substr($trimmed, 0, 117).'...' : $trimmed;
            }
        }

        return '';
    }
}
