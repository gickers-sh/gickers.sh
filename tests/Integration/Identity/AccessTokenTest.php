<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\AuthenticateAccessToken;
use App\Identity\Application\IssueAccessToken;
use App\Identity\Application\RevokeAccessToken;
use App\Identity\Domain\AccessTokenStore;
use App\Identity\Domain\Model\AccessToken;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Shared\Domain\Exception\AccessDenied;
use App\Shared\Domain\Exception\NotFound;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AccessTokenTest extends KernelTestCase
{
    private UserStore $userStore;
    private AccessTokenStore $tokenStore;
    private IssueAccessToken $issueToken;
    private RevokeAccessToken $revokeToken;
    private AuthenticateAccessToken $authToken;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->userStore = $container->get(UserStore::class);
        $this->tokenStore = $container->get(AccessTokenStore::class);
        $this->issueToken = $container->get('test.issue_access_token');
        $this->revokeToken = $container->get('test.revoke_access_token');
        $this->authToken = $container->get('test.authenticate_access_token');
        $this->em = $container->get(EntityManagerInterface::class);
    }

    public function testTokenLifecycle(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User('alice', false, $now);
        $this->userStore->save($user);

        // 1. Issue access token
        [$token, $secret] = ($this->issueToken)($user, 'cli-token', null);

        $this->assertInstanceOf(AccessToken::class, $token);
        $this->assertStringStartsWith('ss_', $secret);
        $this->assertSame(43, \strlen($secret)); // 'ss_' + 40 hex chars
        $this->assertSame('cli-token', $token->name());
        $this->assertNull($token->expiresAt());
        $this->assertNull($token->lastUsedAt());

        // 2. Verify only hash is stored in DB, not secret
        $conn = $this->em->getConnection();
        $row = $conn->fetchAssociative('SELECT * FROM user_access_tokens WHERE id = ?', [$token->id()]);
        $this->assertNotFalse($row);
        $this->assertSame(hash('sha256', $secret), $row['token_hash']);
        $this->assertStringNotContainsString($secret, json_encode($row, \JSON_THROW_ON_ERROR));

        // 3. Authenticate with valid secret
        $authUser = ($this->authToken)($secret);
        $this->assertNotNull($authUser);
        $this->assertSame($user->id(), $authUser->id());

        // Reload token to check lastUsedAt was updated
        $this->em->clear();
        $reloadedToken = $this->tokenStore->byHash(hash('sha256', $secret));
        $this->assertNotNull($reloadedToken);
        $this->assertNotNull($reloadedToken->lastUsedAt());

        // 4. Authenticate with invalid secret
        $invalid = ($this->authToken)('ss_invalid_secret_that_does_not_exist');
        $this->assertNull($invalid);

        // 5. Revoke token
        $reloadedUser = $this->userStore->byId($user->id());
        $this->assertNotNull($reloadedUser);
        ($this->revokeToken)($reloadedUser, $token->id());

        // Authentication fails after revocation
        $revokedAuth = ($this->authToken)($secret);
        $this->assertNull($revokedAuth);

        // Revoking non-existent token throws NotFound
        $this->expectException(NotFound::class);
        $this->expectExceptionMessage('access token not found');
        ($this->revokeToken)($reloadedUser, $token->id());
    }

    public function testExpiredTokenThrowsAccessDenied(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User('bob', false, $now);
        $this->userStore->save($user);

        // Issue already-expired token
        $expiredAt = $now->modify('-1 hour');
        [$token, $secret] = ($this->issueToken)($user, 'expired-token', $expiredAt);

        $this->expectException(AccessDenied::class);
        $this->expectExceptionMessage('token expired');
        ($this->authToken)($secret);
    }

    public function testCannotRevokeAnotherUsersToken(): void
    {
        $now = new \DateTimeImmutable();
        $alice = new User('alice2', false, $now);
        $bob = new User('bob2', false, $now);
        $this->userStore->save($alice);
        $this->userStore->save($bob);

        [$token] = ($this->issueToken)($alice, 'alice-token', null);

        // Bob tries to revoke Alice's token
        $this->expectException(NotFound::class);
        $this->expectExceptionMessage('access token not found');
        ($this->revokeToken)($bob, $token->id());
    }
}
