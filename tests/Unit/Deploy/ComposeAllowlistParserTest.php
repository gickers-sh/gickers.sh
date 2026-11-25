<?php

declare(strict_types=1);

namespace App\Tests\Unit\Deploy;

use App\Deploy\Application\ComposeAllowlistParser;
use App\Deploy\Application\GeneratedComposeBuilder;
use App\Deploy\Domain\DeployQuota;
use App\Deploy\Domain\DeployRefType;
use App\Deploy\Domain\Model\DeployDomain;
use App\Deploy\Domain\Model\DeployEnvironment;
use App\Deploy\Infrastructure\Crypto\SodiumEnvVarEncryptor;
use App\Identity\Domain\Model\User;
use App\Repo\Domain\Model\Repo;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\InvalidInput;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ComposeAllowlistParserTest extends TestCase
{
    private ComposeAllowlistParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ComposeAllowlistParser();
    }

    public function testParsesValidComposeAndIgnoresLocalDevPortsAndDevelop(): void
    {
        $yaml = <<<'YAML'
            services:
              web:
                build:
                  context: .
                  dockerfile: Dockerfile
                ports:
                  - "8000:80"
                develop:
                  watch:
                    - action: rebuild
                      path: ./composer.lock
                environment:
                  APP_ENV: dev
                volumes:
                  - ./:/app
                  - uploads:/app/uploads
                depends_on:
                  - db
              db:
                image: postgres:16-alpine
                volumes:
                  - db-data:/var/lib/postgresql/data
            volumes:
              uploads:
              db-data:
            YAML;

        $spec = $this->parser->parseComposeYaml($yaml, new DeployQuota());

        self::assertCount(2, $spec->services);
        self::assertTrue($spec->hasService('web'));
        self::assertTrue($spec->hasService('db'));
        self::assertSame(['db-data', 'uploads'], $spec->namedVolumes);

        $web = $spec->services['web'];
        self::assertNotNull($web->build);
        self::assertSame('.', $web->build->context);
        self::assertCount(2, $web->volumes);
        self::assertTrue($web->volumes[0]->isRelativeRepoPath);
        self::assertSame('.', $web->volumes[0]->source);
        self::assertSame('/app', $web->volumes[0]->target);
        self::assertFalse($web->volumes[1]->isRelativeRepoPath);
        self::assertSame('uploads', $web->volumes[1]->source);

        // Ensure info logs recorded ignored local-dev keys and converted relative bind-mount
        $joinedLogs = implode("\n", $spec->infoLogs);
        self::assertStringContainsString('ignoring local-dev option "ports"', $joinedLogs);
        self::assertStringContainsString('ignoring local-dev option "develop"', $joinedLogs);
        self::assertStringContainsString('converting relative bind-mount "./:/app"', $joinedLogs);
    }

    public function testRejectsPrivilegedAndUnknownOptions(): void
    {
        $yaml = <<<'YAML'
            services:
              pwn:
                image: alpine
                privileged: true
            YAML;

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('disallowed option "privileged"');
        $this->parser->parseComposeYaml($yaml, new DeployQuota());
    }

    public function testRejectsHostPathVolumes(): void
    {
        $yaml = <<<'YAML'
            services:
              pwn:
                image: alpine
                volumes:
                  - /var/run/docker.sock:/var/run/docker.sock
            YAML;

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('host path volume "/var/run/docker.sock" is forbidden');
        $this->parser->parseComposeYaml($yaml, new DeployQuota());
    }

    public function testRejectsParentDirectoryTraversalInVolumes(): void
    {
        $yaml = <<<'YAML'
            services:
              pwn:
                image: alpine
                volumes:
                  - ../secret:/app
            YAML;

        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('cannot traverse outside the repository');
        $this->parser->parseComposeYaml($yaml, new DeployQuota());
    }

    public function testEnforcesMaxServicesQuota(): void
    {
        $yaml = <<<'YAML'
            services:
              s1:
                image: alpine
              s2:
                image: alpine
              s3:
                image: alpine
            YAML;

        $quota = new DeployQuota(maxServices: 2);
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage('exceeding the quota limit of 2 services');
        $this->parser->parseComposeYaml($yaml, $quota);
    }

    public function testDomainSyntacticLintValidation(): void
    {
        self::assertSame('app.example.com', DeployDomain::normalizeDomain('APP.Example.com.'));

        try {
            DeployDomain::normalizeDomain('127.0.0.1');
            self::fail('Expected IP address to be rejected');
        } catch (InvalidInput $e) {
            self::assertStringContainsString('not an IP address', $e->getMessage());
        }

        try {
            DeployDomain::normalizeDomain('myapp.localhost');
            self::fail('Expected .localhost to be rejected');
        } catch (InvalidInput $e) {
            self::assertStringContainsString('reserved local/internal TLD', $e->getMessage());
        }
    }

    public function testEnvVarEncryptionRoundTrip(): void
    {
        $encryptor = new SodiumEnvVarEncryptor('test-secret-key');
        $cipher = $encryptor->encrypt('super-secret-db-password');

        self::assertNotSame('super-secret-db-password', $cipher);
        self::assertSame('super-secret-db-password', $encryptor->decrypt($cipher));
    }

    public function testGeneratedComposeEnforcesZeroTrustSecurityAndIsolatedGatewayPorts(): void
    {
        $yaml = <<<'YAML'
            services:
              web:
                image: nginx:alpine
              db:
                image: postgres:16-alpine
            YAML;

        $quota = new DeployQuota(cpus: 1.5, memoryMb: 512, pids: 128);
        $spec = $this->parser->parseComposeYaml($yaml, $quota);

        $now = new \DateTimeImmutable();
        $owner = new User('alice', false, $now);
        $repo = new Repo(RepoName::fromInput('app'), $owner, 'app', 'desc', false, false, false, null, $now);
        $this->setEntityId($repo, 10);

        $env = new DeployEnvironment($repo, 'main', DeployRefType::Branch, $now);
        $this->setEntityId($env, 25);

        $domain = new DeployDomain($env, 'app.example.com', 'web', 8080, $now);
        $this->setEntityId($domain, 42);

        $builder = new GeneratedComposeBuilder(
            dockerRuntime: 'runsc',
            deployGatewayHost: 'deploy-dockerd',
        );

        $generatedYaml = $builder->buildYaml(
            environment: $env,
            spec: $spec,
            quota: $quota,
            decryptedEnvVars: ['SECRET_KEY' => 'val'],
            exposedServices: ['web'],
        );

        /** @var array{networks: array<string, mixed>, services: array<string, array<string, mixed>>} $parsed */
        $parsed = Yaml::parse($generatedYaml);

        // 1. No shared proxy_net when isolated deploy gateway host is active
        self::assertArrayNotHasKey('proxy_net', $parsed['networks']);
        self::assertArrayHasKey('env_net', $parsed['networks']);

        /** @var array{networks: array<string, mixed>, ports: list<string>, runtime: string, restart: string, init: bool, security_opt: list<string>, cap_drop: list<string>, cap_add: list<string>, mem_limit: string, memswap_limit: string, pids_limit: int, logging: array{options: array<string, string>}} $web */
        $web = $parsed['services']['web'];
        self::assertArrayNotHasKey('proxy_net', $web['networks']);

        // 2. Deterministic gateway port published inside deploy-dockerd (10000 + 42 = 10042 -> 8080)
        self::assertSame(['10042:8080'], $web['ports']);
        self::assertArrayNotHasKey('ports', $parsed['services']['db']);

        // 3. gVisor runtime, cap_drop ALL, no-new-privileges, init, memswap_limit, and log rotation
        self::assertSame('runsc', $web['runtime']);
        self::assertTrue($web['init']);
        self::assertSame(['no-new-privileges:true'], $web['security_opt']);
        self::assertSame(['ALL'], $web['cap_drop']);
        self::assertContains('NET_BIND_SERVICE', $web['cap_add']);
        self::assertNotContains('NET_RAW', $web['cap_add']);
        self::assertNotContains('SYS_ADMIN', $web['cap_add']);
        self::assertSame('512m', $web['mem_limit']);
        self::assertSame('512m', $web['memswap_limit']);
        self::assertSame(128, $web['pids_limit']);
        self::assertSame('10m', $web['logging']['options']['max-size']);
        self::assertSame('on-failure:5', $web['restart']);
    }

    public function testSmartRestartPolicyCapsUnboundedRestartsToPreventCrashLoops(): void
    {
        $yaml = <<<'YAML'
            services:
              default_svc:
                image: alpine
              always_svc:
                image: alpine
                restart: always
              unless_stopped_svc:
                image: alpine
                restart: unless-stopped
              custom_retry_svc:
                image: alpine
                restart: on-failure:3
              high_retry_svc:
                image: alpine
                restart: on-failure:99
              oneshot_svc:
                image: alpine
                restart: "no"
            YAML;

        $spec = $this->parser->parseComposeYaml($yaml, new DeployQuota(maxServices: 6));

        self::assertSame('on-failure:5', $spec->services['default_svc']->restart);
        self::assertSame('on-failure:5', $spec->services['always_svc']->restart);
        self::assertSame('on-failure:5', $spec->services['unless_stopped_svc']->restart);
        self::assertSame('on-failure:3', $spec->services['custom_retry_svc']->restart);
        self::assertSame('on-failure:10', $spec->services['high_retry_svc']->restart);
        self::assertSame('no', $spec->services['oneshot_svc']->restart);

        $joinedLogs = implode("\n", $spec->infoLogs);
        self::assertStringContainsString('capping unbounded restart policy "always" to "on-failure:5"', $joinedLogs);
        self::assertStringContainsString('capping unbounded restart policy "unless-stopped" to "on-failure:5"', $joinedLogs);
        self::assertStringContainsString('capping restart policy "on-failure:99" to "on-failure:10"', $joinedLogs);
    }

    public function testMergesComposeOverrideYamlAndIncludesAdditionalServicesLikeMailer(): void
    {
        $baseYaml = <<<'YAML'
            services:
              php:
                build:
                  context: .
                  dockerfile: Dockerfile
                environment:
                  APP_ENV: dev
                  DATABASE_URL: postgresql://symfony:ChangeMe@database:5432/app
                volumes:
                  - ./:/var/www/html
                depends_on:
                  database:
                    condition: service_healthy
              nginx:
                image: nginx:alpine
                ports:
                  - "8080:80"
                depends_on:
                  - php
              database:
                image: postgres:13-alpine
                volumes:
                  - db-data:/var/lib/postgresql/data:rw
            volumes:
              db-data:
            YAML;

        $overrideYaml = <<<'YAML'
            services:
              php:
                environment:
                  MAILER_DSN: smtp://mailer:1025
              database:
                ports:
                  - "5432:5432"
              mailer:
                image: schickling/mailcatcher
                ports:
                  - "1025:1025"
                  - "1080:1080"
            YAML;

        $spec = $this->parser->parseComposeYaml($baseYaml, new DeployQuota(maxServices: 4), $overrideYaml);

        self::assertCount(4, $spec->services);
        self::assertTrue($spec->hasService('php'));
        self::assertTrue($spec->hasService('nginx'));
        self::assertTrue($spec->hasService('database'));
        self::assertTrue($spec->hasService('mailer'));

        self::assertSame('schickling/mailcatcher', $spec->services['mailer']->image);
        self::assertSame('dev', $spec->services['php']->environment['APP_ENV'] ?? null);
        self::assertSame('smtp://mailer:1025', $spec->services['php']->environment['MAILER_DSN'] ?? null);

        $joinedLogs = implode("\n", $spec->infoLogs);
        self::assertStringContainsString('Merged compose override file into deployment specification.', $joinedLogs);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity, 'id');
        $ref->setValue($entity, $id);
    }
}
