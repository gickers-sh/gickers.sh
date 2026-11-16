<?php

declare(strict_types=1);

namespace App\UI\Ssh;

use App\Shared\Domain\Exception\InvalidInput;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;

class SshCliApplication extends Application
{
    private SshCommandResolver $resolver;

    public function __construct(
        #[AutowireLocator('app.ssh_command', defaultIndexMethod: 'sshName')]
        private readonly ContainerInterface $commandLocator,
    ) {
        parent::__construct('codes.sh', '1.0.0');
        $this->setAutoExit(false);
        $this->setCatchExceptions(false);

        /** @var array<string, string> $provided */
        $provided = [];
        if ($this->commandLocator instanceof ServiceProviderInterface) {
            /** @var array<string, string> $provided */
            $provided = $this->commandLocator->getProvidedServices();
        }

        $commandMap = [];
        $aliasMap = [];

        foreach ($provided as $name => $serviceType) {
            $commandMap[$name] = $name;

            /** @var list<string> $aliases */
            $aliases = [];
            if (class_exists($serviceType) && method_exists($serviceType, 'sshAliases')) {
                /** @var list<string> $aliases */
                $aliases = $serviceType::sshAliases();
            }

            if ([] !== $aliases) {
                $aliasMap[$name] = $aliases;
                foreach ($aliases as $alias) {
                    $commandMap[$alias] = $name;
                }
            }
        }
        $this->setCommandLoader(new ContainerCommandLoader($this->commandLocator, $commandMap));
        /** @var list<string> $canonicalNames */
        $canonicalNames = array_keys($provided);
        $this->resolver = new SshCommandResolver($canonicalNames, $aliasMap);
    }

    public function getResolver(): SshCommandResolver
    {
        return $this->resolver;
    }

    protected function configureIO(InputInterface $input, OutputInterface $output): void
    {
        $output->setVerbosity(OutputInterface::VERBOSITY_NORMAL);
        if ($input->hasParameterOption(['--quiet', '-q'], true)) {
            $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        }
    }

    protected function getDefaultInputDefinition(): \Symfony\Component\Console\Input\InputDefinition
    {
        return new \Symfony\Component\Console\Input\InputDefinition([
            new \Symfony\Component\Console\Input\InputArgument('command', \Symfony\Component\Console\Input\InputArgument::REQUIRED, 'The command to execute'),
            new \Symfony\Component\Console\Input\InputOption('--help', '-h', \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Display help for the given command'),
            new \Symfony\Component\Console\Input\InputOption('--quiet', '-q', \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Only errors are displayed'),
            new \Symfony\Component\Console\Input\InputOption('--version', '-V', \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Display this application version'),
        ]);
    }

    /**
     * @param list<string> $tokens
     */
    public function runWithTokens(array $tokens, OutputInterface $output, ?InputInterface $parentInput = null): int
    {
        $resolved = $this->resolver->resolve($tokens);
        if (null === $resolved) {
            throw new InvalidInput('Unknown command');
        }

        $argv = ['ssh', $resolved->name, ...$resolved->arguments];
        $input = new ArgvInput($argv);
        if ($parentInput instanceof StreamableInputInterface && null !== $parentInput->getStream()) {
            $input->setStream($parentInput->getStream());
        }

        return $this->run($input, $output);
    }
}
