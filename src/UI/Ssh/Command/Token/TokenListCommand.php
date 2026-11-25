<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Token;

use App\Identity\Domain\AccessTokenStore;
use App\Shared\Domain\Exception\AccessDenied;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class TokenListCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'token:list';
    }

    public static function sshAliases(): array
    {
        return ['token:ls'];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly AccessTokenStore $accessTokenStore,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this->setDescription('List access tokens');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actor = $this->actor();
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $tokens = $this->accessTokenStore->forUser($actor);
        if ([] === $tokens) {
            $this->line($output, 'No tokens found');

            return Command::SUCCESS;
        }

        $now = $this->clock->now();
        if ($this->isDecorated($output)) {
            $header = sprintf('%-5s %-20s %-18s %-20s %-25s %s', 'ID', 'Name', 'Scopes', 'Repos', 'Created At', 'Expires In');
            $this->line($output, \App\UI\Tui\Render\Theme::style($header, \App\UI\Tui\Render\Theme::BLUE, bold: true, underline: true));
        } else {
            $this->line($output, sprintf('%-5s %-20s %-18s %-20s %-25s %s', 'ID', 'Name', 'Scopes', 'Repos', 'Created At', 'Expires In'));
        }

        foreach ($tokens as $token) {
            $expiresAtStr = '-';
            $exp = $token->expiresAt();
            if (null !== $exp) {
                $expiresAtStr = $now > $exp ? 'expired' : $exp->format(\DateTimeInterface::ATOM);
            }

            if ($token->isSingleUse()) {
                if ($token->isUsed()) {
                    $expiresAtStr = 'used (1x)';
                } elseif ('-' === $expiresAtStr) {
                    $expiresAtStr = 'single-use';
                } else {
                    $expiresAtStr .= ' (1x)';
                }
            }

            $scopesStr = implode(',', $token->scopes());
            if (strlen($scopesStr) > 18) {
                $scopesStr = substr($scopesStr, 0, 17) . '…';
            }

            $repos = $token->repos();
            $reposStr = [] === $repos ? '*' : implode(',', $repos);
            if (strlen($reposStr) > 20) {
                $reposStr = substr($reposStr, 0, 19) . '…';
            }

            if ($this->isDecorated($output)) {
                $idCol = \App\UI\Tui\Render\Theme::style(sprintf('%-5d', $token->id()), \App\UI\Tui\Render\Theme::ORANGE);
                $nameCol = \App\UI\Tui\Render\Theme::style(sprintf('%-20s', $token->name()), \App\UI\Tui\Render\Theme::CYAN, bold: true);
                $scopesCol = \App\UI\Tui\Render\Theme::style(sprintf('%-18s', $scopesStr), \App\UI\Tui\Render\Theme::TEAL);
                $reposCol = \App\UI\Tui\Render\Theme::style(sprintf('%-20s', $reposStr), \App\UI\Tui\Render\Theme::HASH);
                $createdCol = \App\UI\Tui\Render\Theme::style(sprintf('%-25s', $token->createdAt()->format(\DateTimeInterface::ATOM)), \App\UI\Tui\Render\Theme::FG_DIM);
                $expCol = str_starts_with($expiresAtStr, 'expired') || str_starts_with($expiresAtStr, 'used')
                    ? \App\UI\Tui\Render\Theme::style($expiresAtStr, \App\UI\Tui\Render\Theme::SELECTOR, bold: true)
                    : ('-' === $expiresAtStr ? \App\UI\Tui\Render\Theme::style('-', \App\UI\Tui\Render\Theme::MUTED) : \App\UI\Tui\Render\Theme::style($expiresAtStr, \App\UI\Tui\Render\Theme::HASH));
                $this->line($output, sprintf('%s %s %s %s %s %s', $idCol, $nameCol, $scopesCol, $reposCol, $createdCol, $expCol));
            } else {
                $this->line($output, sprintf(
                    '%-5d %-20s %-18s %-20s %-25s %s',
                    $token->id(),
                    $token->name(),
                    $scopesStr,
                    $reposStr,
                    $token->createdAt()->format(\DateTimeInterface::ATOM),
                    $expiresAtStr
                ));
            }
        }

        return Command::SUCCESS;
    }
}
