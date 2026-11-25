<?php

declare(strict_types=1);

namespace App\Wiki\Application;

use App\Access\Application\AccessGuard;
use App\Identity\Domain\Model\User;
use App\Shared\Domain\Exception\NotFound;
use App\Wiki\Domain\WikiSlug;
use App\Wiki\Domain\WikiStore;

final readonly class DeleteWikiPage
{
    public function __construct(
        private WikiStore $wikiStore,
        private AccessGuard $accessGuard,
    ) {
    }

    public function __invoke(?User $actor, WikiSlug|string $slug): void
    {
        $this->accessGuard->assertServerAdmin($actor);

        $slugObj = $slug instanceof WikiSlug ? $slug : WikiSlug::fromInput($slug);
        $page = $this->wikiStore->bySlug($slugObj);
        if (null === $page) {
            throw new NotFound('wiki page not found');
        }

        $this->wikiStore->remove($page);
    }
}
