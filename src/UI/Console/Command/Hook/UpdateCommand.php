<?php

declare(strict_types=1);

namespace App\UI\Console\Command\Hook;

use App\Repo\Infrastructure\Git\CustomHookRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soft:hook:update',
    description: 'Execute Git update hook',
    aliases: ['git:hook:update', 'hook:update'],
)]
final class UpdateCommand extends Command
{
    public function __construct(
        private readonly CustomHookRunner $customHookRunner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('ref-name', InputArgument::REQUIRED, 'Target reference')
            ->addArgument('old-rev', InputArgument::REQUIRED, 'Old commit hash')
            ->addArgument('new-rev', InputArgument::REQUIRED, 'New commit hash');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $refArg = $input->getArgument('ref-name');
        $oldArg = $input->getArgument('old-rev');
        $newArg = $input->getArgument('new-rev');

        $refName = is_string($refArg) ? $refArg : '';
        $oldRev = is_string($oldArg) ? $oldArg : '';
        $newRev = is_string($newArg) ? $newArg : '';

        // Note: update hook does not record pushes or emit webhooks anymore; RecordPush is executed once in post-receive.
        $exitCode = $this->customHookRunner->run(
            hookName: 'update',
            stdin: '',
            args: [$refName, $oldRev, $newRev],
            env: [],
            onOutput: static function (string $type, string $buffer) use ($output): void {
                $output->write($buffer);
            },
        );

        return 0 !== $exitCode ? $exitCode : Command::SUCCESS;
    }
}
