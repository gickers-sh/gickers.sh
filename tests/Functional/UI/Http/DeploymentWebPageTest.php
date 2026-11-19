<?php

declare(strict_types=1);

namespace App\Tests\Functional\UI\Http;

use App\Deploy\Domain\DeployRefType;
use App\Deploy\Domain\DeployStatus;
use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\Model\DeployDomain;
use App\Deploy\Domain\Model\DeployEnvironment;
use App\Deploy\Domain\Model\DeployEnvVar;
use App\Deploy\Domain\Model\DeployRelease;
use App\Identity\Application\CreateUser;
use App\Identity\Application\IssueAccessToken;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Repo\Application\CreateRepo;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Repo\Infrastructure\Git\BareRepositoryStorage;
use App\Tests\GitTestHelper;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DeploymentWebPageTest extends KernelTestCase
{
    private CreateUser $createUser;
    private CreateRepo $createRepo;
    private IssueAccessToken $issueToken;
    private BareRepositoryStorage $storage;
    private UserStore $userStore;
    private DeployStore $deployStore;
    private User $admin;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var CreateUser $createUser */
        $createUser = $container->get(CreateUser::class);
        $this->createUser = $createUser;

        /** @var CreateRepo $createRepo */
        $createRepo = $container->get(CreateRepo::class);
        $this->createRepo = $createRepo;

        /** @var IssueAccessToken $issueToken */
        $issueToken = $container->get(IssueAccessToken::class);
        $this->issueToken = $issueToken;

        /** @var BareRepositoryStorage $storage */
        $storage = $container->get(BareRepositoryStorage::class);
        $this->storage = $storage;

        /** @var UserStore $userStore */
        $userStore = $container->get(UserStore::class);
        $this->userStore = $userStore;

        /** @var DeployStore $deployStore */
        $deployStore = $container->get(DeployStore::class);
        $this->deployStore = $deployStore;

        $admin = new User('depwebadmin-' . bin2hex(random_bytes(3)), true, new \DateTimeImmutable());
        $this->userStore->save($admin);
        $this->admin = $admin;
    }

    private function user(string $prefix): User
    {
        return ($this->createUser)($this->admin, $prefix . '-' . bin2hex(random_bytes(3)));
    }

    private function secret(User $user): string
    {
        [, $secret] = ($this->issueToken)($user, 'web-token');

        return $secret;
    }

    private function get(string $uri, ?string $token = null): Response
    {
        $server = null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Token ' . $token];
        $request = Request::create($uri, 'GET', [], [], [], $server);

        $kernel = self::$kernel ?? throw new \LogicException('Kernel not booted');

        return $kernel->handle($request);
    }

    public function testDeploymentShowPageRendersEnvironmentAndReleases(): void
    {
        $owner = $this->user('alice');
        $ownerToken = $this->secret($owner);

        $repo = ($this->createRepo)($owner, 'my-app-' . bin2hex(random_bytes(3)), private: false, description: 'Test app');
        $repoName = $repo->name()->value;
        $git = $this->storage->open(RepoName::fromInput($repoName));
        GitTestHelper::writeFile($git, 'main', 'Dockerfile', "FROM alpine\n", 'init');

        $now = new \DateTimeImmutable();
        $env = new DeployEnvironment($repo, 'main', DeployRefType::Branch, $now);
        $env->setStatus(DeployStatus::Running, $now);
        $env->markDeployed('04a54bef59c494670dac37af76384308cf2552b3', $now);
        $this->deployStore->saveEnvironment($env);

        $domain = new DeployDomain($env, 'hello.codes.sh', 'app', 80, $now);
        $this->deployStore->saveDomain($domain);

        $envVar = new DeployEnvVar($env, 'APP_ENV', 'encrypted_secret', $now);
        $this->deployStore->saveEnvVar($envVar);

        // Create multiple releases: Release 1 failed, Release 2 succeeded
        $rel1 = new DeployRelease($env, '04a54bef59c494670dac37af76384308cf2552b3', $owner, $now);
        $rel1->appendLog("==> Preparing deployment\n[error] docker compose failed\n");
        $rel1->markFailed('docker failed', $now->modify('+5 seconds'));
        $this->deployStore->saveRelease($rel1);

        $rel2 = new DeployRelease($env, '04a54bef59c494670dac37af76384308cf2552b3', $owner, $now->modify('+10 seconds'));
        $rel2->appendLog("==> Preparing deployment\n==> Deployment succeeded.\n");
        $rel2->markSucceeded($now->modify('+25 seconds'));
        $this->deployStore->saveRelease($rel2);

        // Anonymous user cannot view deployments (404)
        $anon = $this->get('/' . $repoName . '/deployments/main');
        $this->assertSame(404, $anon->getStatusCode());

        // Other regular user cannot view deployments (404)
        $otherUser = $this->user('bob');
        $otherToken = $this->secret($otherUser);
        $otherRes = $this->get('/' . $repoName . '/deployments/main', $otherToken);
        $this->assertSame(404, $otherRes->getStatusCode());

        // Repo owner with write access can view deployments
        $response = $this->get('/' . $repoName . '/deployments/main', $ownerToken);
        $this->assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();

        $this->assertStringContainsString('my-app', $html);
        $this->assertStringContainsString('main', $html);
        $this->assertStringContainsString('running', $html);
        $this->assertStringContainsString('hello.codes.sh', $html);
        $this->assertStringContainsString('APP_ENV', $html);
        $this->assertStringNotContainsString('encrypted_secret', $html); // secrets must stay hidden

        // Both releases must appear in the history
        $this->assertStringContainsString('#' . $rel1->id(), $html);
        $this->assertStringContainsString('#' . $rel2->id(), $html);

        // By default, latest release (#2) log is displayed
        $this->assertStringContainsString('Deployment succeeded.', $html);

        // Request specific release log (?release=rel1)
        $rel1Response = $this->get('/' . $repoName . '/deployments/main?release=' . $rel1->id(), $ownerToken);
        $this->assertSame(200, $rel1Response->getStatusCode());
        $rel1Html = (string) $rel1Response->getContent();
        $this->assertStringContainsString('docker compose failed', $rel1Html);

        // Alias /deploy/main works
        $aliasResponse = $this->get('/' . $repoName . '/deploy/main', $ownerToken);
        $this->assertSame(200, $aliasResponse->getStatusCode());

        // Main repository show page makes the deployment card clickable
        $repoPage = $this->get('/' . $repoName, $ownerToken);
        $this->assertSame(200, $repoPage->getStatusCode());
        $repoHtml = (string) $repoPage->getContent();
        $this->assertStringContainsString('Docker Deployments', $repoHtml);
        $this->assertStringContainsString('data-href="/' . $repoName . '/deployments/main"', $repoHtml);
        $this->assertStringContainsString('href="/' . $repoName . '/deployments/main"', $repoHtml);
        $this->assertStringContainsString('#' . $rel1->id(), $repoHtml);
        $this->assertStringContainsString('#' . $rel2->id(), $repoHtml);
    }
}
