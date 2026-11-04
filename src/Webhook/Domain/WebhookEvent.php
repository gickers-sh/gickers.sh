<?php

declare(strict_types=1);

namespace App\Webhook\Domain;

enum WebhookEvent: string
{
    case BranchTagCreate = 'branch_tag_create';
    case BranchTagDelete = 'branch_tag_delete';
    case Collaborator = 'collaborator';
    case Push = 'push';
    case Repository = 'repository';
    case RepositoryVisibilityChange = 'repository_visibility_change';
}
