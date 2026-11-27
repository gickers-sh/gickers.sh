<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Access\Application\AccessGuard;
use App\Access\Application\AccessResolver;
use App\Access\Application\ServerPolicy;
use App\Access\Domain\AccessLevel;
use App\Access\Domain\DeployPolicy;
use App\Deploy\Application\ComposeAllowlistParser;
use App\Deploy\Application\DeployAccessResolver;
use App\Deploy\Application\GeneratedComposeBuilder;
use App\Deploy\Domain\DeployQuota;
use App\Deploy\Domain\DeployRefType;
use App\Deploy\Domain\DeployStore;
use App\Deploy\Domain\Model\DeployDomain;
use App\Deploy\Domain\Model\DeployEnvironment;
use App\Deploy\Domain\Spec\BuildSpec;
use App\Deploy\Domain\Spec\ServiceSpec;
use App\Deploy\Domain\Spec\VolumeMountSpec;
use App\Deploy\Infrastructure\Docker\DockerContainerOrchestrator;
use App\Identity\Domain\Model\AccessToken;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\WebLoginTicketStore;
use App\Identity\Infrastructure\WebAuth\WebSessionTokens;
use App\Repo\Application\RepoNamespacePolicy;
use App\Repo\Domain\CollaboratorStore;
use App\Repo\Domain\Model\Collaborator;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoDirectory;
use App\Repo\Domain\RepoName;
use App\Repo\Domain\RepoStore;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Infrastructure\Config\ServerSettings;
use App\Shared\Infrastructure\Process\MinimalProcessEnv;
use App\UI\Ssh\ExceptionRenderer;
use App\UI\Ssh\Setup\SetupScriptGenerator;
use App\UI\Ssh\SshCommandResolver;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;

