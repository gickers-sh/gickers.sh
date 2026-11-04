<?php

declare(strict_types=1);

namespace App\Tui\Component;

use App\Tui\Event\InputEvent;
use App\Tui\Event\KeyMsg;
use App\Tui\Event\MouseMsg;
use App\Tui\Style\Ansi;
use App\Tui\Style\TokyoNight;
use App\Tui\Style\ZoneManager;

final class SelectorComponent implements ComponentInterface
{
 /** @var array<int, array{id: string, label: string, data: mixed, title?: string, projectName?: ?string, repoName?: string, description?: string, command?: string, updatedAt?: ?\DateTimeInterface, isPrivate?: bool}> */
 private array $items = [];
 private int $selectedIndex = 0;
 private string $filterQuery = '';
 private int $yOffset = 0;
 private ?string $idPrefix = null;
 private ?string $copiedId = null;
 private string $emptyMessage;
 private string $displayMode = 'repo';

 public function setDisplayMode(string $mode): void
 {
  $this->displayMode = $mode;
 }

 /**
  * @param array<int, string|array<string, mixed>|object> $items
  */
 public function __construct(array $items = [], ?string $idPrefix = 'sel_', string $emptyMessage = 'No items.')
 {
  $this->idPrefix = $idPrefix ?? 'sel_';
  $this->emptyMessage = $emptyMessage;
  $this->setItems($items);
 }

 public function init(): void
 {
  $this->selectedIndex = 0;
  $this->filterQuery = '';
  $this->yOffset = 0;
  $this->copiedId = null;
 }

 /**
  * @param array<int, string|array<string, mixed>|object> $items
  */
 public function setItems(array $items): void
 {
  $this->items = [];
  foreach ($items as $idx => $item) {
   if (is_string($item)) {
    $this->items[] = [
     'id' => (string) $idx,
     'label' => $item,
     'title' => $item,
     'data' => $item,
    ];
   } elseif (is_array($item)) {
    $id = (string) ($item['id'] ?? $idx);
    $label = (string) ($item['label'] ?? $item['title'] ?? $item['name'] ?? $id);
    $this->items[] = array_merge([
     'id' => $id,
     'label' => $label,
     'title' => (string) ($item['title'] ?? $label),
     'projectName' => isset($item['projectName']) ? (string) $item['projectName'] : null,
     'repoName' => isset($item['repoName']) ? (string) $item['repoName'] : (string) ($item['name'] ?? $id),
     'description' => (string) ($item['description'] ?? ''),
     'command' => (string) ($item['command'] ?? ''),
     'updatedAt' => $item['updatedAt'] ?? null,
     'isPrivate' => (bool) ($item['isPrivate'] ?? false),
     'data' => $item,
    ], $item);
   } elseif (is_object($item)) {
    $id = method_exists($item, 'getId') ? (string) $item->getId() : (string) ($item->id ?? $idx);
    $label = method_exists($item, 'getLabel') ? (string) $item->getLabel() : (string) ($item->label ?? $item->name ?? $id);
    $this->items[] = [
     'id' => $id,
     'label' => $label,
     'title' => $label,
     'data' => $item,
    ];
   }
  }

  $filtered = $this->getFilteredItems();
  if ($this->selectedIndex >= count($filtered)) {
   $this->selectedIndex = max(0, count($filtered) - 1);
  }
 }
 /**
  * @return array<int, array{id: string, label: string, data: mixed}>
  */
 public function getItems(): array
 {
  return $this->items;
 }


 /**
  * @return array<int, array{id: string, label: string, data: mixed}>
  */
 public function getFilteredItems(): array
 {
  if ($this->filterQuery === '') {
   return $this->items;
  }

  $filtered = [];
  $query = mb_strtolower($this->filterQuery, 'UTF-8');
  foreach ($this->items as $item) {
   $labelLower = mb_strtolower($item['label'], 'UTF-8');
   if (str_contains($labelLower, $query)) {
    $filtered[] = $item;
   }
  }
  return $filtered;
 }

 public function getSelectedIndex(): int
 {
  return $this->selectedIndex;
 }

 public function setSelectedIndex(int $index): void
 {
  $filtered = $this->getFilteredItems();
  if ($index >= 0 && $index < count($filtered)) {
   $this->selectedIndex = $index;
  }
 }

