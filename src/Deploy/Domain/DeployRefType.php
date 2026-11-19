<?php

declare(strict_types=1);

namespace App\Deploy\Domain;

enum DeployRefType: string
{
    case Branch = 'branch';
    case Tag = 'tag';
}
