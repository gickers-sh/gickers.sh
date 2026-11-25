<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Identity\Domain\Model\WebLoginTicket;

interface WebLoginTicketStore
{
    public function save(WebLoginTicket $ticket): void;

    public function byHash(string $hash): ?WebLoginTicket;
}