 /**
  * @return array{id: string, label: string, data: mixed}|null
  */
 public function getSelectedItem(): ?array
 {
  $filtered = $this->getFilteredItems();
  return $filtered[$this->selectedIndex] ?? null;
 }

 public function getFilterQuery(): string
 {
  return $this->filterQuery;
 }

 public function setFilterQuery(string $query): void
 {
  $this->filterQuery = $query;
  $filtered = $this->getFilteredItems();
  if ($this->selectedIndex >= count($filtered)) {
   $this->selectedIndex = max(0, count($filtered) - 1);
  }
 }

 public function update(InputEvent $event): ?string
 {
  $filtered = $this->getFilteredItems();

  if ($event instanceof KeyMsg) {
   if ($event->is(KeyMsg::UP) || $event->is('k')) {
    if ($this->selectedIndex > 0) {
     $this->selectedIndex--;
    }
    return null;
   }

   if ($event->is(KeyMsg::DOWN) || $event->is('j')) {
    if ($this->selectedIndex < count($filtered) - 1) {
     $this->selectedIndex++;
    }
    return null;
   }
   if ($event->is('c') && $this->filterQuery === '' && !empty($filtered[$this->selectedIndex]['command'])) {
    $sel = $filtered[$this->selectedIndex];
    $this->copiedId = (string) $sel['id'];
    $copyText = (string) $sel['command'];
    echo "\x1b]52;c;" . base64_encode($copyText) . "\x07";
    return 'copy';
   }

   if ($event->is(KeyMsg::PAGE_UP)) {
    $this->selectedIndex = max(0, $this->selectedIndex - 5);
    return null;
   }

   if ($event->is(KeyMsg::PAGE_DOWN)) {
    $this->selectedIndex = min(max(0, count($filtered) - 1), $this->selectedIndex + 5);
    return null;
   }

   if ($event->is(KeyMsg::HOME)) {
    $this->selectedIndex = 0;
    return null;
   }

   if ($event->is(KeyMsg::END)) {
    $this->selectedIndex = max(0, count($filtered) - 1);
    return null;
   }

   if ($event->is(KeyMsg::ENTER) || $event->is('l') || $event->is(KeyMsg::RIGHT)) {
    return 'select';
   }
   if ($event->is(KeyMsg::BACKSPACE)) {
    if ($this->filterQuery !== '') {
     $this->filterQuery = mb_substr($this->filterQuery, 0, -1, 'UTF-8');
     $newFiltered = $this->getFilteredItems();
     if ($this->selectedIndex >= count($newFiltered)) {
      $this->selectedIndex = max(0, count($newFiltered) - 1);
     }
     return null;
    }
    return 'back';
   }

   if ($event->is('h') && $this->filterQuery === '') {
    return 'back';
   }

   if ($event->is(KeyMsg::LEFT) && $this->filterQuery === '') {
    return 'back';
   }

   // Typing printable characters for filterQuery
   if ($event->runes !== '' && !$event->ctrl && !$event->alt && strlen($event->runes) === 1 && $event->runes !== 'j' && $event->runes !== 'k') {
    $this->filterQuery .= $event->runes;
    $newFiltered = $this->getFilteredItems();
    if ($this->selectedIndex >= count($newFiltered)) {
     $this->selectedIndex = max(0, count($newFiltered) - 1);
    }
    return null;
   }
  }

  if ($event instanceof MouseMsg) {
   if ($event->isWheelUp()) {
    if ($this->selectedIndex > 0) {
     $this->selectedIndex--;
    }
    return null;
   }

   if ($event->isWheelDown()) {
    if ($this->selectedIndex < count($filtered) - 1) {
     $this->selectedIndex++;
    }
    return null;
   }

   if ($event->isLeftClick()) {
    foreach ($filtered as $idx => $item) {
     $zoneId = $this->idPrefix . $item['id'];
     if (ZoneManager::inBounds($zoneId, $event)) {
      $this->selectedIndex = $idx;
      return 'select';
     }
    }
   }
  }

  return null;
 }

