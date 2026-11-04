<?php

declare(strict_types=1);

namespace App\Tui\Page;

use App\Model\AccessLevel;
use App\Model\GitRepositoryModel;
use App\Model\UserModel;
use App\Service\Backend\BackendService;
use App\Service\Store\DataStore;
use App\Service\Render\TermstrapRenderer;
use App\Service\Database\DatabaseManager;
use App\Service\Git\GitService;
use App\Tui\Component\ComponentInterface;
use App\Tui\Component\Field\TextInputField;
use App\Tui\Component\Field\ToggleField;
use App\Tui\Component\FooterComponent;
use App\Tui\Component\FormComponent;
use App\Tui\Component\HeaderComponent;
use App\Tui\Component\SelectorComponent;
use App\Tui\Component\StatusbarComponent;
use App\Tui\Component\TabsComponent;
use App\Tui\Component\ViewportComponent;
use App\Tui\Event\InputEvent;
use App\Tui\Event\KeyMsg;
use App\Tui\Event\MouseMsg;
use App\Tui\Style\Style;
use App\Tui\Style\TokyoNight;
use Throwable;

final class SelectionPage implements ComponentInterface
{
 public const TAB_FEED = 0;
 public const TAB_REPOS = 1;
 public const TAB_ABOUT = 2;
 public const TAB_PROFILE = 3;

 private TabsComponent $tabs;
 private HeaderComponent $header;
 private FooterComponent $footer;

 private SelectorComponent $feedSelector;
 private SelectorComponent $reposSelector;
 private ViewportComponent $aboutViewport;
 private ViewportComponent $profileViewport;

 private ?FormComponent $createRepoModal = null;
 private ?FormComponent $forkRepoModal = null;
 private bool $showHelp = false;

 /** @var callable|null */
 private $onSelectRepoCallback = null;
 /** @var callable|null */
 private $onAdminCallback = null;
 private BackendService $backend;
 private ?UserModel $currentUser = null;

 public function __construct(
  private readonly GitService $gitService,
  private readonly TermstrapRenderer $renderer,
  ?BackendService $backend = null,
  ?UserModel $currentUser = null,
 ) {
  if ($backend === null) {
   $dbManager = new DatabaseManager();
   $dbManager->ensureBootstrapped();
   $backend = new BackendService(new DataStore($dbManager));
  } else {
   $backend->getDataStore()->getDatabaseManager()->ensureBootstrapped();
  }
  $this->backend = $backend;
  $this->currentUser = $currentUser ?? $this->resolveCurrentUser();

  $this->header = new HeaderComponent('codes.sh');
  $this->tabs = new TabsComponent([
   'Feed',
   'My Repositories',
   'About',
   'Profil',
  ]);

  $this->feedSelector = new SelectorComponent([], 'feed_', 'No recent activity.');
  $this->reposSelector = new SelectorComponent([], 'repo_', 'No repositories found.');
  $this->aboutViewport = new ViewportComponent();
  $this->profileViewport = new ViewportComponent();

  $this->footer = new FooterComponent([
   '↑↓' => 'navigate',
   'tab' => 'section',
   '→/l' => 'select',
   'n' => 'new',
   'F' => 'fork',
   'S' => 'settings',
   'c' => 'copy',
   'q' => 'quit',
   '?' => 'help',
  ]);
 }

 public function setOnAdmin(?callable $callback): void
 {
  $this->onAdminCallback = $callback;
 }

 private function resolveCurrentUser(): ?UserModel
 {
  $envUser = (string) (getenv('SOFT_SERVE_USERNAME') ?: '');
  if ($envUser !== '') {
   $u = $this->backend->getUser($envUser);
   if ($u !== null) {
    return $u;
   }
  }

  $envKey = (string) (getenv('SOFT_SERVE_PUBLIC_KEY') ?: '');
  if ($envKey !== '') {
   $u = $this->backend->getUserByPublicKey($envKey);
   if ($u !== null) {
    return $u;
   }
  }

  // Fallback to first admin user (ID 1) for local CLI/TUI execution
  return $this->backend->getUser('admin') ?? $this->backend->getUserById(1);
 }

