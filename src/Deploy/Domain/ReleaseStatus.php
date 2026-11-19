<?php

declare(strict_types=1);

namespace App\Deploy\Domain;

enum ReleaseStatus: string
{
    case Queued = 'queued';
    case Building = 'building';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
