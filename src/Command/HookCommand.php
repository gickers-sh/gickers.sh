<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soft:hook',
    description: 'Run git server hooks',
    aliases: ['git:hook', 'hook']
)]
final class HookCommand extends Command
{
    protected function configure(): void
    {
        $this->ignoreValidationErrors();
        $this
            ->addArgument('hook-name', InputArgument::REQUIRED, 'Name of the hook (pre-receive, update, post-receive, post-update)')
            ->addArgument('hook-args', InputArgument::IS_ARRAY, 'Additional hook arguments');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hookName = (string) $input->getArgument('hook-name');
        $app = $this->getApplication();
        if ($app === null) {
            return Command::FAILURE;
        }

        $commandName = 'soft:hook:' . $hookName;
        if (!$app->has($commandName)) {
            $output->writeln(sprintf('<error>Unknown hook: %s</error>', $hookName));
            return Command::FAILURE;
        }

        $subCommand = $app->find($commandName);

        $rawArgs = (array) $input->getArgument('hook-args');
        $params = ['command' => $commandName];

        if ($hookName === 'update') {
            if (isset($rawArgs[0])) {
                $params['ref-name'] = $rawArgs[0];
            }
            if (isset($rawArgs[1])) {
                $params['old-rev'] = $rawArgs[1];
            }
            if (isset($rawArgs[2])) {
                $params['new-rev'] = $rawArgs[2];
            }
        } elseif ($hookName === 'post-update') {
            $params['refs'] = $rawArgs;
        }

        $subInput = new ArrayInput($params);
        $subInput->setInteractive(false);

        return $subCommand->run($subInput, $output);
    }
}