 public function setOnSelectRepo(?callable $callback): void
 {
  $this->onSelectRepoCallback = $callback;
 }

 public function init(): void
 {
  $this->tabs->setActiveTab(self::TAB_FEED);
  $this->loadRepositories();
  $this->loadFeed();
  $this->loadAbout();
  $this->loadProfile();
 }

 /**
  * Loads "My Repositories" from the SQLite database (BackendService),
  * strictly matching pkg/ui/pages/selection/selection.go:Init():
  * only non-hidden repositories owned by the current user, sorted by last update.
  */
 private function loadRepositories(): void
 {
  $rows = $this->backend->listRepos();
  $myItems = [];

  foreach ($rows as $row) {
   if (!empty($row['hidden'])) {
    continue;
   }

   // "My Repositories" only lists repositories owned by the current user (selection.go:286)
   if ($this->currentUser === null || (int) ($row['user_id'] ?? 0) !== $this->currentUser->id) {
    continue;
   }

   $myItems[] = $this->createRepoItemFromRow($row);
  }

  $this->sortItemsByActivity($myItems);
  $this->reposSelector->setItems($myItems);
 }

 /**
  * Loads "Feed" from the SQLite database (BackendService),
  * strictly matching pkg/ui/pages/selection/feed.go:loadFeedCmd():
  * every non-hidden repository the current user has >= ReadOnlyAccess to, sorted by last update.
  */
 private function loadFeed(): void
 {
  $rows = $this->backend->listRepos();
  $feedItems = [];
  $username = $this->currentUser?->username;

  foreach ($rows as $row) {
   if (!empty($row['hidden'])) {
    continue;
   }

   $repoName = (string) $row['name'];
   if ($this->backend->getAccessLevel($repoName, $username)->value < AccessLevel::ReadOnlyAccess->value) {
    continue;
   }

   $feedItems[] = $this->createRepoItemFromRow($row);
  }

  $this->sortItemsByActivity($feedItems);
  $this->feedSelector->setItems($feedItems);
 }

 /**
  * Sorts items by most recent activity first, then alphabetically by name (matching item.go:Less).
  *
  * @param array<int, array<string, mixed>> $items
  */
 private function sortItemsByActivity(array &$items): void
 {
  usort($items, function (array $a, array $b): int {
   $ta = isset($a['updatedAt']) && $a['updatedAt'] instanceof \DateTimeInterface
    ? $a['updatedAt']->getTimestamp()
    : (int) ($a['updatedAt'] ?? 0);
   $tb = isset($b['updatedAt']) && $b['updatedAt'] instanceof \DateTimeInterface
    ? $b['updatedAt']->getTimestamp()
    : (int) ($b['updatedAt'] ?? 0);

   if ($ta === $tb) {
    return strcmp((string) ($a['repoName'] ?? $a['label'] ?? ''), (string) ($b['repoName'] ?? $b['label'] ?? ''));
   }
   return $tb <=> $ta;
  });
 }

 /**
  * Builds a selector item from a SQLite `repos` row and its bare Git repository in `.data/repos/<name>.git`.
  *
  * @param array<string, mixed> $row
  * @return array<string, mixed>
  */
 private function createRepoItemFromRow(array $row): array
 {
  $name = (string) $row['name'];
  $projectName = (string) ($row['project_name'] ?? '');
  $desc = trim((string) ($row['description'] ?? ''));
  $isPrivate = !empty($row['private']);

  $publicUrl = getenv('SOFT_SERVE_SSH_PUBLIC_URL') ?: (getenv('CODES_SH_PUBLIC_URL') ?: 'ssh://codes.sh:2222');
  $cmd = 'git clone ' . rtrim($publicUrl, '/') . '/' . $name . '.git';

  $updatedAt = null;
  if (!empty($row['updated_at'])) {
   try {
    $updatedAt = new \DateTimeImmutable((string) $row['updated_at']);
   } catch (Throwable) {
   }
  }

  $repoModel = null;
  $repoPath = $this->backend->getRepoPath($name);
  if (is_dir($repoPath)) {
   try {
    $opened = $this->gitService->openRepository($repoPath);
    if ($opened->updatedAt !== null) {
     $updatedAt = $opened->updatedAt;
    }
    $repoModel = new GitRepositoryModel(
     name: $name,
     path: $opened->path,
     isBare: $opened->isBare,
     defaultBranch: $opened->defaultBranch,
     description: $desc !== '' ? $desc : $opened->description,
     projectName: $projectName !== '' ? $projectName : $opened->projectName,
     isPrivate: $isPrivate,
     updatedAt: $updatedAt,
    );
   } catch (Throwable) {
   }
  }

  $title = ($projectName !== '' && $projectName !== $name) ? ($projectName . ' ' . $name) : ($projectName !== '' ? $projectName : $name);

  return [
   'id' => $name,
   'label' => $title,
   'title' => $projectName !== '' ? $projectName : $name,
   'projectName' => $projectName !== '' ? $projectName : null,
   'repoName' => $name,
   'description' => $desc,
   'command' => $cmd,
   'updatedAt' => $updatedAt,
   'isPrivate' => $isPrivate,
   'repo' => $repoModel,
  ];
 }

