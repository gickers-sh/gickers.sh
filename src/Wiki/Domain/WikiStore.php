<?php

declare(strict_types=1);

namespace App\Wiki\Domain;

use App\Wiki\Domain\Model\WikiPage;

interface WikiStore
{
    public function byId(int $id): ?WikiPage;

    public function bySlug(WikiSlug $slug): ?WikiPage;

    /**
     * All wiki pages ordered by position ASC, category ASC, title ASC.
     *
     * @return list<WikiPage>
     */
    public function all(): array;

    public function save(WikiPage $page): void;

    public function remove(WikiPage $page): void;
}