 public static function humanizeTime(int|\DateTimeInterface $time): string
 {
  $ts = $time instanceof \DateTimeInterface ? $time->getTimestamp() : $time;
  $diff = time() - $ts;
  if ($diff < 0) {
   $diff = 0;
  }
  if ($diff < 60) {
   return 'now';
  }
  if ($diff < 3600) {
   $mins = (int) floor($diff / 60);
   return $mins === 1 ? '1 minute ago' : "{$mins} minutes ago";
  }
  if ($diff < 86400) {
   $hours = (int) floor($diff / 3600);
   return $hours === 1 ? '1 hour ago' : "{$hours} hours ago";
  }
  if ($diff < 86400 * 7) {
   $days = (int) floor($diff / 86400);
   return $days === 1 ? '1 day ago' : "{$days} days ago";
  }
  if ($diff < 86400 * 30) {
   $weeks = (int) floor($diff / (86400 * 7));
   return $weeks === 1 ? '1 week ago' : "{$weeks} weeks ago";
  }
  if ($diff < 86400 * 365) {
   $months = (int) floor($diff / (86400 * 30));
   return $months === 1 ? '1 month ago' : "{$months} months ago";
  }
  $years = (int) floor($diff / (86400 * 365));
  return $years === 1 ? '1 year ago' : "{$years} years ago";
 }

 public function view(int $width, int $height): string
 {
  if ($this->displayMode === 'files') {
   return $this->viewFiles($width, $height);
  }
  $filtered = $this->getFilteredItems();
  $totalItems = count($filtered);
  $reset = TokyoNight::reset();

  $lines = [];

  // Filter bar if filtering
  $filterHeight = 0;
  if ($this->filterQuery !== '') {
   $filterPrompt = TokyoNight::hexToAnsi(TokyoNight::CYAN) . ' / ' . $reset;
   $filterText = TokyoNight::hexToAnsi(TokyoNight::FG) . $this->filterQuery . $reset . "\x1b[7m \x1b[27m";
   $countInfo = TokyoNight::hexToAnsi(TokyoNight::MUTED) . sprintf(' (%d/%d)', $totalItems, count($this->items)) . $reset;
   $lines[] = $filterPrompt . $filterText . $countInfo;
   $filterHeight = 1;
  }

  $availableHeight = max(1, $height - $filterHeight);

  if ($totalItems === 0) {
   $lines[] = TokyoNight::hexToAnsi(TokyoNight::NO_CONTENT_FG) . $this->emptyMessage . $reset;
   return implode("\n", $lines);
  }

  // Keep selected index in view
  if ($this->selectedIndex < $this->yOffset) {
   $this->yOffset = $this->selectedIndex;
  } elseif ($this->selectedIndex > $this->yOffset + 3) {
   $this->yOffset = max(0, $this->selectedIndex - 3);
  }

  $barColor = TokyoNight::hexToAnsi(TokyoNight::ITEM_ACTIVE_BAR);
  $activeTitleColor = TokyoNight::hexToAnsi(TokyoNight::ITEM_ACTIVE_TITLE);
  $activeRepoNameColor = TokyoNight::hexToAnsi(TokyoNight::ITEM_ACTIVE_TITLE_REPO);
  $normalRepoNameColor = TokyoNight::hexToAnsi(TokyoNight::ITEM_NORMAL_TITLE_REPO);
  $activeDescColor = TokyoNight::hexToAnsi(TokyoNight::ITEM_ACTIVE_DESC);
  $normalDescColor = TokyoNight::hexToAnsi(TokyoNight::ITEM_NORMAL_DESC);
  $activeCmdColor = TokyoNight::hexToAnsi(TokyoNight::ITEM_ACTIVE_CMD);
  $normalCmdColor = TokyoNight::hexToAnsi(TokyoNight::ITEM_NORMAL_CMD);
  $updatedColor = TokyoNight::hexToAnsi(TokyoNight::ITEM_UPDATED);

  $visibleSlice = array_slice($filtered, $this->yOffset, null, true);

  foreach ($visibleSlice as $idx => $item) {
   $isSelected = ($idx === $this->selectedIndex);
   $zoneId = $this->idPrefix . $item['id'];

   // Indicator width = 2
   if ($isSelected) {
    $indicator = $barColor . "│ " . $reset;
   } else {
    $indicator = "  ";
   }

   $contentWidth = max(10, $width - 2);

   // 1. Title line
   $pname = $item['projectName'] ?? null;
   $rname = $item['repoName'] ?? $item['title'] ?? $item['label'];
   if ($pname !== null && $pname !== '' && $pname !== $rname) {
    if ($isSelected) {
     $titleRendered = "\x1b[1m" . $activeTitleColor . $pname . $reset . " "
      . "\x1b[2m" . $activeRepoNameColor . $rname . $reset;
    } else {
     $titleRendered = "\x1b[1m" . $pname . $reset . " "
      . "\x1b[2m" . $normalRepoNameColor . $rname . $reset;
    }
    $titlePlain = $pname . ' ' . $rname;
   } else {
    $plain = $item['title'] ?? $item['label'];
    if ($isSelected) {
     $titleRendered = "\x1b[1m" . $activeTitleColor . $plain . $reset;
    } else {
     $titleRendered = "\x1b[1m" . $plain . $reset;
    }
    $titlePlain = $plain;
   }

   if (!empty($item['isPrivate'])) {
    $titleRendered .= " 🔒";
    $titlePlain .= " 🔒";
   }

   // Updated time
   $updatedStr = '';
   if (!empty($item['updatedAt'])) {
    $human = self::humanizeTime($item['updatedAt']);
    $updatedStr = "Updated " . $human;
   }

   $titleWidth = Ansi::width($titlePlain);
   $updatedWidth = Ansi::width($updatedStr);

   if ($updatedStr !== '' && ($contentWidth - $titleWidth - $updatedWidth) > 0) {
    $pad = $contentWidth - $titleWidth - $updatedWidth;
    $line1 = $indicator . $titleRendered . str_repeat(' ', $pad) . $updatedColor . $updatedStr . $reset;
   } else {
    $line1 = $indicator . $titleRendered;
   }

   $itemLines = [];
   $itemLines[] = $line1;

   // 2. Description
   $desc = trim((string) ($item['description'] ?? ''));
   if ($desc !== '') {
    $descColor = $isSelected ? $activeDescColor : $normalDescColor;
    $descRawLines = explode("\n", $desc);
    foreach ($descRawLines as $dline) {
     $truncated = Ansi::truncate($dline, $contentWidth);
     $itemLines[] = $indicator . $descColor . $truncated . $reset;
    }
   }

   // 3. Command
   $cmd = (string) ($item['command'] ?? '');
   if ($cmd !== '') {
    if ($this->copiedId === $item['id']) {
     $cmdText = "(copied to clipboard)";
     $cmdRendered = $normalDescColor . $cmdText . $reset;
     $this->copiedId = null;
    } else {
     $cmdColor = $isSelected ? $activeCmdColor : $normalCmdColor;
     $cmdTruncated = Ansi::truncate($cmd, $contentWidth);
     $cmdRendered = $cmdColor . $cmdTruncated . $reset;
    }
    $itemLines[] = $indicator . $cmdRendered;
   }

   $itemBlock = implode("\n", $itemLines);
   $marked = ZoneManager::mark($zoneId, $itemBlock);
   $lines[] = $marked;

   // Blank line spacing between items (Spacing = 1)
   $lines[] = '';

   if (count($lines) >= $availableHeight) {
    break;
   }
  }

  return implode("\n", $lines);
 }

