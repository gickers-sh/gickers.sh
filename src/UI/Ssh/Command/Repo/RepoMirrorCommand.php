<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Repo;

use App\Access\Application\AccessGuard;
use App\Access\Domain\AccessLevel;
use App\Repo\Application\ForkRepo;
use App\Repo\Application\ImportRepo;
use App\Repo\Application\RepoNamespacePolicy;
use App\Repo\Domain\Event\RepoCreated;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Repo\Infrastructure\Git\HookInstaller;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\NotFound;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class RepoMirrorCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'repo:mirror';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly RepoStore $repoStore,
        private readonly AccessGuard $accessGuard,
        private readonly ClockInterface $clock,
        private readonly ImportRepo $importRepo,
        private readonly BareRepositoryStorage $storage,
        private readonly HookInstaller $hookInstaller,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly RepoNamespacePolicy $namespacePolicy = new RepoNamespacePolicy(),
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Mirror a repository (external URL or gickers.sh repository) or set/get repository mirror property')
            ->addArgument('repository', InputArgument::REQUIRED, 'Remote Git repository URL or gickers.sh repository name')
            ->addArgument('mirror', InputArgument::OPTIONAL, 'Target repository name (defaults to <username>/<repo_name_source>), or boolean value (true|false)')
            ->addOption('lfs', null, InputOption::VALUE_NONE, 'Pull Git LFS objects')
            ->addOption('lfs-endpoint', null, InputOption::VALUE_REQUIRED, 'Set the Git LFS endpoint', '')
            ->addOption('private', 'p', InputOption::VALUE_NONE, 'Make the repository private')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Set the repository description', '')
            ->addOption('name', 'n', InputOption::VALUE_REQUIRED, 'Set the project name', '')
            ->addOption('hidden', 'H', InputOption::VALUE_NONE, 'Hide the repository from the UI');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->stringArgument($input, 'repository');
        $mirrorArg = $this->nullableStringArgument($input, 'mirror');

        // 1. External URL -> Mirror from remote URL
        if (ForkRepo::isExternalUrl($name)) {
            return $this->createMirrorFromRemote($input, $output, $name, $mirrorArg);
        }

        // 2. Boolean argument (true/false) -> toggle mirror on existing repo
        if (null !== $mirrorArg && in_array(strtolower($mirrorArg), ['true', 'false', '1', '0'], true)) {
            $repo = $this->accessGuard->assertRepo($this->actor(), RepoName::fromInput($name), AccessLevel::ReadWriteAccess);
            if (null === $repo) {
                throw new NotFound('repository not found');
            }

            $isMirror = $this->parseBooleanArgument($mirrorArg, 'mirror');
            $repo->setMirror($isMirror, $this->clock->now());
            $this->repoStore->save($repo);

            return Command::SUCCESS;
        }

        // 3. If destination is explicitly provided (and not boolean) -> mirror gickers.sh repo into custom destination
        if (null !== $mirrorArg) {
            return $this->createMirrorFromLocal($input, $output, $name, $mirrorArg);
        }

        // 4. Single argument: check if the actor owns this repo or if target default <username>/<repo_name_source> matches
        $actor = $this->actor();
        $existing = null;
        try {
            $sourceName = RepoName::fromInput($name);
            $existing = $this->accessGuard->assertRepo($actor, $sourceName, AccessLevel::ReadOnlyAccess);
        } catch (\Throwable) {
            $existing = null;
        }

        if (null !== $existing && null !== $actor) {
            $base = ForkRepo::extractRepoNameSource($name);
            $defaultTarget = '' !== $actor->username() ? $actor->username().'/'.$base : $base;

            // If the user already owns this repository (or it's in their namespace), they are inspecting its mirror status:
            if ($existing->nameString() === $defaultTarget || ($existing->owner()->id() === $actor->id())) {
                $this->line($output, $this->formatBoolean($output, $existing->isMirror()));

                return Command::SUCCESS;
            }

            // Otherwise, the caller is asking to mirror another gickers.sh repo into their own namespace:
            return $this->createMirrorFromLocal($input, $output, $name, null);
        }

        if (null !== $existing) {
            $this->line($output, $this->formatBoolean($output, $existing->isMirror()));

            return Command::SUCCESS;
        }

        throw new NotFound(sprintf('repository "%s" not found', $name));
    }

    private function createMirrorFromRemote(
        InputInterface $input,
        OutputInterface $output,
        string $remoteUrl,
        ?string $destination,
    ): int {
        $actor = $this->actor();
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $base = ForkRepo::extractRepoNameSource($remoteUrl);

        if (null !== $destination && '' !== trim($destination)) {
            $dest = trim($destination);
            if (!str_contains($dest, '/') && '' !== $actor->username()) {
                $dest = $actor->username().'/'.$dest;
            }
        } else {
            $dest = '' !== $actor->username() ? $actor->username().'/'.$base : $base;
        }

        $repo = ($this->importRepo)(
            actor: $actor,
            name: $dest,
            remoteUrl: $remoteUrl,
            mirror: true,
            private: (bool) $input->getOption('private'),
            hidden: (bool) $input->getOption('hidden'),
            description: $this->stringOption($input, 'description'),
            projectName: $this->stringOption($input, 'name'),
            lfs: (bool) $input->getOption('lfs'),
            lfsEndpoint: $this->stringOption($input, 'lfs-endpoint'),
        );

        $this->errLine($output, sprintf('Mirrored %s into %s', $remoteUrl, $repo->nameString()));

        return Command::SUCCESS;
    }

    private function createMirrorFromLocal(
        InputInterface $input,
        OutputInterface $output,
        string $source,
        ?string $destination,
    ): int {
        $actor = $this->actor();
        if (null === $actor) {
            throw new AccessDenied('unauthorized');
        }

        $sourceName = RepoName::fromInput($source);
        $src = $this->accessGuard->assertRepo($actor, $sourceName, AccessLevel::ReadOnlyAccess);
        if (null === $src) {
            throw new NotFound(sprintf('repository "%s" not found', $source));
        }

        $base = ForkRepo::extractRepoNameSource($source);

        if (null !== $destination && '' !== trim($destination)) {
            $dest = trim($destination);
            if (!str_contains($dest, '/') && '' !== $actor->username()) {
                $dest = $actor->username().'/'.$dest;
            }
        } else {
            $dest = '' !== $actor->username() ? $actor->username().'/'.$base : $base;
        }

        $targetName = RepoName::fromInput($dest);
        $this->namespacePolicy->assertCanClaimName($actor, $targetName);

        if ($this->storage->exists($targetName)) {
            throw new Conflict(sprintf('repository "%s" already exists', $targetName));
        }

        $sourcePath = $this->storage->pathFor($src->name());
        $now = $this->clock->now();

        try {
            $gitRepo = $this->storage->importMirror($sourcePath, $targetName, mirror: true, enforcePublicRemote: false);
            $this->hookInstaller->install($gitRepo);
            $repo = new Repo(
                name: $targetName,
                owner: $actor,
                projectName: $this->stringOption($input, 'name') ?: $src->projectName(),
                description: $this->stringOption($input, 'description') ?: $src->description(),
                isPrivate: (bool) $input->getOption('private') || $src->isPrivate(),
                isHidden: (bool) $input->getOption('hidden'),
                isMirror: true,
                forkOf: null,
                createdAt: $now,
            );
            $this->repoStore->save($repo);
        } catch (\Throwable $e) {
            $this->storage->delete($targetName);
            throw $e;
        }

        $this->eventDispatcher->dispatch(new RepoCreated($repo->id(), $actor->id()));
        $this->errLine($output, sprintf('Mirrored %s into %s', $source, $repo->nameString()));

        return Command::SUCCESS;
    }
}
