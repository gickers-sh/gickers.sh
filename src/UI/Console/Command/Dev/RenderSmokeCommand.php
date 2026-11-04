<?php

declare(strict_types=1);

namespace App\UI\Console\Command\Dev;

use App\UI\Tui\Render\TermstrapRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Renders a Markdown and a CSV document through the Termstrap FFI bridge.
 */
#[When('dev')]
#[AsCommand(
    name: 'dev:render-smoke',
    description: 'Render sample Markdown and CSV through Termstrap (FFI bridge smoke test)',
)]
final class RenderSmokeCommand extends Command
{
    public function __construct(private readonly TermstrapRenderer $renderer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $markdown = <<<'MD'
            # codes.sh

            Rendered by **Termstrap** through *ext-ffi*.

            - [x] GitHub Flavored Markdown
            - [x] TokyoNight colours
            - [x] Links: [codes.sh](https://codes.sh)
            MD;

        $output->writeln($this->renderer->renderDocument('README.md', $markdown, 70));
        $output->writeln($this->renderer->renderDocument('team.csv', "Name,Role,Status\nAlice,Admin,Active\nBob,Dev,Pending\n", 70));

        return Command::SUCCESS;
    }
}