 /**
  * Loads the About tab from the `.soft-serve` repository's README.md if present in the database,
  * otherwise displays defaultNoContent (matching selection.go:22 & 274).
  */
 private function loadAbout(): void
 {
  $softServePath = $this->backend->getRepoPath('.soft-serve');
  if (is_dir($softServePath)) {
   try {
    $repo = $this->gitService->openRepository($softServePath);
    $readme = $this->gitService->getReadme($repo);
    if ($readme !== null && trim($readme['content']) !== '') {
     $rendered = $this->renderer->renderDocument($readme['filename'], $readme['content'], 80);
     $this->aboutViewport->setContent($rendered);
     return;
    }
   } catch (Throwable) {
   }
  }

  $defaultNoContent = "No readme found.\n\nCreate a `.soft-serve` repository and add a `README.md` file to display readme.";
  $this->aboutViewport->setContent(
   Style::create()->foreground(TokyoNight::NO_CONTENT_FG)->render($defaultNoContent)
  );
 }

 private function loadProfile(): void
 {
  $user = $this->currentUser;
  $username = $user?->username ?? get_current_user();
  $role = ($user?->isAdmin ?? false) ? 'Admin' : 'User';
  $myReposCount = count($this->reposSelector->getItems());

  $sb = [];
  $sb[] = Style::create()->bold(true)->foreground(TokyoNight::ACTIVE_BORDER)->render('Profil Utilisateur');
  $sb[] = '';
  $sb[] = 'Username     : ' . Style::create()->bold(true)->render($username);
  $sb[] = 'Role         : ' . Style::create()->foreground(TokyoNight::CYAN)->render($role);
  $sb[] = 'Repositories : ' . $myReposCount;
  $sb[] = '';
  $sb[] = Style::create()->bold(true)->render('Clés SSH enregistrées :');

  $keys = $user?->publicKeys ?? [];
  if (!empty($keys)) {
   foreach ($keys as $keyContent) {
    $parts = explode(' ', trim($keyContent));
    $type = $parts[0] ?? 'ssh-key';
    $comment = $parts[2] ?? ($parts[1] ?? '');
    $sb[] = '  🔑 ' . Style::create()->foreground(TokyoNight::CYAN)->render($type) . ' ' . $comment;
   }
  } else {
   $sb[] = Style::create()->foreground(TokyoNight::MUTED)->render('  Aucune clé SSH enregistrée pour cet utilisateur.');
  }

  $this->profileViewport->setContent(implode("\n", $sb));
 }

