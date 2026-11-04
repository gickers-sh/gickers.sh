<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Render\TermstrapRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'test:render',
    description: 'Verifies the Termstrap FFI bridge by rendering Markdown, CSV, and ANSI TokyoNight'
)]
final class TestRenderCommand extends Command
{
    public function __construct(private readonly TermstrapRenderer $renderer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $md = <<<'MD'
# codes.sh TUI with Symfony & Termstrap
Bienvenue sur l'interface **codes.sh** propulsée par *Symfony 7.2* et *Termstrap*.

- [x] Rendu Markdown GitHub Flavored
- [x] Coloration TokyoNight
- [x] Support des liens [codes.sh](https://codes.sh)
MD;

        $renderedMd = $this->renderer->renderDocument('README.md', $md, 70);
        $output->writeln($renderedMd);

        $csv = "Nom,Rôle,Statut\nAlice,Admin,Actif\nBob,Dev,En attente\n";
        $renderedCsv = $this->renderer->renderDocument('team.csv', $csv, 70);
        $output->writeln($renderedCsv);

        return Command::SUCCESS;
    }
}