final class SshSecurityHardeningTest extends TestCase
{
    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-11-27T12:00:00Z');
            }
        };
    }

    private function serverSettings(): ServerSettings
    {
        return new ServerSettings(
            name: 'Codes.sh',
            dataPath: sys_get_temp_dir().'/codes-sec-unit-'.bin2hex(random_bytes(4)),
            sshListenAddr: '127.0.0.1:2223',
            sshPublicUrl: 'ssh://codes.sh:2223',
            httpListenAddr: '127.0.0.1:8080',
            httpPublicUrl: 'https://codes.sh',
            lfsEnabled: true,
            anonAccess: 'read-only',
            allowKeyless: false,
            initialAdminKeys: '',
        );
    }

    public function testC1AccessTokenScopeHierarchyDoesNotEscalate(): void
    {
        $now = new \DateTimeImmutable('2026-11-27T12:00:00Z');
        $user = new User('admin', true, $now);

        [$repoRead] = AccessToken::issue($user, 'ro', null, $now, ['repo:read'], ['alice/app']);
        self::assertTrue($repoRead->hasScope('repo:read'));
        self::assertTrue($repoRead->hasScope('lfs:download'));
        self::assertFalse($repoRead->hasScope('repo:write'));
        self::assertFalse($repoRead->hasScope('repo:admin'));
        self::assertFalse($repoRead->hasScope('admin'));
        self::assertFalse($repoRead->hasScope('lfs:upload'));
        self::assertTrue($repoRead->allowsRepo('/alice/app.git'));
        self::assertFalse($repoRead->allowsRepo('alice/other'));

        [$repoWrite] = AccessToken::issue($user, 'rw', null, $now, ['repo:write'], []);
        self::assertTrue($repoWrite->hasScope('repo:read'));
        self::assertTrue($repoWrite->hasScope('repo:write'));
        self::assertTrue($repoWrite->hasScope('lfs:upload'));
        self::assertFalse($repoWrite->hasScope('repo:admin'));
        self::assertFalse($repoWrite->hasScope('admin'));

        [$repoAdmin] = AccessToken::issue($user, 'ra', null, $now, ['repo:admin'], ['alice/app']);
        self::assertTrue($repoAdmin->hasScope('repo:admin'));
        self::assertTrue($repoAdmin->hasScope('repo:write'));
        self::assertTrue($repoAdmin->hasScope('repo:read'));
        // Critical C1 fix: repo:admin MUST NOT imply server-wide admin!
        self::assertFalse($repoAdmin->hasScope('admin'));

        [$lfsDl] = AccessToken::issue($user, 'lfs-dl', null, $now, ['lfs:download'], ['alice/app']);
        self::assertTrue($lfsDl->isEphemeralLfsOnly());
        self::assertTrue($lfsDl->hasScope('lfs:download'));
        self::assertFalse($lfsDl->hasScope('lfs:upload'));
        self::assertFalse($lfsDl->hasScope('repo:read'));
        self::assertFalse($lfsDl->hasScope('admin'));

        [$lfsUl] = AccessToken::issue($user, 'lfs-ul', null, $now, ['lfs:upload'], ['alice/app']);
        self::assertTrue($lfsUl->isEphemeralLfsOnly());
        self::assertTrue($lfsUl->hasScope('lfs:upload'));
        self::assertTrue($lfsUl->hasScope('lfs:download'));
        self::assertFalse($lfsUl->hasScope('repo:write'));
        self::assertFalse($lfsUl->hasScope('admin'));

        [$full] = AccessToken::issue($user, 'full', null, $now, ['*'], []);
        self::assertFalse($full->isEphemeralLfsOnly());
        self::assertTrue($full->hasScope('admin'));
        self::assertTrue($full->hasScope('repo:admin'));
        self::assertTrue($full->hasScope('lfs:upload'));
    }

    public function testC2WebSessionTokensUseAppSecretNotPublicUrl(): void
    {
        $ticketStore = $this->createStub(WebLoginTicketStore::class);
        $tokens = new WebSessionTokens('super-secret-kernel-key-1234', $this->fixedClock(), $ticketStore);

        $user = new User('admin', true, new \DateTimeImmutable('2026-11-27T12:00:00Z'));
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, 1);

        $validCookie = $tokens->issueSession($user);
        $verified = $tokens->verifySession($validCookie);
        self::assertSame(['id' => 1, 'version' => 0], $verified);

        // Forging a cookie signed with the public HTTP URL must fail
        $payload = rtrim(strtr(base64_encode((string) json_encode([
            'id' => 1,
            'wtv' => 0,
            'exp' => (new \DateTimeImmutable('2026-12-27T12:00:00Z'))->getTimestamp(),
        ])), '+/', '-_'), '=');
        $forgedWithUrl = $payload.'.'.hash_hmac('sha256', $payload, 'https://codes.sh');
        self::assertNull($tokens->verifySession($forgedWithUrl));

        $forgedWithFallback = $payload.'.'.hash_hmac('sha256', $payload, 'codes.sh-session-secret');
        self::assertNull($tokens->verifySession($forgedWithFallback));
    }

    public function testC3GeneratedComposeEscapesDollarAndMinimalEnvClearsSecrets(): void
    {
        $now = new \DateTimeImmutable('2026-11-27T12:00:00Z');
        $owner = new User('alice', false, $now);
        $repo = new Repo(RepoName::fromInput('alice/app'), $owner, '', '', false, false, false, null, $now);
        (new \ReflectionProperty(Repo::class, 'id'))->setValue($repo, 1);
        $env = new DeployEnvironment($repo, 'main', DeployRefType::Branch, $now);
        (new \ReflectionProperty(DeployEnvironment::class, 'id'))->setValue($env, 1);

        $parser = new ComposeAllowlistParser();
        $spec = $parser->parseComposeYaml(<<<'YAML'
services:
  web:
    image: nginx:alpine
    command: ["sh", "-c", "echo ${APP_SECRET} $DATABASE_URL"]
    environment:
      LEAK_ATTEMPT: "${APP_SECRET}"
YAML, new DeployQuota());

        $builder = new GeneratedComposeBuilder();
        $yaml = $builder->buildYaml($env, $spec, new DeployQuota(), ['USER_SECRET' => 'p@$$w0rd_${APP_SECRET}'], []);

        self::assertStringContainsString('$${APP_SECRET}', $yaml);
        self::assertStringContainsString('$$DATABASE_URL', $yaml);
        self::assertStringContainsString('p@$$$$w0rd_$${APP_SECRET}', $yaml);
        // No unescaped `${APP_SECRET}` should remain
        self::assertDoesNotMatchRegularExpression('/(?<!\$)\$\{APP_SECRET\}/', $yaml);

        putenv('APP_SECRET=top-secret-value');
        $_ENV['DATABASE_URL'] = 'postgres://secret';
        try {
            $minimal = MinimalProcessEnv::build(['CUSTOM_SAFE' => 'ok']);
            self::assertFalse($minimal['APP_SECRET'] ?? null);
            self::assertFalse($minimal['DATABASE_URL'] ?? null);
            self::assertSame('ok', $minimal['CUSTOM_SAFE']);
        } finally {
            putenv('APP_SECRET');
            unset($_ENV['DATABASE_URL']);
        }
    }

    public function testH2DeployAccessResolverBlocksNonAdminCollaboratorOnAdminRepo(): void
    {
        $now = new \DateTimeImmutable('2026-11-27T12:00:00Z');
        $admin = new User('admin', true, $now);
        $collab = new User('bob', false, $now);
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($admin, 1);
        $ref->setValue($collab, 2);

        $repo = new Repo(RepoName::fromInput('admin/prod'), $admin, '', '', true, false, false, null, $now);

        $repoStore = $this->createStub(RepoStore::class);
        $repoStore->method('byName')->willReturn($repo);

        $collabStore = $this->createStub(CollaboratorStore::class);
        $collabStore->method('find')->willReturn(new Collaborator($repo, $collab, AccessLevel::ReadWriteAccess, $now));

        $repoDir = $this->createStub(RepoDirectory::class);
        $deployStore = $this->createStub(DeployStore::class);
        $deployStore->method('configForRepo')->willReturn(null);
        $deployStore->method('refRulesForRepo')->willReturn([]);

        foreach ([DeployPolicy::AdminsOnly, DeployPolicy::Authorized, DeployPolicy::RepoAdmins] as $policy) {
            $em = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);
            $em->method('find')->willReturnCallback(static fn (string $class, mixed $id) => match ($id) {
                \App\Access\Domain\SettingKey::DeployPolicy => new \App\Access\Domain\ServerSetting($id, $policy->value, $now),
                \App\Access\Domain\SettingKey::AnonAccess => new \App\Access\Domain\ServerSetting($id, 'no-access', $now),
                default => null,
            });
            $serverPolicy = new ServerPolicy($em, $this->serverSettings(), $this->fixedClock());
            $accessResolver = new AccessResolver($serverPolicy, $repoStore, $collabStore, $repoDir);
            $accessGuard = new AccessGuard($accessResolver, $repoStore);
            $deployResolver = new DeployAccessResolver($serverPolicy, $accessResolver, $accessGuard, $deployStore);

            self::assertFalse(
                $deployResolver->canManageDeploy($collab, $repo, 'main'),
                sprintf('ReadWrite collaborator must NOT manage deploys under policy %s', $policy->value),
            );
            self::assertTrue(
                $deployResolver->canManageDeploy($admin, $repo, 'main'),
                sprintf('Server admin must still manage deploys under policy %s', $policy->value),
            );
        }
    }

    public function testH3RejectsDockerfileSymlinkEscapeAndNewlineInjection(): void
    {
        $parser = new ComposeAllowlistParser();

        try {
            $parser->parseComposeYaml("services:\n  app:\n    image: \"alpine\\nRUN id\"\n", new DeployQuota());
            self::fail('Expected InvalidInput for newline in image');
        } catch (InvalidInput) {
            $this->addToAssertionCount(1);
        }

        try {
            $parser->parseComposeYaml("services:\n  app:\n    image: alpine\n    volumes:\n      - \"./:/app\\nRUN id\"\n", new DeployQuota());
            self::fail('Expected InvalidInput for newline in volume target');
        } catch (InvalidInput) {
            $this->addToAssertionCount(1);
        }

        $workDir = sys_get_temp_dir().'/codes-h3-work-'.bin2hex(random_bytes(4));
        $outsideFile = sys_get_temp_dir().'/codes-h3-outside-'.bin2hex(random_bytes(4));
        mkdir($workDir, 0o700, true);
        file_put_contents($outsideFile, "ORIGINAL\n");
        symlink($outsideFile, $workDir.'/Dockerfile');

        $orchestrator = new DockerContainerOrchestrator(
            $this->serverSettings(),
            $this->createStub(RepoDirectory::class),
            new GeneratedComposeBuilder(),
        );

        $service = new ServiceSpec(
            name: 'app',
            build: new BuildSpec(context: '.', dockerfile: 'Dockerfile'),
            volumes: [new VolumeMountSpec(source: '.', target: '/app', isRelativeRepoPath: true)],
        );

        try {
            $this->expectException(InvalidInput::class);
            $orchestrator->prepareRelativeMountBake($workDir, $service);
        } finally {
            self::assertSame("ORIGINAL\n", file_get_contents($outsideFile), 'Outside file must never be overwritten');
            (new Filesystem())->remove([$workDir, $outsideFile]);
        }
    }

    public function testH5SetupScriptRejectsInjectionAndRemovesEval(): void
    {
        $generator = new SetupScriptGenerator($this->serverSettings(), 'prod');

        foreach (['alice"; id #', '$(whoami)', "alice\nProxyCommand sh", 'alice`id`'] as $badUser) {
            try {
                $generator->generateRawConfig('codes.sh', 2223, $badUser, null);
                self::fail('Expected InvalidInput for username: '.$badUser);
            } catch (InvalidInput) {
                $this->addToAssertionCount(1);
            }
        }

        foreach (['~/.ssh/id_ed25519;id', "~/.ssh/key\nProxyCommand evil", '$(id)', '-oProxyCommand=evil'] as $badKeyPath) {
            try {
                $generator->generateBashScript('codes.sh', 2223, 'alice', $badKeyPath, '', null, '');
                self::fail('Expected InvalidInput for keyPath: '.$badKeyPath);
            } catch (InvalidInput) {
                $this->addToAssertionCount(1);
            }
        }

        $bashScript = $generator->generateBashScript('codes.sh', 2223, 'alice', null, '', null, '');
        self::assertStringNotContainsString('eval "$REG_CMD"', $bashScript);
        self::assertStringContainsString('ssh "${REG_ARGS[@]}"', $bashScript);
    }

    public function testM2ResolverRejectsListCommand(): void
    {
        $resolver = new SshCommandResolver(['info', 'user:create', 'settings:allow-signup']);
        self::assertNull($resolver->resolve(['list']));
        self::assertNull($resolver->resolve(['list', '--raw']));
    }

    public function testM4ServiceNameRejectsCliFlags(): void
    {
        foreach (['--help', '-f', '--timestamps', '--tail=9999'] as $bad) {
            try {
                DeployDomain::normalizeServiceName($bad);
                self::fail('Expected InvalidInput for service name: '.$bad);
            } catch (InvalidInput) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame('web-app_1', DeployDomain::normalizeServiceName('web-app_1'));
    }

    public function testM5RepoNamespacePolicyEnforcesUserPrefixAndDotReservation(): void
    {
        $now = new \DateTimeImmutable('2026-11-27T12:00:00Z');
        $alice = new User('alice', false, $now);
        $admin = new User('admin', true, $now);
        $policy = new RepoNamespacePolicy();

        // Allowed for non-admin
        $policy->assertCanClaimName($alice, RepoName::fromInput('alice/my-repo'));
        $policy->assertCanClaimName($alice, RepoName::fromInput('alice/nested/my-repo'));
        $this->addToAssertionCount(2);

        // Allowed for admin
        $policy->assertCanClaimName($admin, RepoName::fromInput('.soft-serve'));
        $policy->assertCanClaimName($admin, RepoName::fromInput('global-repo'));
        $policy->assertCanClaimName($admin, RepoName::fromInput('alice/admin-created'));
        $this->addToAssertionCount(3);

        // Denied for non-admin
        foreach (['.soft-serve', 'alice/.hidden', 'unscoped-repo', 'bob/stolen', 'alice'] as $forbidden) {
            try {
                $policy->assertCanClaimName($alice, RepoName::fromInput($forbidden));
                self::fail('Expected AccessDenied for non-admin claiming: '.$forbidden);
            } catch (AccessDenied) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testL3ExceptionRendererMasksInternalExceptions(): void
    {
        $renderer = new ExceptionRenderer();
        $out = new BufferedOutput();

        $code = $renderer->render(new \RuntimeException('SQLSTATE[HY000]: secret /var/db/path leaked'), $out);
        self::assertSame(1, $code);
        self::assertStringContainsString('An internal server error occurred.', $out->fetch());

        $domainOut = new BufferedOutput();
        $code2 = $renderer->render(new InvalidInput('explicit validation error'), $domainOut);
        self::assertSame(1, $code2);
        self::assertStringContainsString('explicit validation error', $domainOut->fetch());
    }
}
