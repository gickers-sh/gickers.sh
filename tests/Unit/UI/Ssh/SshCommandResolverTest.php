<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Ssh;

use App\UI\Ssh\SshCommandResolver;
use PHPUnit\Framework\TestCase;

final class SshCommandResolverTest extends TestCase
{
    private SshCommandResolver $resolver;

    protected function setUp(): void
    {
        $canonical = [
            'repo:create',
            'repo:delete',
            'repo:rename',
            'repo:list',
            'repo:info',
            'repo:tree',
            'repo:blob',
            'repo:branch:list',
            'repo:branch:default',
            'repo:branch:delete',
            'repo:tag:list',
            'repo:tag:delete',
            'repo:collab:add',
            'repo:collab:remove',
            'repo:collab:list',
            'repo:webhook:create',
            'pr:list',
            'pr:merge',
            'user:create',
            'user:list',
            'info',
        ];

        $aliases = [
            'repo:branch:delete' => ['repo:branch:rm', 'repo:branch:del', 'repo:branch:remove'],
            'repo:branch:list' => ['repo:branch:ls'],
            'repo:tag:list' => ['repo:tag:ls'],
            'repo:list' => ['repo:ls'],
            'repo:blob' => ['repo:cat', 'repo:show'],
            'repo:delete' => ['repo:rm', 'repo:del'],
            'repo:rename' => ['repo:mv'],
            'repo:collab:remove' => ['repo:collab:rm', 'repo:collab:del'],
        ];

        $this->resolver = new SshCommandResolver($canonical, $aliases);
    }

    public function testLongestPrefixMatching(): void
    {
        $res = $this->resolver->resolve(['repo', 'branch', 'delete', 'demo', 'feature']);
        $this->assertNotNull($res);
        $this->assertSame('repo:branch:delete', $res->name);
        $this->assertSame(['demo', 'feature'], $res->arguments);
    }

    public function testLeafAliases(): void
    {
        $res1 = $this->resolver->resolve(['repo', 'branch', 'rm', 'demo', 'feature']);
        $this->assertNotNull($res1);
        $this->assertSame('repo:branch:delete', $res1->name);
        $this->assertSame(['demo', 'feature'], $res1->arguments);

        $res2 = $this->resolver->resolve(['repo', 'ls', '-a']);
        $this->assertNotNull($res2);
        $this->assertSame('repo:list', $res2->name);
        $this->assertSame(['-a'], $res2->arguments);

        $res3 = $this->resolver->resolve(['repo', 'cat', 'demo', 'README.md']);
        $this->assertNotNull($res3);
        $this->assertSame('repo:blob', $res3->name);
        $this->assertSame(['demo', 'README.md'], $res3->arguments);
    }

    public function testTopLevelCollabPromotion(): void
    {
        $res = $this->resolver->resolve(['collab', 'add', 'demo', 'alice', 'read-write']);
        $this->assertNotNull($res);
        $this->assertSame('repo:collab:add', $res->name);
        $this->assertSame(['demo', 'alice', 'read-write'], $res->arguments);
    }

    public function testTopLevelWebhookPromotion(): void
    {
        $res = $this->resolver->resolve(['webhook', 'create', 'demo', 'http://127.0.0.1']);
        $this->assertNotNull($res);
        $this->assertSame('repo:webhook:create', $res->name);
        $this->assertSame(['demo', 'http://127.0.0.1'], $res->arguments);
    }

    public function testRepoPrPromotion(): void
    {
        $res = $this->resolver->resolve(['repo', 'pr', 'list', 'demo']);
        $this->assertNotNull($res);
        $this->assertSame('pr:list', $res->name);
        $this->assertSame(['demo'], $res->arguments);
    }

    public function testDirectBranchAndTagShortcuts(): void
    {
        $resBranch = $this->resolver->resolve(['repo', 'branch', 'demo']);
        $this->assertNotNull($resBranch);
        $this->assertSame('repo:branch:list', $resBranch->name);
        $this->assertSame(['demo'], $resBranch->arguments);

        $resTag = $this->resolver->resolve(['repo', 'tag', 'demo']);
        $this->assertNotNull($resTag);
        $this->assertSame('repo:tag:list', $resTag->name);
        $this->assertSame(['demo'], $resTag->arguments);
    }

    public function testUnknownCommandReturnsNull(): void
    {
        $res = $this->resolver->resolve(['nonexistent', 'command']);
        $this->assertNull($res);
    }

    public function testEmptyTokensReturnsNull(): void
    {
        $this->assertNull($this->resolver->resolve([]));
    }
}
