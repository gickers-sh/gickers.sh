<?php

declare(strict_types=1);

namespace App\Deploy\Domain;

enum DeployStatus: string
{
    case Idle = 'idle';
    case Queued = 'queued';
    case Building = 'building';
    case Running = 'running';
    case Failed = 'failed';
    case Stopped = 'stopped';
}
