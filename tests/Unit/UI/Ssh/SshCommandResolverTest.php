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
            'repo:grep',
            'repo:readme',
            'repo:diff',
            'repo:branch:list',
            'repo:branch:default',
            'repo:branch:delete',
            'repo:tag:list',
            'repo:tag:delete',
            'repo:collab:add',
            'repo:collab:remove',
            'repo:collab:list',
            'repo:webhook:create',
            'repo:webhook:list',
            'user:create',
            'user:list',
            'pubkey:list',
            'token:list',
            'info',
            'about',
        ];

        $aliases = [
            'repo:branch:delete' => ['repo:branch:rm', 'repo:branch:del', 'repo:branch:remove'],
            'repo:branch:list' => ['repo:branch:ls'],
            'repo:tag:list' => ['repo:tag:ls'],
            'repo:list' => ['repo:ls'],
            'repo:blob' => ['repo:cat', 'repo:show'],
            'repo:grep' => ['repo:search'],
            'repo:delete' => ['repo:rm', 'repo:del'],
            'repo:rename' => ['repo:mv'],
            'repo:collab:remove' => ['repo:collab:rm', 'repo:collab:del'],
        ];

        $this->resolver = new SshCommandResolver($canonical, $aliases);
    }

    public function testResolveAboutCommand(): void
    {
        $res = $this->resolver->resolve(['about']);
        $this->assertNotNull($res);
        $this->assertSame('about', $res->name);
        $this->assertSame([], $res->arguments);

        $resWithArgs = $this->resolver->resolve(['about', '--width', '100']);
        $this->assertNotNull($resWithArgs);
        $this->assertSame('about', $resWithArgs->name);
        $this->assertSame(['--width', '100'], $resWithArgs->arguments);
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

    public function testGrepSearchAliasReadmeAndDiff(): void
    {
        $grep = $this->resolver->resolve(['repo', 'grep', 'demo', 'needle', 'main']);
        $this->assertNotNull($grep);
        $this->assertSame('repo:grep', $grep->name);
        $this->assertSame(['demo', 'needle', 'main'], $grep->arguments);

        $search = $this->resolver->resolve(['repo', 'search', 'demo', 'needle']);
        $this->assertNotNull($search);
        $this->assertSame('repo:grep', $search->name);
        $this->assertSame(['demo', 'needle'], $search->arguments);

        $readme = $this->resolver->resolve(['repo', 'readme', 'demo', 'main']);
        $this->assertNotNull($readme);
        $this->assertSame('repo:readme', $readme->name);
        $this->assertSame(['demo', 'main'], $readme->arguments);

        $diff = $this->resolver->resolve(['repo', 'diff', 'demo', 'v1', 'v2']);
        $this->assertNotNull($diff);
        $this->assertSame('repo:diff', $diff->name);
        $this->assertSame(['demo', 'v1', 'v2'], $diff->arguments);
    }

    public function testGroupAliases(): void
    {
        // repo <- repos / repository / repositories
        $r1 = $this->resolver->resolve(['repos', 'list']);
        $this->assertNotNull($r1);
        $this->assertSame('repo:list', $r1->name);

        $r2 = $this->resolver->resolve(['repository', 'list']);
        $this->assertNotNull($r2);
        $this->assertSame('repo:list', $r2->name);

        $r3 = $this->resolver->resolve(['repositories', 'list']);
        $this->assertNotNull($r3);
        $this->assertSame('repo:list', $r3->name);

        // collab <- collabs / collaborator / collaborators
        $c1 = $this->resolver->resolve(['collabs', 'list', 'demo']);
        $this->assertNotNull($c1);
        $this->assertSame('repo:collab:list', $c1->name);

        $c2 = $this->resolver->resolve(['collaborator', 'list', 'demo']);
        $this->assertNotNull($c2);
        $this->assertSame('repo:collab:list', $c2->name);

        $c3 = $this->resolver->resolve(['collaborators', 'list', 'demo']);
        $this->assertNotNull($c3);
        $this->assertSame('repo:collab:list', $c3->name);

        // pubkey <- pubkeys / publickey / publickeys
        $pk1 = $this->resolver->resolve(['pubkeys', 'list']);
        $this->assertNotNull($pk1);
        $this->assertSame('pubkey:list', $pk1->name);

        $pk2 = $this->resolver->resolve(['publickey', 'list']);
        $this->assertNotNull($pk2);
        $this->assertSame('pubkey:list', $pk2->name);

        $pk3 = $this->resolver->resolve(['publickeys', 'list']);
        $this->assertNotNull($pk3);
        $this->assertSame('pubkey:list', $pk3->name);

        // token <- access-token
        $tok = $this->resolver->resolve(['access-token', 'list']);
        $this->assertNotNull($tok);
        $this->assertSame('token:list', $tok->name);

        // user <- users
        $u = $this->resolver->resolve(['users', 'list']);
        $this->assertNotNull($u);
        $this->assertSame('user:list', $u->name);
        $wh1 = $this->resolver->resolve(['webhook', 'list', 'demo']);
        $this->assertNotNull($wh1);
        $this->assertSame('repo:webhook:list', $wh1->name);

        $wh2 = $this->resolver->resolve(['repo', 'webhooks', 'list', 'demo']);
        $this->assertNotNull($wh2);
        $this->assertSame('repo:webhook:list', $wh2->name);
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
