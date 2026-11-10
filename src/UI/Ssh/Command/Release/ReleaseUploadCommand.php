<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Release;

use App\Repo\Application\UploadRelease;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ReleaseUploadCommand extends AbstractSshCommand
{
    use ReleaseUploadTrait;

    public static function sshName(): string
    {
        return 'release:upload';
    }

    public function __construct(
        SshSession $sshSession,
        private readonly UploadRelease $uploadRelease,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this->configureReleaseUpload();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->runReleaseUpload($this->uploadRelease, $input, $output);
    }
}
