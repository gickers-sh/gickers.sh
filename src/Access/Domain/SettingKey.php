<?php

declare(strict_types=1);

namespace App\Access\Domain;

enum SettingKey: string
{
    case AnonAccess = 'anon_access';
    case AllowKeyless = 'allow_keyless';
    case AllowSignup = 'allow_signup';
    case DeployPolicy = 'deploy_policy';
}
