<?php

declare(strict_types=1);

namespace App\Tests\Functional\Wiki;

use App\Identity\Application\BootstrapServer;
use App\Identity\Application\IssueAccessToken;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\UserStore;
use App\Identity\Infrastructure\WebAuth\WebSessionTokens;
use App\UI\Tui\TuiAppFactory;
use App\Wiki\Application\Query\WikiQuery;
use App\Wiki\Application\SaveWikiPage;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class WikiFeatureTest extends KernelTestCase
{
    private User $admin;
    private User $user;
    private WebSessionTokens $webTokens;
    private IssueAccessToken $issueToken;
    private SaveWikiPage $saveWikiPage;
    private WikiQuery $wikiQuery;
    private TuiAppFactory $tuiApps;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();

        $userStore = $c->get(UserStore::class);
        $this->admin = new User('wiki-admin-'.bin2hex(random_bytes(3)), true, new \DateTimeImmutable());
        $userStore->save($this->admin);

        $this->user = new User('wiki-user-'.bin2hex(random_bytes(3)), false, new \DateTimeImmutable());
        $userStore->save($this->user);

        $this->webTokens = $c->get(WebSessionTokens::class);
        $this->issueToken = $c->get(IssueAccessToken::class);
        $this->saveWikiPage = $c->get(SaveWikiPage::class);
        $this->wikiQuery = $c->get(WikiQuery::class);
        $this->tuiApps = $c->get(TuiAppFactory::class);
    }

    private function dispatchSsh(string $commandLine, ?User $actor = null): CommandTester
    {
        self::assertNotNull(self::$kernel);
        $target = $actor ?? $this->admin;
        [, $secret] = ($this->issueToken)($target, 'ssh-tok-'.bin2hex(random_bytes(2)));

        $tester = new CommandTester((new Application(self::$kernel))->find('soft:ssh:dispatch'));
        $tester->execute(
            ['original-command' => $commandLine, '--token' => $secret],
            ['capture_stderr_separately' => true],
        );

        return $tester;
    }

    private function handleHttp(Request $request): Response
    {
        $kernel = self::$kernel ?? throw new \LogicException('Kernel not booted');
        $response = $kernel->handle($request);
        if ($kernel instanceof \Symfony\Component\HttpKernel\TerminableInterface) {
            $kernel->terminate($request, $response);
        }

        return $response;
    }

    public function testSshCliWikiSetListShowSearchAndDelete(): void
    {
        // Non-admin cannot create or edit wiki pages
        $forbidden = $this->dispatchSsh('wiki set forbidden-page "# Forbidden"', $this->user);
        self::assertNotSame(Command::SUCCESS, $forbidden->getStatusCode());

        // Admin creates a wiki page via SSH CLI
        $create = $this->dispatchSsh(
            'wiki set getting-started "# Getting Started\n\nInstall and connect to codes.sh over SSH." -t "Getting Started" -c "Guides" -p 1',
            $this->admin,
        );
        self::assertSame(Command::SUCCESS, $create->getStatusCode());

        // Regular user can list wiki pages
        $list = $this->dispatchSsh('wiki list', $this->user);
        self::assertSame(Command::SUCCESS, $list->getStatusCode());
        self::assertStringContainsString("getting-started\tGuides\tGetting Started", $list->getDisplay());

        // Regular user can read wiki page (--raw and rendered) and via shortcut `wiki <slug>`
        $showRaw = $this->dispatchSsh('wiki show getting-started --raw', $this->user);
        self::assertSame(Command::SUCCESS, $showRaw->getStatusCode());
        self::assertStringContainsString('# Getting Started', $showRaw->getDisplay());

        $showShortcut = $this->dispatchSsh('wiki getting-started', $this->user);
        self::assertSame(Command::SUCCESS, $showShortcut->getStatusCode());
        self::assertStringContainsString('Getting Started', $showShortcut->getDisplay());

        // Search finds the page
        $search = $this->dispatchSsh('wiki search "connect to codes.sh"', $this->user);
        self::assertSame(Command::SUCCESS, $search->getStatusCode());
        self::assertStringContainsString("getting-started\tGuides\tGetting Started", $search->getDisplay());

        // Non-admin cannot delete wiki page
        $delForbidden = $this->dispatchSsh('wiki delete getting-started', $this->user);
        self::assertNotSame(Command::SUCCESS, $delForbidden->getStatusCode());

        // Admin deletes wiki page
        $del = $this->dispatchSsh('wiki delete getting-started', $this->admin);
        self::assertSame(Command::SUCCESS, $del->getStatusCode());
        self::assertNull($this->wikiQuery->find('getting-started'));
    }

    public function testWebWikiPublicReadAndAdminOnlyAdministration(): void
    {
        ($this->saveWikiPage)(
            $this->admin,
            'home',
            'Welcome to the Wiki',
            "# Server Wiki\n\nDocumentation for **codes.sh**.",
            'Overview',
            0,
        );

        // 1. Anonymous user can view /wiki and /wiki/home, without admin controls
        $anonRes = $this->handleHttp(Request::create('/wiki', 'GET'));
        self::assertSame(200, $anonRes->getStatusCode());
        $anonHtml = (string) $anonRes->getContent();
        self::assertStringContainsString('Welcome to the Wiki', $anonHtml);
        self::assertStringContainsString('Documentation for <strong>codes.sh</strong>.', $anonHtml);
        self::assertStringNotContainsString('+ New Page', $anonHtml);
        self::assertStringNotContainsString('Edit Page', $anonHtml);

        // 2. Anonymous and non-admin users are rejected on /wiki/new, /wiki/edit/home, /wiki/save, /wiki/delete/home
        $anonNew = $this->handleHttp(Request::create('/wiki/new', 'GET'));
        self::assertSame(401, $anonNew->getStatusCode());

        $userCookie = [WebSessionTokens::COOKIE_NAME => $this->webTokens->issueSession($this->user)];
        $userNew = $this->handleHttp(Request::create('/wiki/new', 'GET', [], $userCookie));
        self::assertSame(404, $userNew->getStatusCode());

        $userSave = $this->handleHttp(Request::create('/wiki/save', 'POST', [
            'slug' => 'hacked',
            'title' => 'Hacked',
            'content' => '# Hacked',
        ], $userCookie));
        self::assertSame(404, $userSave->getStatusCode());

        // 3. Admin user sees admin controls, can open /wiki/new, save a new page, edit it, and delete it
        $adminCookieVal = $this->webTokens->issueSession($this->admin);
        $adminCookie = [WebSessionTokens::COOKIE_NAME => $adminCookieVal];

        $adminIndex = $this->handleHttp(Request::create('/wiki', 'GET', [], $adminCookie));
        self::assertSame(200, $adminIndex->getStatusCode());
        self::assertStringContainsString('+ New Page', (string) $adminIndex->getContent());
        self::assertStringContainsString('Edit Page', (string) $adminIndex->getContent());

        $newFormRes = $this->handleHttp(Request::create('/wiki/new', 'GET', [], $adminCookie));
        self::assertSame(200, $newFormRes->getStatusCode());
        $newFormHtml = (string) $newFormRes->getContent();
        self::assertSame(1, preg_match('/name="_token" value="([a-f0-9]{64})"/', $newFormHtml, $m));
        $csrf = $m[1] ?? '';

        $saveRes = $this->handleHttp(Request::create('/wiki/save', 'POST', [
            '_token' => $csrf,
            'slug' => 'architecture',
            'title' => 'System Architecture',
            'category' => 'Internals',
            'position' => '2',
            'content' => "# System Architecture\n\nBuilt with Symfony & Go.",
        ], $adminCookie));
        self::assertSame(Response::HTTP_SEE_OTHER, $saveRes->getStatusCode());
        self::assertSame('/wiki/architecture', $saveRes->headers->get('Location'));

        $showArch = $this->handleHttp(Request::create('/wiki/architecture', 'GET'));
        self::assertSame(200, $showArch->getStatusCode());
        self::assertStringContainsString('Built with Symfony &amp; Go.', (string) $showArch->getContent());

        // Search over web
        $searchRes = $this->handleHttp(Request::create('/wiki?q=Symfony', 'GET'));
        self::assertSame(200, $searchRes->getStatusCode());
        self::assertStringContainsString('System Architecture', (string) $searchRes->getContent());

        // Delete page as admin
        $delRes = $this->handleHttp(Request::create('/wiki/delete/architecture', 'POST', [
            '_token' => $csrf,
        ], $adminCookie));
        self::assertSame(Response::HTTP_SEE_OTHER, $delRes->getStatusCode());
        self::assertNull($this->wikiQuery->find('architecture'));
    }

    public function testTuiWikiTabListsFiltersAndOpensWikiPage(): void
    {
        ($this->saveWikiPage)(
            $this->admin,
            'tui-guide',
            'Terminal UI Guide',
            "# Terminal UI Guide\n\nNavigate repositories and wiki pages with ease.",
            'Guides',
            -1,
        );

        $vt = new VirtualTerminal(90, 24);
        $app = $this->tuiApps->create($vt, $this->user, true);
        $app->start($app->views()->selection());
        $tui = $app->tui();

        $tui->start();
        $initial = $this->tickUntilSettled($tui, $vt);
        self::assertStringContainsString('Wiki', $initial);

        // Cycle tabs: About (0) -> Feed (1) -> My Repositories (2) -> Profile (3) -> Wiki (4)
        $vt->simulateInput("\t\t\t\t");
        $wikiListOut = $this->tickUntilSettled($tui, $vt);
        $plainList = (string) preg_replace('/\e\[[0-9;]*m/', '', $wikiListOut);
        self::assertStringContainsString('Terminal UI Guide', $plainList);
        self::assertStringContainsString('[Guides]', $plainList);
        self::assertStringContainsString('(tui-guide)', $plainList);

        // Press Enter to open the WikiPageView and render the Markdown document
        $vt->simulateInput("\r");
        $pageOut = $this->tickUntilSettled($tui, $vt);
        $plainPage = (string) preg_replace('/\e\[[0-9;]*m/', '', $pageOut);
        self::assertStringContainsString('Wiki / Guides / Terminal UI Guide', $plainPage);
        self::assertStringContainsString('Navigate repositories and wiki pages with ease.', $plainPage);

        // Press Esc to go back to the Wiki tab list
        $vt->simulateInput("\e");
        $backOut = $this->tickUntilSettled($tui, $vt);
        $plainBack = (string) preg_replace('/\e\[[0-9;]*m/', '', $backOut);
        self::assertStringContainsString('Terminal UI Guide', $plainBack);

        $tui->stop();
    }

    public function testBootstrapSeedsCompleteSignupWikiPage(): void
    {
        $bootstrap = static::getContainer()->get(BootstrapServer::class);
        ($bootstrap)();

        $page = $this->wikiQuery->find('signup');
        self::assertNotNull($page);
        self::assertSame('Self-Service SSH Signup & Account Registration', $page->title());
        self::assertSame('Signup', $page->category());
        self::assertStringContainsString('ssh codes.sh signup alice', $page->content());
        self::assertStringContainsString('ssh alice@codes.sh signup', $page->content());
        self::assertStringContainsString('settings allow-signup', $page->content());

        $show = $this->dispatchSsh('wiki show signup --raw', $this->user);
        self::assertSame(Command::SUCCESS, $show->getStatusCode());
        self::assertStringContainsString('# Self-Service SSH Signup & Account Registration', $show->getDisplay());

        $webRes = $this->handleHttp(Request::create('/wiki/signup', 'GET'));
        self::assertSame(200, $webRes->getStatusCode());
        self::assertStringContainsString('Self-Service SSH Signup &amp; Account Registration', (string) $webRes->getContent());

        $sshKeysPage = $this->wikiQuery->find('ssh-keys');
        self::assertNotNull($sshKeysPage);
        self::assertSame('SSH Keys & Permanent Agent Storage (ssh-add & Keychain)', $sshKeysPage->title());
        self::assertSame('Login', $sshKeysPage->category());
        self::assertStringContainsString('ssh-add ~/.ssh/id_ed25519', $sshKeysPage->content());
        self::assertStringContainsString('UseKeychain yes', $sshKeysPage->content());
        self::assertStringContainsString('AddKeysToAgent yes', $sshKeysPage->content());
        self::assertStringContainsString('keychain', $sshKeysPage->content());

        $showSshKeys = $this->dispatchSsh('wiki show ssh-keys --raw', $this->user);
        self::assertSame(Command::SUCCESS, $showSshKeys->getStatusCode());
        self::assertStringContainsString('# SSH Key Management & Permanent Local Storage', $showSshKeys->getDisplay());

        $homePage = $this->wikiQuery->find('home');
        self::assertNotNull($homePage);
        self::assertSame('codes.sh Server Documentation & Knowledge Base', $homePage->title());
        self::assertSame('Overview', $homePage->category());

        $deployPage = $this->wikiQuery->find('deploy-guide');
        self::assertNotNull($deployPage);
        self::assertSame('Deploy', $deployPage->category());

        $collabPage = $this->wikiQuery->find('collaboration');
        self::assertNotNull($collabPage);
        self::assertSame('Collab', $collabPage->category());

        $repoPage = $this->wikiQuery->find('repo-management');
        self::assertNotNull($repoPage);
        self::assertSame('Repo', $repoPage->category());

        $webSshKeys = $this->handleHttp(Request::create('/wiki/ssh-keys', 'GET'));
        self::assertSame(200, $webSshKeys->getStatusCode());
        self::assertStringContainsString('SSH Keys &amp; Permanent Agent Storage', (string) $webSshKeys->getContent());
        self::assertStringContainsString('wiki-pager', (string) $webSshKeys->getContent());
    }

    private function tickUntilSettled(Tui $tui, VirtualTerminal $vt, int $maxTicks = 120): string
    {
        $tui->tick();
        $out = $vt->consumeOutput();
        $emptyStreak = 0;
        for ($i = 0; $i < $maxTicks; ++$i) {
            usleep(10000);
            $tui->tick();
            $chunk = $vt->consumeOutput();
            if ('' !== $chunk) {
                $out .= $chunk;
                $emptyStreak = 0;
                continue;
            }
            if (++$emptyStreak >= 8) {
                break;
            }
        }

        return $out;
    }
}
