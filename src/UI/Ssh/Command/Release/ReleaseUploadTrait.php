<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Release;

use App\Repo\Application\UploadRelease;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared behaviour for the `release upload` and `repo release upload` SSH
 * commands: identical arguments and stdin handling, both delegating to the same
 * {@see UploadRelease} application service. The binary artifact is read from
 * stdin because a local path is not readable by the SSH server.
 */
trait ReleaseUploadTrait
{
    private ?string $inputStream = null;

    public function setInputStream(?string $input): void
    {
        $this->inputStream = $input;
    }

    protected function configureReleaseUpload(): void
    {
        $this
            ->setDescription('Upload a release artifact for a tag (the file is read from stdin)')
            ->addArgument('repository', InputArgument::REQUIRED, 'Repository name')
            ->addArgument('tag', InputArgument::REQUIRED, 'Existing tag name')
            ->addArgument('filename', InputArgument::REQUIRED, 'Destination artifact filename');
    }

    protected function runReleaseUpload(UploadRelease $uploadRelease, InputInterface $input, OutputInterface $output): int
    {
        $repository = $this->stringArgument($input, 'repository');
        $tag = $this->stringArgument($input, 'tag');
        $filename = $this->stringArgument($input, 'filename');
        $contents = $this->resolveStdin();

        ($uploadRelease)($this->actor(), $repository, $tag, $filename, $contents);

        $this->line($output, sprintf('Uploaded %s (%d bytes) to %s %s', $filename, \strlen($contents), $repository, $tag));

        return Command::SUCCESS;
    }

    private function resolveStdin(): string
    {
        if (null !== $this->inputStream) {
            return $this->inputStream;
        }

        $raw = @file_get_contents('php://stdin');

        return false !== $raw ? $raw : '';
    }
}
