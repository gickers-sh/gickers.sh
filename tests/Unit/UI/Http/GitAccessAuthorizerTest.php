<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Http;

use App\Access\Domain\AccessLevel;
use App\UI\Http\Security\GitAccessAuthorizer;
use App\UI\Http\Security\GitVerdict;
use PHPUnit\Framework\TestCase;

/**
 * Direct port of Go Soft Serve `pkg/web/authorize_test.go:TestAuthorize`.
 * Pins the decision order for every caller, route, and repo state.
 */
final class GitAccessAuthorizerTest extends TestCase
{
    /**
     * @return list<array{name: string, service: string, file: string, method: string}>
     */
    private function routes(): array
    {
        return [
            ['name' => 'fetch advert', 'service' => 'git-upload-pack', 'file' => 'info/refs', 'method' => 'GET'],
            ['name' => 'fetch rpc', 'service' => 'git-upload-pack', 'file' => 'git-upload-pack', 'method' => 'POST'],
            ['name' => 'push advert', 'service' => 'git-receive-pack', 'file' => 'info/refs', 'method' => 'GET'],
            ['name' => 'push rpc', 'service' => 'git-receive-pack', 'file' => 'git-receive-pack', 'method' => 'POST'],
            ['name' => 'dumb file', 'service' => '', 'file' => 'HEAD', 'method' => 'GET'],
            ['name' => 'lfs batch', 'service' => '', 'file' => 'info/lfs/objects/batch', 'method' => 'POST'],
            ['name' => 'lfs upload', 'service' => '', 'file' => 'info/lfs/objects/basic/00', 'method' => 'PUT'],
            ['name' => 'lfs lock', 'service' => '', 'file' => 'info/lfs/locks', 'method' => 'POST'],
        ];
    }

    public function testMissingAndPrivateAreIndistinguishable(): void
    {
        $callers = [
            ['name' => 'anonymous', 'anonymous' => true, 'badCreds' => false, 'missing' => AccessLevel::ReadOnlyAccess],
            ['name' => 'anonymous no-access', 'anonymous' => true, 'badCreds' => false, 'missing' => AccessLevel::NoAccess],
            ['name' => 'bad credentials', 'anonymous' => true, 'badCreds' => true, 'missing' => AccessLevel::ReadOnlyAccess],
            ['name' => 'signed-in', 'anonymous' => false, 'badCreds' => false, 'missing' => AccessLevel::ReadWriteAccess],
        ];

        foreach ($callers as $c) {
            foreach ($this->routes() as $rt) {
                $private = GitAccessAuthorizer::authorize($rt['service'], $rt['file'], $rt['method'], false, AccessLevel::NoAccess, $c['anonymous'], $c['badCreds']);
                $missing = GitAccessAuthorizer::authorize($rt['service'], $rt['file'], $rt['method'], false, $c['missing'], $c['anonymous'], $c['badCreds']);
                $isLfs = str_starts_with($rt['file'], 'info/lfs');

                $want = GitVerdict::DenyUnreadable;
                if ($c['badCreds'] && !$isLfs) {
                    $want = GitVerdict::Forbid;
                } elseif ('git-receive-pack' === $rt['service'] && !$c['anonymous'] && \in_array($rt['file'], ['info/refs', 'git-receive-pack'], true)) {
                    $this->assertSame(GitVerdict::CreateThenServe, $missing, sprintf('%s/%s missing', $c['name'], $rt['name']));
                    $this->assertSame(GitVerdict::DenyUnreadable, $private, sprintf('%s/%s private', $c['name'], $rt['name']));
                    continue;
                }

                $this->assertSame($want, $private, sprintf('%s/%s private', $c['name'], $rt['name']));
                $this->assertSame($want, $missing, sprintf('%s/%s missing', $c['name'], $rt['name']));
            }
        }
    }

