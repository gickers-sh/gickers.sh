<?php

declare(strict_types=1);

namespace App\Tests\Tui\Keymap;

use App\Repo\Infrastructure\Git\GitRepositoryReader;
use App\UI\Tui\Page\RepoPage;
use App\UI\Tui\Page\SelectionPage;
use App\UI\Tui\Render\TermstrapRenderer;
use CodesSh\Tui\Component\CodeViewerComponent;
use CodesSh\Tui\Component\EditorComponent;
use CodesSh\Tui\Component\Field\TextInputField;
use CodesSh\Tui\Component\Field\ToggleField;
use CodesSh\Tui\Component\FormComponent;
use CodesSh\Tui\Component\SelectorComponent;
use CodesSh\Tui\Event\KeyMsg;
use CodesSh\Tui\Keymap\KeyMap;
use CodesSh\Tui\Runtime\InputParser;
use PHPUnit\Framework\TestCase;

final class ShortcutsTest extends TestCase
{
    public function testKeyMapBindings(): void
    {
        $km = KeyMap::default();

        $this->assertTrue($km->quit->matches(new KeyMsg('q')));
        $this->assertTrue($km->quit->matches(new KeyMsg('ctrl+c')));

        $this->assertTrue($km->up->matches(new KeyMsg(KeyMsg::UP)));
        $this->assertTrue($km->up->matches(new KeyMsg('k')));

        $this->assertTrue($km->down->matches(new KeyMsg(KeyMsg::DOWN)));
        $this->assertTrue($km->down->matches(new KeyMsg('j')));

        $this->assertTrue($km->gotoTop->matches(new KeyMsg('g')));
        $this->assertTrue($km->gotoTop->matches(new KeyMsg(KeyMsg::HOME)));

        $this->assertTrue($km->gotoBottom->matches(new KeyMsg('G')));
        $this->assertTrue($km->gotoBottom->matches(new KeyMsg(KeyMsg::END)));

        $this->assertTrue($km->create->matches(new KeyMsg('n')));
        $this->assertTrue($km->fork->matches(new KeyMsg('F')));
        $this->assertTrue($km->settings->matches(new KeyMsg('S')));
        $this->assertTrue($km->copy->matches(new KeyMsg('c')));
        $this->assertTrue($km->help->matches(new KeyMsg('?')));
    }

    public function testInputParserMultiKeySequences(): void
    {
        $parser = new InputParser();

        $events = $parser->parse('gd');
        $this->assertCount(1, $events);
        $this->assertInstanceOf(KeyMsg::class, $events[0]);
        $this->assertSame('gd', $events[0]->key);

        $events2 = $parser->parse('gr');
        $this->assertCount(1, $events2);
        $this->assertInstanceOf(KeyMsg::class, $events2[0]);
        $this->assertSame('gr', $events2[0]->key);
    }

    public function testSelectorComponentShortcuts(): void
    {
        $items = [
            ['id' => '1', 'name' => 'first', 'command' => 'git clone test1'],
            ['id' => '2', 'name' => 'second', 'command' => 'git clone test2'],
        ];

        $sel = new SelectorComponent($items, 'test_');
        $this->assertSame('1', $sel->getSelectedItem()['id']);

        // Navigate down with j
        $sel->update(new KeyMsg('j'));
        $this->assertSame('2', $sel->getSelectedItem()['id']);

        // Navigate up with k
        $sel->update(new KeyMsg('k'));
        $this->assertSame('1', $sel->getSelectedItem()['id']);

        // Copy shortcut 'c'
        $resCopy = $sel->update(new KeyMsg('c'));
        $this->assertSame('copy', $resCopy);

        // Enter selects
        $resSelect = $sel->update(new KeyMsg(KeyMsg::ENTER));
        $this->assertSame('select', $resSelect);

        // 'h' when no filter gives 'back'
        $resBack = $sel->update(new KeyMsg('h'));
        $this->assertSame('back', $resBack);
    }

