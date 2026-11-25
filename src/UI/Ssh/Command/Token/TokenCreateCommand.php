<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Token;

use App\Identity\Application\IssueAccessToken;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\DurationParser;
use App\Shared\Domain\Exception\InvalidInput;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class TokenCreateCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'token:create';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly IssueAccessToken $issueAccessToken,
        private readonly ClockInterface $clock,
        private readonly UserStore $userStore,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Create a new access token')
            ->addArgument('name', InputArgument::IS_ARRAY, 'Token name (e.g. "ci-bot", "deploy")')
            ->addOption('expires-in', 'e', InputOption::VALUE_REQUIRED, 'Token expiration time (e.g. 1y, 3mo, 2w, 5d4h, 1h30m, none, never)')
            ->addOption('scope', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Token scopes: all, repo:read, repo:write, repo:admin, lfs (can be specified multiple times or comma-separated)')
            ->addOption('repo', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict token to specific repositories (can be specified multiple times or comma-separated)')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Optional description of the token purpose')
            ->addOption('single-use', '1', InputOption::VALUE_NONE, 'Allow the token to be used only once')
            ->addOption('one-time', null, InputOption::VALUE_NONE, 'Alias for --single-use')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Target username to create token for (admin only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $nameParts */
        $nameParts = (array) $input->getArgument('name');
        if ([] === $nameParts) {
            $name = 'token-' . bin2hex(random_bytes(4));
        } else {
            $name = implode(' ', $nameParts);
        }

        $expiresIn = $input->getOption('expires-in');
        $expiresAt = null;
        $notice = 'Access token created';

        if (is_string($expiresIn) && '' !== trim($expiresIn)) {
            $norm = strtolower(trim($expiresIn));
            if ('none' !== $norm && 'never' !== $norm) {
                $seconds = DurationParser::toSeconds($expiresIn);
                if ($seconds > 0) {
                    $expiresAt = $this->clock->now()->modify(sprintf('+%d seconds', $seconds));
                    $notice .= sprintf(' (expires in %s)', trim($expiresIn));
                }
            }
        }

        /** @var list<string> $rawScopes */
        $rawScopes = (array) $input->getOption('scope');
        $scopes = [];
        foreach ($rawScopes as $scopeArg) {
            foreach (explode(',', $scopeArg) as $sub) {
                $sub = trim(strtolower($sub));
                if ('' !== $sub) {
                    $scopes[] = $sub;
                }
            }
        }
        if ([] === $scopes) {
            $scopes = ['*'];
        }

        /** @var list<string> $rawRepos */
        $rawRepos = (array) $input->getOption('repo');
        $repos = [];
        foreach ($rawRepos as $repoArg) {
            foreach (explode(',', $repoArg) as $sub) {
                $sub = trim($sub);
                if ('' !== $sub) {
                    $repos[] = RepoName::sanitize($sub);
                }
            }
        }

        $rawDescription = $input->getOption('description');
        $description = \is_string($rawDescription) && '' !== trim($rawDescription) ? trim($rawDescription) : null;

        $singleUse = (bool) ($input->getOption('single-use') || $input->getOption('one-time'));

        $targetUser = null;
        $targetUsername = $input->getOption('user');
        if (is_string($targetUsername) && '' !== trim($targetUsername)) {
            $targetUser = $this->userStore->byUsername(trim($targetUsername));
            if (null === $targetUser) {
                throw new InvalidInput(sprintf('Target user "%s" not found', $targetUsername));
            }
        }

        if ($singleUse) {
            $notice .= ' [single-use]';
        }
        if ($scopes !== ['*']) {
            $notice .= sprintf(' [scopes: %s]', implode(', ', $scopes));
        }
        if ([] !== $repos) {
            $notice .= sprintf(' [repos: %s]', implode(', ', $repos));
        }
        if (null !== $targetUser) {
            $notice .= sprintf(' for @%s', $targetUser->username());
        }

        [, $secret] = ($this->issueAccessToken)(
            actor: $this->actor(),
            name: $name,
            expiresAt: $expiresAt,
            scopes: $scopes,
            repos: $repos,
            description: $description,
            targetUser: $targetUser,
            singleUse: $singleUse,
        );

        if ($this->isDecorated($output)) {
            $this->errLine($output, \App\UI\Tui\Render\Theme::style('✔ ' . $notice, \App\UI\Tui\Render\Theme::GREEN, bold: true));
            $this->line($output, \App\UI\Tui\Render\Theme::style($secret, \App\UI\Tui\Render\Theme::HIGHLIGHT, bold: true));
        } else {
            $this->errLine($output, $notice);
            $this->line($output, $secret);
        }

        return Command::SUCCESS;
    }
}
