<?php

declare(strict_types=1);

namespace App\Wiki\Application;

use App\Access\Application\AccessGuard;
use App\Identity\Domain\Model\User;
use App\Shared\Domain\Exception\InvalidInput;
use App\Wiki\Domain\Model\WikiPage;
use App\Wiki\Domain\WikiSlug;
use App\Wiki\Domain\WikiStore;
use Psr\Clock\ClockInterface;

final readonly class SaveWikiPage
{
    public function __construct(
        private WikiStore $wikiStore,
        private AccessGuard $accessGuard,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        ?User $actor,
        WikiSlug|string $slug,
        ?string $title,
        string $content,
        ?string $category = null,
        ?int $position = null,
    ): WikiPage {
        $this->accessGuard->assertServerAdmin($actor);

        $slugObj = $slug instanceof WikiSlug ? $slug : WikiSlug::fromInput($slug);
        $existing = $this->wikiStore->bySlug($slugObj);

        $resolvedTitle = null !== $title && '' !== trim($title)
            ? trim($title)
            : ($existing?->title() ?? self::extractTitleOrDefault($content, $slugObj->value));

        if (mb_strlen($resolvedTitle) > 255) {
            throw new InvalidInput('wiki page title cannot exceed 255 characters');
        }

        $resolvedCategory = null !== $category && '' !== trim($category)
            ? trim($category)
            : ($existing?->category() ?? 'General');

        if (mb_strlen($resolvedCategory) > 128) {
            throw new InvalidInput('wiki category cannot exceed 128 characters');
        }

        $resolvedPosition = $position ?? ($existing?->position() ?? 0);
        $now = $this->clock->now();

        if (null !== $existing) {
            $existing->update(
                title: $resolvedTitle,
                content: $content,
                category: $resolvedCategory,
                position: $resolvedPosition,
                author: $actor,
                now: $now,
            );
            $this->wikiStore->save($existing);

            return $existing;
        }

        $page = new WikiPage(
            slug: $slugObj,
            title: $resolvedTitle,
            content: $content,
            category: $resolvedCategory,
            position: $resolvedPosition,
            author: $actor,
            createdAt: $now,
        );
        $this->wikiStore->save($page);

        return $page;
    }

    private static function extractTitleOrDefault(string $content, string $slug): string
    {
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $trimmed = trim($line);
            if (1 === preg_match('/^#\s+(.+)$/', $trimmed, $m)) {
                return trim($m[1]);
            }
        }

        $base = basename($slug);

        return ucwords(str_replace(['-', '_', '.'], ' ', $base));
    }
}
