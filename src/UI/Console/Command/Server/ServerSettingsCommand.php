<?php

declare(strict_types=1);

namespace App\UI\Console\Command\Server;

use App\Access\Application\ServerPolicy;
use App\Access\Domain\AccessLevel;
use App\Access\Domain\DeployPolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'server:settings',
    description: 'Manage or inspect server settings and global policies',
    aliases: ['host:settings', 'settings'],
)]
final class ServerSettingsCommand extends Command
{
    public function __construct(
        private readonly ServerPolicy $serverPolicy,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('setting', InputArgument::OPTIONAL, 'Setting key (allow-signup|allow-keyless|anon-access|deploy-policy)')
            ->addArgument('value', InputArgument::OPTIONAL, 'Setting value');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $setting = $input->getArgument('setting');
        $value = $input->getArgument('value');

        if (!is_string($setting) || '' === trim($setting)) {
            $io->title('⚙️  Server Settings & Policies');
            $table = new Table($output);
            $table->setHeaders(['Setting', 'Current Value', 'Allowed Values']);
            $table->setRows([
                ['allow-signup', $this->serverPolicy->allowSignup() ? 'true' : 'false', 'true, false'],
                ['allow-keyless', $this->serverPolicy->allowKeyless() ? 'true' : 'false', 'true, false'],
                ['anon-access', $this->serverPolicy->anonAccess()->toString(), 'no-access, read-only, admin-access'],
                ['deploy-policy', $this->serverPolicy->deployPolicy()->value, 'admins-only, authorized, repo-admins'],
            ]);
            $table->render();

            return Command::SUCCESS;
        }

        $setting = strtolower(trim($setting));

        if (!is_string($value) || '' === trim($value)) {
            $currentVal = match ($setting) {
                'allow-signup' => $this->serverPolicy->allowSignup() ? 'true' : 'false',
                'allow-keyless' => $this->serverPolicy->allowKeyless() ? 'true' : 'false',
                'anon-access' => $this->serverPolicy->anonAccess()->toString(),
                'deploy-policy' => $this->serverPolicy->deployPolicy()->value,
                default => null,
            };

            if (null === $currentVal) {
                $io->error(sprintf('Unknown setting "%s".', $setting));

                return Command::FAILURE;
            }

            $output->writeln($currentVal);

            return Command::SUCCESS;
        }

        $valStr = trim($value);

        switch ($setting) {
            case 'allow-signup':
                $bool = filter_var($valStr, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if (null === $bool) {
                    $io->error('Invalid boolean value. Must be true or false.');

                    return Command::FAILURE;
                }
                $this->serverPolicy->setAllowSignup($bool);
                $io->success(sprintf('allow-signup updated to "%s"', $bool ? 'true' : 'false'));
                break;

            case 'allow-keyless':
                $bool = filter_var($valStr, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if (null === $bool) {
                    $io->error('Invalid boolean value. Must be true or false.');

                    return Command::FAILURE;
                }
                $this->serverPolicy->setAllowKeyless($bool);
                $io->success(sprintf('allow-keyless updated to "%s"', $bool ? 'true' : 'false'));
                break;

            case 'anon-access':
                $level = AccessLevel::tryFromString($valStr);
                if (null === $level) {
                    $io->error(sprintf('Invalid access level "%s". Allowed: no-access, read-only, admin-access.', $valStr));

                    return Command::FAILURE;
                }
                $this->serverPolicy->setAnonAccess($level);
                $io->success(sprintf('anon-access updated to "%s"', $level->toString()));
                break;

            case 'deploy-policy':
                $policy = DeployPolicy::tryFrom($valStr);
                if (null === $policy) {
                    $io->error(sprintf('Invalid deploy policy "%s". Allowed: admins-only, authorized, repo-admins.', $valStr));

                    return Command::FAILURE;
                }
                $this->serverPolicy->setDeployPolicy($policy);
                $io->success(sprintf('deploy-policy updated to "%s"', $policy->value));
                break;

            default:
                $io->error(sprintf('Unknown setting "%s".', $setting));

                return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