 public function update(InputEvent $event): ?string
 {
  // 1. If modals are open
  if ($this->createRepoModal !== null) {
   $action = $this->createRepoModal->update($event);
   if ($action === 'cancel') {
    $this->createRepoModal = null;
    return null;
   }
   if ($action === 'submit') {
    $name = trim((string) $this->createRepoModal->getFieldValue('name'));
    $desc = trim((string) $this->createRepoModal->getFieldValue('description'));
    $priv = (bool) $this->createRepoModal->getFieldValue('is_private');
    if ($name !== '') {
     try {
      $this->backend->createRepositoryWithGit(
       name: $name,
       owner: $this->currentUser,
       description: $desc,
       private: $priv,
      );
      $this->footer->setMessage(sprintf("Repository '%s' created successfully!", $name), 'success');
     } catch (Throwable $e) {
      $this->footer->setMessage(sprintf("Error creating repository: %s", $e->getMessage()), 'error');
     }
     $this->loadRepositories();
     $this->loadFeed();
     $this->loadAbout();
     $this->loadProfile();
    }
    $this->createRepoModal = null;
    return null;
   }
   return null;
  }

  if ($this->forkRepoModal !== null) {
   $action = $this->forkRepoModal->update($event);
   if ($action === 'cancel') {
    $this->forkRepoModal = null;
    return null;
   }
   if ($action === 'submit') {
    $newName = trim((string) $this->forkRepoModal->getFieldValue('new_name'));
    $sourceRepo = trim((string) $this->forkRepoModal->getFieldValue('source_repo'));
    $priv = (bool) $this->forkRepoModal->getFieldValue('is_private');
    if ($newName !== '' && $sourceRepo !== '') {
     try {
      $this->backend->forkRepository($sourceRepo, $newName, $this->currentUser, $priv);
     } catch (Throwable) {
     }
     $this->loadRepositories();
     $this->loadFeed();
    }
    $this->forkRepoModal = null;
    return null;
   }
   return null;
  }

  // 2. Global keys
  if ($event instanceof KeyMsg) {
   if ($event->is('q')) {
    return 'quit';
   }
   if ($event->is('?')) {
    $this->showHelp = !$this->showHelp;
    return null;
   }
   if ($event->is('n') && $this->tabs->getActiveTab() === self::TAB_REPOS) {
    $this->openNewRepoModal();
    return null;
   }
   if ($event->is('S')) {
    if ($this->onAdminCallback !== null) {
     ($this->onAdminCallback)();
     return null;
    }
   }
   if ($event->is('F')) {
    $selected = match ($this->tabs->getActiveTab()) {
     self::TAB_FEED => $this->feedSelector->getSelectedItem(),
     self::TAB_REPOS => $this->reposSelector->getSelectedItem(),
     default => null,
    };
    if (is_array($selected)) {
     $repoName = (string) ($selected['repoName'] ?? $selected['id'] ?? '');
     if ($repoName !== '') {
      $this->openForkModal($repoName);
      return null;
     }
    }
   }
  }
  // 3. Tabs update (tab, shift+tab, mouse click on tab)
  $oldTab = $this->tabs->getActiveTab();
  $this->tabs->update($event);
  if ($this->tabs->getActiveTab() !== $oldTab) {
   return null;
  }

  // 4. Tab contents
  switch ($this->tabs->getActiveTab()) {
   case self::TAB_FEED:
    $res = $this->feedSelector->update($event);
    if ($res === 'select') {
     $selected = $this->feedSelector->getSelectedItem();
     $this->openSelectedItem($selected);
    }
    break;

   case self::TAB_REPOS:
    $res = $this->reposSelector->update($event);
    if ($res === 'select') {
     $selected = $this->reposSelector->getSelectedItem();
     $this->openSelectedItem($selected);
    }
    break;

   case self::TAB_ABOUT:
    $this->aboutViewport->update($event);
    break;

   case self::TAB_PROFILE:
    $this->profileViewport->update($event);
    break;
  }

  return null;
 }

 /**
  * @param array<string, mixed>|null $selected
  */
 private function openSelectedItem(?array $selected): void
 {
  if ($selected === null) {
   return;
  }
  $repo = $selected['repo'] ?? ($selected['data']['repo'] ?? null);
  if ($repo instanceof GitRepositoryModel) {
   if ($this->onSelectRepoCallback !== null) {
    ($this->onSelectRepoCallback)($repo);
   }
   return;
  }

  $name = (string) ($selected['id'] ?? '');
  if ($name !== '') {
   $repoPath = $this->backend->getRepoPath($name);
   if (is_dir($repoPath)) {
    try {
     $r = $this->gitService->openRepository($repoPath);
     if ($this->onSelectRepoCallback !== null) {
      ($this->onSelectRepoCallback)($r);
     }
    } catch (Throwable) {
    }
   }
  }
 }

