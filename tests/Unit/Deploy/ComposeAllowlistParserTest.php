<?php

declare(strict_types=1);

namespace App\Tests\Unit\Deploy;

use App\Deploy\Application\ComposeAllowlistParser;
use App\Deploy\Domain\DeployQuota;
use App\Deploy\Domain\Model\DeployDomain;
use App\Deploy\Infrastructure\Crypto\SodiumEnvVarEncryptor;
use App\Shared\Domain\Exception\InvalidInput;
use PHPUnit\Framework\TestCase;

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
}
