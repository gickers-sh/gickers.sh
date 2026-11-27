<?php

declare(strict_types=1);

namespace App\UI\Ssh\Command\Server;

use App\Access\Application\AccessGuard;
use App\UI\Ssh\Command\AbstractSshCommand;
use App\UI\Ssh\SshSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ServerUpdateCommand extends AbstractSshCommand
{
    public static function sshName(): string
    {
        return 'server:update';
    }

    /**
     * @return list<string>
     */
    public static function sshAliases(): array
    {
        return [
            'host:update',
            'server:upgrade',
            'host:upgrade',
        ];
    }

    public function __construct(
        SshSession $sshSession,
        private readonly AccessGuard $accessGuard,
    ) {
        parent::__construct($sshSession);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Generate self-contained update and upgrade script for the host server')
            ->addOption('bash', null, InputOption::VALUE_NONE, 'Generate Bash update script')
            ->addOption('ps1', null, InputOption::VALUE_NONE, 'Generate PowerShell update script');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->accessGuard->assertServerAdmin($this->actor());

        // Bash update script by default
        $script = <<<'BASH'
#!/usr/bin/env bash
# ==============================================================================
#  ⚡ Gickers.sh — Script de Mise à Jour & Migration du Serveur
# ==============================================================================
set -euo pipefail

echo "==> [1/4] Récupération des dernières modifications Git..."
if [ -d ".git" ]; then
    git fetch --all --prune
    git pull --ff-only || {
        echo "⚠️  Attention : Fast-forward impossible, vérifiez vos modifications locales."
    }
else
    echo "ℹ️  Aucun dépôt .git détecté à la racine d'installation."
fi

echo "==> [2/4] Reconstruction et mise à jour des conteneurs Docker..."
if command -v docker >/dev/null 2>&1; then
    docker compose pull --ignore-pull-failures 2>/dev/null || true
    docker compose build --pull || docker compose build
    docker compose up -d --remove-orphans
else
    echo "⚠️  Docker n'est pas disponible dans le PATH."
fi

echo "==> [3/4] Exécution des migrations de base de données..."
if docker compose ps --services 2>/dev/null | grep -q "app"; then
    docker compose exec -T app bin/console doctrine:migrations:migrate --no-interaction || true
fi

echo "==> [4/4] Redémarrage ou rechargement du service..."
if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet gickers.service 2>/dev/null; then
    sudo systemctl restart gickers.service || true
fi

echo "✔ Mise à jour terminée avec succès !"
BASH;

        $output->write($script);

        return Command::SUCCESS;
    }
}
