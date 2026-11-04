<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repo;

use App\Repo\Domain\Event\RepoCreated;
use App\Repo\Domain\RepoName;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\InvalidInput;

final class ImportRepoTest extends RepoUseCaseTestCase
{
    public function testRejectsLoopbackUrl(): void
    {
        $alice = $this->user('alice');

        try {
            ($this->importRepoUseCase())($alice, 'alice/mirror', 'http://127.0.0.1/evil.git');
            self::fail('expected InvalidInput');
        } catch (InvalidInput) {
            // expected
        }

        self::assertNull($this->repoStore->byName(RepoName::fromInput('alice/mirror')));
        self::assertFalse($this->storage->exists(RepoName::fromInput('alice/mirror')));
        self::assertCount(0, $this->eventsOf(RepoCreated::class));
    }

    public function testRejectsPrivateHostUrl(): void
    {
        $alice = $this->user('alice');
        $this->expectException(InvalidInput::class);
        ($this->importRepoUseCase())($alice, 'alice/mirror', 'http://10.0.0.5/x.git');
    }

    public function testRejectsNonHttpScheme(): void
    {
        $alice = $this->user('alice');
        $this->expectException(InvalidInput::class);
        ($this->importRepoUseCase())($alice, 'alice/mirror', 'file:///etc/passwd');
    }

    public function testAnonymousCannotImport(): void
    {
        // SSRF passes (literal public IP, no DNS needed) but an anonymous caller
        // cannot own a repository.
        $this->expectException(AccessDenied::class);
        ($this->importRepoUseCase())(null, 'nobody/mirror', 'https://93.184.216.34/repo.git');
    }
}
