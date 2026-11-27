<?php

declare(strict_types=1);

namespace App\UI\Console\Command\Server;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'server:update',
    description: 'Update and upgrade the gickers.sh server installation and Docker stack',
    aliases: ['host:update', 'server:upgrade', 'host:upgrade'],
)]
final class ServerUpdateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('pull-only', null, InputOption::VALUE_NONE, 'Only pull latest Git commits and Docker images without building or restarting')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Display the update operations without executing them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('⚡ Gickers.sh Server Update & Migration');

        $dryRun = (bool) $input->getOption('dry-run');
        $pullOnly = (bool) $input->getOption('pull-only');

        if ($dryRun) {
            $io->note('Mode DRY-RUN : les commandes suivantes seraient exécutées :');
            $io->listing([
                'git fetch --all --prune && git pull --ff-only',
                'docker compose pull --ignore-pull-failures',
                $pullOnly ? '(Reconstruction et redémarrage ignorés)' : 'docker compose build --pull && docker compose up -d --remove-orphans',
                'docker compose exec -T app bin/console doctrine:migrations:migrate --no-interaction',
                'sudo systemctl restart gickers.service (si actif)',
            ]);

            return Command::SUCCESS;
        }

        $steps = [
            'Git Pull' => ['git', 'pull', '--ff-only'],
            'Docker Pull' => ['docker', 'compose', 'pull', '--ignore-pull-failures'],
        ];

        if (!$pullOnly) {
            $steps['Docker Build'] = ['docker', 'compose', 'build'];
            $steps['Docker Up'] = ['docker', 'compose', 'up', '-d', '--remove-orphans'];
            $steps['Database Migrations'] = ['docker', 'compose', 'exec', '-T', 'app', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'];
        }

        foreach ($steps as $title => $cmd) {
            $io->section($title);
            $process = new Process($cmd);
            $process->setTimeout(300);
            $process->run(function ($type, $buffer) use ($output): void {
                $output->write($buffer);
            });

            if (!$process->isSuccessful() && 'Database Migrations' !== $title && 'Docker Pull' !== $title) {
                $io->warning(sprintf('L\'étape "%s" a retourné le code %d.', $title, (int) $process->getExitCode()));
            }
        }

        $io->success('Mise à jour du serveur terminée.');

        return Command::SUCCESS;
    }
}