 /**
  * Compact single-line file tree rendering, matching Go soft-serve's
  * FileItemDelegate (pkg/ui/pages/repo/filesitem.go): selector, mode, size, name.
  */
 public function viewFiles(int $width, int $height): string
 {
  $filtered = $this->getFilteredItems();
  $total = count($filtered);
  $reset = TokyoNight::reset();
  $lines = [];

  $filterHeight = 0;
  if ($this->filterQuery !== '') {
   $filterPrompt = TokyoNight::hexToAnsi(TokyoNight::CYAN) . ' / ' . $reset;
   $filterText = TokyoNight::hexToAnsi(TokyoNight::FG) . $this->filterQuery . $reset . "\x1b[7m \x1b[27m";
   $countInfo = TokyoNight::hexToAnsi(TokyoNight::MUTED) . sprintf(' (%d/%d)', $total, count($this->items)) . $reset;
   $lines[] = $filterPrompt . $filterText . $countInfo;
   $filterHeight = 1;
  }

  $availableHeight = max(1, $height - $filterHeight);

  if ($total === 0) {
   $lines[] = TokyoNight::hexToAnsi(TokyoNight::NO_CONTENT_FG) . $this->emptyMessage . $reset;
   return implode("\n", $lines);
  }

  // Keep selected index in view.
  if ($this->selectedIndex < $this->yOffset) {
   $this->yOffset = $this->selectedIndex;
  } elseif ($this->selectedIndex >= $this->yOffset + $availableHeight) {
   $this->yOffset = max(0, $this->selectedIndex - $availableHeight + 1);
  }

  $selColor = TokyoNight::hexToAnsi(TokyoNight::TREE_SELECTOR);
  $dirNormal = TokyoNight::hexToAnsi(TokyoNight::TREE_DIR_FG);
  $modeNormal = TokyoNight::hexToAnsi(TokyoNight::TREE_MODE_NORMAL);
  $modeActive = TokyoNight::hexToAnsi(TokyoNight::TREE_MODE_ACTIVE);
  $sizeActive = TokyoNight::hexToAnsi(TokyoNight::TREE_SIZE_ACTIVE);
  $nameActive = TokyoNight::hexToAnsi(TokyoNight::TREE_FILE_ACTIVE);

  // Column budget: selector(1)+gap(1)+mode(10)+gap(1)+size(8)+gap(1) = 22.
  $nameWidth = max(1, $width - 22);

  $slice = array_slice($filtered, $this->yOffset, $availableHeight, true);
  foreach ($slice as $idx => $item) {
   $isSel = ($idx === $this->selectedIndex);
   $isDir = !empty($item['isDir']);
   $name = (string) ($item['name'] ?? $item['label'] ?? '');
   $name = Ansi::truncate($name, $nameWidth);

   $modeStr = $this->fileModeString((string) ($item['mode'] ?? ''), $isDir);
   $sizeStr = $isDir ? '' : $this->humanizeBytes((int) ($item['size'] ?? 0));
   $sizePadded = str_pad($sizeStr, 8, ' ', STR_PAD_LEFT);

   $selector = $isSel ? ($selColor . '>' . $reset) : ' ';
   $modeColor = $isSel ? $modeActive : $modeNormal;
   $modeRendered = "\x1b[1m" . $modeColor . $modeStr . $reset;
   $sizeColor = $isSel ? $sizeActive : $modeNormal;
   $sizeRendered = $sizeColor . $sizePadded . $reset;

   if ($isSel) {
    $nameRendered = "\x1b[1m" . $nameActive . $name . $reset;
   } elseif ($isDir) {
    $nameRendered = $dirNormal . $name . $reset;
   } else {
    $nameRendered = $name;
   }

   // Margins: mode/size/name each have a 1-space left margin (Go MarginLeft(1)).
   $row = $selector . ' ' . $modeRendered . ' ' . $sizeRendered . ' ' . $nameRendered;
   $zoneId = $this->idPrefix . $item['id'];
   $lines[] = ZoneManager::mark($zoneId, $row);

   if (count($lines) >= $availableHeight) {
    break;
   }
  }

  return implode("\n", $lines);
 }

