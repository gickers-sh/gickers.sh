<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ssh;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Tests\GitTestHelper;
use App\UI\Ssh\SshSession;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class DeployCommandTest extends KernelTestCase
{
    private User $admin;
    private User $regularOwner;
    private Repo $repo;
    private SshSession $session;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $userStore = $container->get(UserStore::class);
        $this->admin = new User('depadmin-'.bin2hex(random_bytes(3)), true, new \DateTimeImmutable());
        $userStore->save($this->admin);

        $this->regularOwner = new User('depowner-'.bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $userStore->save($this->regularOwner);

        /** @var SshSession $session */
        $session = $container->get('test.ssh_session');
        $this->session = $session;
        $this->session->authenticate($this->admin, 'SHA256:admin');

        $storage = $container->get(BareRepositoryStorage::class);
        $repoName = RepoName::fromInput('deploy-app-'.bin2hex(random_bytes(3)));
        $gitRepo = $storage->init($repoName, 'main');
        GitTestHelper::writeFile($gitRepo, 'main', 'Dockerfile', "FROM alpine\n", 'init main');
        GitTestHelper::writeFile($gitRepo, 'staging', 'Dockerfile', "FROM alpine\n", 'init staging');

        $this->repo = new Repo($repoName, $this->regularOwner, 'proj', 'desc', false, false, false, null, new \DateTimeImmutable());
        $container->get(RepoStore::class)->save($this->repo);
    }

    private function tester(string $serviceId): CommandTester
    {
        /** @var \Symfony\Component\Console\Command\Command $cmd */
        $cmd = static::getContainer()->get($serviceId);

        return new CommandTester($cmd);
    }

    public function testDeployPolicyQuotasEnvironmentsEnvVarsAndDomainsLifecycle(): void
    {
        $repoName = $this->repo->nameString();

        // 1. Default policy is admins-only: regularOwner cannot enable deployment yet
        $this->session->authenticate($this->regularOwner, 'SHA256:owner');
        try {
            $this->tester('test.repo_deploy_enable_command')->execute([
                'repository' => $repoName,
                'ref' => 'main',
            ]);
            self::fail('Expected non-admin owner to be denied under admins-only policy');
        } catch (AccessDenied) {
        }

        // 2. Admin switches policy to "authorized" and grants deploy permission on ref "main" and "staging"
        $this->session->authenticate($this->admin, 'SHA256:admin');
        $this->tester('test.settings_deploy_policy_command')->execute(['policy' => 'authorized']);

        $policyTester = $this->tester('test.settings_deploy_policy_command');
        $policyTester->execute([]);
        self::assertSame('authorized', trim($policyTester->getDisplay()));

        // Allow only "main" via ref rule first
        $this->tester('test.repo_deploy_allow_command')->execute([
            'repository' => $repoName,
            'allowed' => 'true',
            '--ref' => 'main',
        ]);

        // Configure repo-level quota and a specific ref quota for main
        $quotaTester = $this->tester('test.repo_deploy_quota_command');
        $quotaTester->execute([
            'repository' => $repoName,
            '--cpus' => '1.0',
            '--memory' => '512m',
            '--pids' => '128',
            '--max-envs' => '2',
            '--max-services' => '3',
        ]);
        self::assertStringContainsString('cpus=1.00 memory=512m pids=128 max-envs=2 max-services=3', $quotaTester->getDisplay());

        $quotaRefTester = $this->tester('test.repo_deploy_quota_command');
        $quotaRefTester->execute([
            'repository' => $repoName,
            '--ref' => 'main',
            '--cpus' => '2.5',
            '--memory' => '2g',
            '--pids' => '256',
        ]);
        self::assertStringContainsString('cpus=2.50 memory=2048m pids=256 max-envs=2 max-services=3', $quotaRefTester->getDisplay());

        // 3. Now regularOwner can enable "main", but not "staging" yet
        $this->session->authenticate($this->regularOwner, 'SHA256:owner');
        $enableMain = $this->tester('test.repo_deploy_enable_command');
        $enableMain->execute(['repository' => $repoName, 'ref' => 'main']);
        self::assertStringContainsString(sprintf('Deployment enabled for %s@main', $repoName), $enableMain->getDisplay());

        try {
            $this->tester('test.repo_deploy_enable_command')->execute(['repository' => $repoName, 'ref' => 'staging']);
            self::fail('Expected staging to be denied until allowed');
        } catch (AccessDenied) {
        }

        // 4. Admin grants user-level deploy-allow to regularOwner so they can deploy any branch on their repo
        $this->session->authenticate($this->admin, 'SHA256:admin');
        $this->tester('test.user_deploy_allow_command')->execute([
            'username' => $this->regularOwner->username(),
            'allowed' => 'true',
        ]);

        $this->session->authenticate($this->regularOwner, 'SHA256:owner');
        $enableStaging = $this->tester('test.repo_deploy_enable_command');
        $enableStaging->execute(['repository' => $repoName, 'ref' => 'staging']);
        self::assertStringContainsString(sprintf('Deployment enabled for %s@staging', $repoName), $enableStaging->getDisplay());

        // 5. Env vars lifecycle (set, list, get masked, get --reveal, unset)
        $this->tester('test.repo_deploy_env_set_command')->execute([
            'repository' => $repoName,
            'ref' => 'main',
            'pairs' => ['APP_ENV=prod', 'DB_PASS=s3cr3t'],
        ]);

        $envList = $this->tester('test.repo_deploy_env_list_command');
        $envList->execute(['repository' => $repoName, 'ref' => 'main']);
        self::assertStringContainsString('APP_ENV=********', $envList->getDisplay());
        self::assertStringContainsString('DB_PASS=********', $envList->getDisplay());

        $envGetMasked = $this->tester('test.repo_deploy_env_get_command');
        $envGetMasked->execute(['repository' => $repoName, 'ref' => 'main', 'key' => 'DB_PASS']);
        self::assertSame('********', trim($envGetMasked->getDisplay()));

        $envGetReveal = $this->tester('test.repo_deploy_env_get_command');
        $envGetReveal->execute(['repository' => $repoName, 'ref' => 'main', 'key' => 'DB_PASS', '--reveal' => true]);
        self::assertSame('s3cr3t', trim($envGetReveal->getDisplay()));

        $this->tester('test.repo_deploy_env_unset_command')->execute([
            'repository' => $repoName,
            'ref' => 'main',
            'key' => 'APP_ENV',
        ]);

        // 6. Domains lifecycle (requires explicit --service and --port, checks collision)
        $domAdd = $this->tester('test.repo_deploy_domain_add_command');
        $domAdd->execute([
            'repository' => $repoName,
            'ref' => 'main',
            'domain' => 'prod-app.example.com',
            '--service' => 'web',
            '--port' => '8080',
            '--skip-dns-check' => true,
        ]);
        self::assertStringContainsString('Domain prod-app.example.com bound to', $domAdd->getDisplay());

        // Collision check: trying to bind the same domain to staging fails with Conflict
        try {
            $this->tester('test.repo_deploy_domain_add_command')->execute([
                'repository' => $repoName,
                'ref' => 'staging',
                'domain' => 'prod-app.example.com',
                '--service' => 'web',
                '--port' => '8080',
                '--skip-dns-check' => true,
            ]);
            self::fail('Expected domain collision across environments to be rejected');
        } catch (Conflict) {
        }

        // Syntactic lint check: IP literal rejected
        try {
            $this->tester('test.repo_deploy_domain_add_command')->execute([
                'repository' => $repoName,
                'ref' => 'staging',
                'domain' => '192.168.1.10',
                '--service' => 'web',
                '--port' => '80',
                '--skip-dns-check' => true,
            ]);
            self::fail('Expected IP address domain to be rejected');
        } catch (InvalidInput) {
        }

        $domGet = $this->tester('test.repo_deploy_domain_get_command');
        $domGet->execute([
            'repository' => $repoName,
            'ref' => 'main',
            'domain' => 'prod-app.example.com',
        ]);
        self::assertStringContainsString('domain=prod-app.example.com ref=main service=web port=8080', $domGet->getDisplay());

        $domList = $this->tester('test.repo_deploy_domain_list_command');
        $domList->execute(['repository' => $repoName]);
        self::assertStringContainsString('prod-app.example.com (main) -> web:8080', $domList->getDisplay());

        // Status output includes both environments and their quotas
        $statusTester = $this->tester('test.repo_deploy_status_command');
        $statusTester->execute(['repository' => $repoName]);
        $statusDisplay = $statusTester->getDisplay();
        self::assertStringContainsString('main (branch)', $statusDisplay);
        self::assertStringContainsString('prod-app.example.com->web:8080', $statusDisplay);
        self::assertStringContainsString('cpus=2.50,mem=2048m,pids=256', $statusDisplay);
        self::assertStringContainsString('staging (branch)', $statusDisplay);
        self::assertStringContainsString('cpus=1.00,mem=512m,pids=128', $statusDisplay);

        // Domain removal
        $this->tester('test.repo_deploy_domain_remove_command')->execute([
            'repository' => $repoName,
            'ref' => 'main',
            'domain' => 'prod-app.example.com',
        ]);

        // 7. Redeploy active environments
        $redeployTester = $this->tester('test.repo_deploy_redeploy_command');
        $redeployTester->execute([
            'repository' => $repoName,
            'ref' => 'main',
        ]);
        self::assertStringContainsString('Queued release #', $redeployTester->getDisplay());

        // Non-admin attempting --all is denied
        try {
            $this->tester('test.repo_deploy_redeploy_command')->execute(['--all' => true]);
            self::fail('Expected non-admin redeploy --all to be denied');
        } catch (AccessDenied) {
        }

        // Admin can redeploy all active deployments
        $this->session->authenticate($this->admin, 'SHA256:admin');
        $redeployAllTester = $this->tester('test.repo_deploy_redeploy_command');
        $redeployAllTester->execute(['--all' => true]);
        self::assertStringContainsString('Queued release #', $redeployAllTester->getDisplay());

        // 8. Logs retrieval
        $logsTester = $this->tester('test.repo_deploy_logs_command');
        $logsTester->execute([
            'repository' => $repoName,
            'ref' => 'main',
            '--build' => true,
        ]);
        self::assertSame(0, $logsTester->getStatusCode());
    }
}