    public function testExistingRepositoryPermissions(): void
    {
        $routes = $this->routes();
        $cases = [
            ['name' => 'anon reader fetch', 'route' => $routes[0], 'level' => AccessLevel::ReadOnlyAccess, 'anonymous' => true, 'want' => GitVerdict::Serve],
            ['name' => 'anon reader push', 'route' => $routes[2], 'level' => AccessLevel::ReadOnlyAccess, 'anonymous' => true, 'want' => GitVerdict::Unauthorized],
            ['name' => 'anon reader push rpc', 'route' => $routes[3], 'level' => AccessLevel::ReadOnlyAccess, 'anonymous' => true, 'want' => GitVerdict::Unauthorized],
            ['name' => 'anon reader dumb', 'route' => $routes[4], 'level' => AccessLevel::ReadOnlyAccess, 'anonymous' => true, 'want' => GitVerdict::Serve],
            ['name' => 'anon reader lfs batch', 'route' => $routes[5], 'level' => AccessLevel::ReadOnlyAccess, 'anonymous' => true, 'want' => GitVerdict::Serve],
            ['name' => 'anon reader lfs upload', 'route' => $routes[6], 'level' => AccessLevel::ReadOnlyAccess, 'anonymous' => true, 'want' => GitVerdict::ForbidLfsWrite],
            ['name' => 'anon reader lfs lock', 'route' => $routes[7], 'level' => AccessLevel::ReadOnlyAccess, 'anonymous' => true, 'want' => GitVerdict::ForbidLfsWrite],
            ['name' => 'reader fetch', 'route' => $routes[0], 'level' => AccessLevel::ReadOnlyAccess, 'anonymous' => false, 'want' => GitVerdict::Serve],
            ['name' => 'reader push', 'route' => $routes[2], 'level' => AccessLevel::ReadOnlyAccess, 'anonymous' => false, 'want' => GitVerdict::Forbid],
            ['name' => 'reader push rpc', 'route' => $routes[3], 'level' => AccessLevel::ReadOnlyAccess, 'anonymous' => false, 'want' => GitVerdict::Forbid],
            ['name' => 'reader lfs upload', 'route' => $routes[6], 'level' => AccessLevel::ReadOnlyAccess, 'anonymous' => false, 'want' => GitVerdict::ForbidLfsWrite],
            ['name' => 'writer push', 'route' => $routes[2], 'level' => AccessLevel::ReadWriteAccess, 'anonymous' => false, 'want' => GitVerdict::Serve],
            ['name' => 'writer push rpc', 'route' => $routes[3], 'level' => AccessLevel::ReadWriteAccess, 'anonymous' => false, 'want' => GitVerdict::Serve],
            ['name' => 'writer lfs upload', 'route' => $routes[6], 'level' => AccessLevel::ReadWriteAccess, 'anonymous' => false, 'want' => GitVerdict::Serve],
            ['name' => 'writer lfs lock', 'route' => $routes[7], 'level' => AccessLevel::ReadWriteAccess, 'anonymous' => false, 'want' => GitVerdict::Serve],
            ['name' => 'anon writer push', 'route' => $routes[2], 'level' => AccessLevel::ReadWriteAccess, 'anonymous' => true, 'want' => GitVerdict::Serve],
        ];

        foreach ($cases as $c) {
            $got = GitAccessAuthorizer::authorize(
                $c['route']['service'],
                $c['route']['file'],
                $c['route']['method'],
                true,
                $c['level'],
                $c['anonymous'],
                false,
            );
            $this->assertSame($c['want'], $got, $c['name']);
        }
    }

    public function testBadCredentialsRefused(): void
    {
        foreach ($this->routes() as $rt) {
            $got = GitAccessAuthorizer::authorize($rt['service'], $rt['file'], $rt['method'], true, AccessLevel::ReadOnlyAccess, true, true);
            $this->assertSame(GitVerdict::Forbid, $got, 'bad creds on readable '.$rt['name']);

            $isLfs = str_starts_with($rt['file'], 'info/lfs');
            $want = $isLfs ? GitVerdict::DenyUnreadable : GitVerdict::Forbid;
            $gotMissing = GitAccessAuthorizer::authorize($rt['service'], $rt['file'], $rt['method'], false, AccessLevel::ReadWriteAccess, true, true);
            $this->assertSame($want, $gotMissing, 'bad creds on missing '.$rt['name']);
        }
    }

    public function testPushMayOnlyCreateFromPushEndpoints(): void
    {
        $got = GitAccessAuthorizer::authorize('git-receive-pack', 'HEAD', 'GET', false, AccessLevel::ReadWriteAccess, false, false);
        $this->assertSame(GitVerdict::DenyUnreadable, $got);
    }
}
