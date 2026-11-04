<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command;

use App\Lfs\Domain\TokenIssuer;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\UI\Ssh\SshSession;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates an Ed25519 JSON Web Token for Git LFS HTTP access.
 * Parity with Go `pkg/ssh/cmd/jwt.go`:
 * - command name `jwt`
 * - subject `username#id`
 * - audience list of sanitized repositories
 * - 1h validity.
 */
final class JwtCommand extends Command
{
    public function __construct(
        private readonly TokenIssuer $issuer,
        private readonly ServerSettings $settings,
        private readonly SshSession $session,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct(self::sshName());
    }

    public static function sshName(): string
    {
        return 'jwt';
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Generate a JSON Web Token')
            ->addArgument('repositories', InputArgument::IS_ARRAY, 'Repositories for which the token is valid');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->session->user();
        if (null === $user) {
            throw new AccessDenied('user not found');
        }

        /** @var list<string> $rawRepos */
        $rawRepos = $input->getArgument('repositories');
        $audience = [];
        foreach ($rawRepos as $repo) {
            $audience[] = RepoName::sanitize($repo);
        }

        $now = $this->clock->now();
        $expiresAt = $now->add(new \DateInterval('PT3600S'));

        $subject = sprintf('%s#%d', $user->username(), $user->id());
        $token = $this->issuer->issue(
            $subject,
            $audience,
            $this->settings->httpPublicUrl,
            $now,
            $expiresAt,
        );

        $output->writeln($token);

        return Command::SUCCESS;
    }
}
