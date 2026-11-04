<?php

declare(strict_types=1);

namespace App\PullRequest\Domain;

enum PullRequestStatus: string
{
    case Open = 'open';
    case Merged = 'merged';
    case Closed = 'closed';
}