    public function testCodeViewerShortcuts(): void
    {
        $code = "Line 1\nLine 2\nLine 3\nLine 4\nLine 5";
        $viewer = new CodeViewerComponent('test.txt', $code);

        $this->assertSame(0, $viewer->getCursorLine());
        $this->assertTrue($viewer->isShowLineNumbers());

        // 'l' toggles line numbers
        $viewer->update(new KeyMsg('l'));
        $this->assertFalse($viewer->isShowLineNumbers());
        $viewer->update(new KeyMsg('l'));
        $this->assertTrue($viewer->isShowLineNumbers());

        // 'j' moves cursor down
        $viewer->update(new KeyMsg('j'));
        $this->assertSame(1, $viewer->getCursorLine());

        // 'k' moves cursor up
        $viewer->update(new KeyMsg('k'));
        $this->assertSame(0, $viewer->getCursorLine());

        // 'G' goes to bottom
        $viewer->update(new KeyMsg('G'));
        $this->assertSame(4, $viewer->getCursorLine());

        // 'g' goes to top
        $viewer->update(new KeyMsg('g'));
        $this->assertSame(0, $viewer->getCursorLine());

        // 'c' copies all content
        $res = $viewer->update(new KeyMsg('c'));
        $this->assertSame('copy', $res);

        // 'gd' returns goto_def
        $resDef = $viewer->update(new KeyMsg('gd'));
        $this->assertSame('goto_def', $resDef);

        // 'gr' returns find_refs
        $resRefs = $viewer->update(new KeyMsg('gr'));
        $this->assertSame('find_refs', $resRefs);
    }

    public function testFormComponentShortcuts(): void
    {
        $t1 = new ToggleField('test_toggle', 'Option', false);
        $txt = new TextInputField('test_text', 'Name', '');
        $form = new FormComponent('Title', 'Subtitle', [$t1, $txt]);

        // Toggle with Space or x
        $this->assertFalse($t1->getValue());
        $t1->handleKey(new KeyMsg(' '));
        $this->assertTrue($t1->getValue());
        $t1->handleKey(new KeyMsg('x'));
        $this->assertFalse($t1->getValue());

        // Navigate fields with Tab
        $form->update(new KeyMsg(KeyMsg::TAB));
        // Cancel with ESC
        $resCancel = $form->update(new KeyMsg(KeyMsg::ESC));
        $this->assertSame('cancel', $resCancel);

        // Submit with ctrl+s from InputParser (\x13)
        $parser = new InputParser();
        $events = $parser->parse("\x13");
        $this->assertCount(1, $events);
        $this->assertSame('ctrl+s', $events[0]->key);

        $txt->setValue('My New Repo');
        $this->assertSame('My New Repo', $form->getFieldValue('test_text'));

        $resSubmit = $form->update($events[0]);
        $this->assertSame('submit', $resSubmit);
    }

    public function testEditorComponentShortcuts(): void
    {
        $editor = new EditorComponent('file.md', "Hello World\nLine 2");
        $this->assertFalse($editor->isLivePreview());

        // Toggle live preview with ctrl+p
        $editor->update(new KeyMsg('ctrl+p'));
        $this->assertTrue($editor->isLivePreview());
        $editor->update(new KeyMsg('ctrl+p'));
        $this->assertFalse($editor->isLivePreview());
    }

    public function testSelectionPageGlobalShortcuts(): void
    {
        $gitService = new GitRepositoryReader();
        $renderer = new TermstrapRenderer();
        $page = new SelectionPage($gitService, $renderer);
        $page->init();

        $adminTriggered = false;
        $page->setOnAdmin(static function () use (&$adminTriggered) {
            $adminTriggered = true;
        });

        // 'S' opens server settings
        $page->update(new KeyMsg('S'));
        $this->assertTrue($adminTriggered);

        // 'q' quits
        $resQuit = $page->update(new KeyMsg('q'));
        $this->assertSame('quit', $resQuit);
    }

    public function testRepoPageGlobalShortcuts(): void
    {
        $tmpDir = sys_get_temp_dir().'/test_repo_shortcuts_'.uniqid();
        mkdir($tmpDir, 0755, true);
        shell_exec('git -C '.escapeshellarg($tmpDir).' init --bare 2>/dev/null');

        $gitService = new GitRepositoryReader();
        $repo = $gitService->openRepository($tmpDir);
        $renderer = new TermstrapRenderer();
        $repoPage = new RepoPage($repo, $gitService, $renderer);
        $repoPage->init();

        // 'q' quits
        $resQuit = $repoPage->update(new KeyMsg('q'));
        $this->assertSame('quit', $resQuit);

        // '?' toggles help
        $repoPage->update(new KeyMsg('?'));
        $viewWithHelp = $repoPage->view(80, 24);
        $this->assertStringContainsString('Change tab', $viewWithHelp);
        $this->assertStringContainsString('Navigate', $viewWithHelp);

        // Cleanup
        shell_exec('rm -rf '.escapeshellarg($tmpDir));
    }
}