 private function openNewRepoModal(): void
 {
  $nameField = new TextInputField('name', 'Repository name', '');
  $descField = new TextAreaField('description', 'Description (optional)', '');
  $privField = new ToggleField('is_private', 'Private repository', false);
  $this->createRepoModal = new FormComponent('New Repository', 'Create a new Git repository on codes.sh', [
   $nameField,
   $descField,
   $privField,
  ]);
 }

 private function openForkModal(string $sourceRepo): void
 {
  $sourceField = new TextInputField('source_repo', 'Source repository', $sourceRepo);
  $forkName = $sourceRepo . '-fork';
  $nameField = new TextInputField('new_name', 'Fork name', $forkName);
  $privField = new ToggleField('is_private', 'Private repository', false);
  $this->forkRepoModal = new FormComponent('Fork Repository', 'Fork repository into your account', [
   $sourceField,
   $nameField,
   $privField,
  ]);
 }
 public function view(int $width, int $height): string
 {
  if ($width <= 0 || $height <= 0) {
   return '';
  }

  $innerWidth = max(10, $width - 4);

  $headerStr = $this->header->view($innerWidth, 1);
  $tabsStr = $this->tabs->view($innerWidth, 1);
  $footerStr = $this->footer->view($innerWidth, 1);

  // Total chrome: 1 top margin + 2 header + 2 tabs + 2 footer + 1 bottom margin = 8 lines
  $bodyHeight = max(1, $height - 8);

  if ($this->createRepoModal !== null) {
   $bodyView = $this->createRepoModal->view($innerWidth, $bodyHeight);
  } elseif ($this->forkRepoModal !== null) {
   $bodyView = $this->forkRepoModal->view($innerWidth, $bodyHeight);
  } else {
   $bodyView = match ($this->tabs->getActiveTab()) {
    self::TAB_FEED => $this->feedSelector->view($innerWidth, $bodyHeight),
    self::TAB_REPOS => $this->reposSelector->view($innerWidth, $bodyHeight),
    self::TAB_ABOUT => $this->aboutViewport->view($innerWidth, $bodyHeight),
    self::TAB_PROFILE => $this->profileViewport->view($innerWidth, $bodyHeight),
    default => '',
   };
  }
  $contentLines = [];

  // Line 1: Top margin (1 empty line)
  $contentLines[] = '';

  // Line 2: Header (ServerName: 2 spaces App margin + 1 space ServerName MarginLeft = 3 spaces)
  $contentLines[] = '   ' . ltrim($headerStr);

  // Line 3: Empty line below Header (ServerName MarginBottom 1)
  $contentLines[] = '';

  // Line 4: Tabs (2 spaces App margin)
  $contentLines[] = '  ' . $tabsStr;

  // Line 5: Empty line below Tabs (Tabs MarginBottom 1)
  $contentLines[] = '';

  // Lines 6..: Body (with 2 spaces App margin)
  foreach (explode("\n", rtrim($bodyView, "\n")) as $line) {
   $contentLines[] = $line === '' ? '' : ('  ' . $line);
  }

  // Fill vertical space until footer
  // Footer needs: 1 empty line above (Footer MarginTop 1) + 1 footer line + 1 bottom margin = 3 lines at bottom
  $footerLineTarget = max(0, $height - 2);
  if (count($contentLines) > $footerLineTarget - 1) {
   $contentLines = array_slice($contentLines, 0, $footerLineTarget - 1);
  }
  while (count($contentLines) < $footerLineTarget - 1) {
   $contentLines[] = '';
  }

  // Empty line above footer (Footer MarginTop 1)
  $contentLines[] = '';

  // Footer (with 3 spaces indent: App Margin 2 + Footer Padding 1)
  $contentLines[] = '   ' . $footerStr;

  // Bottom margin (1 empty line)
  $contentLines[] = '';

  return implode("\n", array_slice($contentLines, 0, $height));
 }
}