 /**
  * Converts a raw git tree mode (e.g. "100644", "040000") into an fs.FileMode
  * style string (e.g. "-rw-r--r--", "drwxrwxrwx"), matching Go's mode.String().
  */
 private function fileModeString(string $gitMode, bool $isDir): string
 {
  $gitMode = ltrim($gitMode);
  switch ($gitMode) {
   case '040000':
   case '40000':
   case '160000': // submodule rendered as a directory
    return 'drwxrwxrwx';
   case '120000':
    return 'Lrwxrwxrwx';
   case '100755':
    return '-rwxr-xr-x';
   case '100644':
    return '-rw-r--r--';
  }

  if ($isDir) {
   return 'drwxrwxrwx';
  }

  $perm = substr($gitMode, -3);
  if ($perm === '' || !ctype_digit($perm)) {
   $perm = '644';
  }
  $map = ['---', '--x', '-w-', '-wx', 'r--', 'r-x', 'rw-', 'rwx'];
  $out = '-';
  for ($i = 0; $i < 3; $i++) {
   $d = (int) $perm[$i];
   $out .= $map[$d] ?? '---';
  }
  return $out;
 }

 /**
  * Humanizes a byte count using SI (base 1000) units, matching Go's
  * humanize.Bytes with spaces stripped (e.g. 174 -> "174B", 1126 -> "1.1kB").
  */
 private function humanizeBytes(int $bytes): string
 {
  if ($bytes < 1000) {
   return $bytes . 'B';
  }
  $units = ['kB', 'MB', 'GB', 'TB', 'PB', 'EB'];
  $val = (float) $bytes;
  $i = -1;
  do {
   $val /= 1000.0;
   $i++;
  } while ($val >= 1000.0 && $i < count($units) - 1);

  $num = $val < 10.0 ? number_format($val, 1) : (string) round($val);
  return $num . $units[$i];
 }
}
