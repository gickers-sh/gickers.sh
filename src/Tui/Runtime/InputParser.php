<?php

declare(strict_types=1);

namespace App\Tui\Runtime;

use App\Tui\Event\BackgroundColorMsg;
use App\Tui\Event\InputEvent;
use App\Tui\Event\KeyMsg;
use App\Tui\Event\MouseMsg;

final class InputParser
{
 private string $buffer = '';

 /**
  * Parses incoming raw bytes and returns all recognized InputEvent instances.
  *
  * @return InputEvent[]
  */
 public function parse(string $bytes): array
 {
  $this->buffer .= $bytes;
  $events = [];

  while ($this->buffer !== '') {
   $event = $this->parseNext();
   if ($event === null) {
    break;
   }
   $events[] = $event;
  }

  return $events;
 }

 private function parseNext(): ?InputEvent
 {
  if ($this->buffer === '') {
   return null;
  }

  // 1. OSC 11 probe response: \x1b]11;rgb:rrrr/gggg/bbbb\x07 or \x1b]11;rgb:rrrr/gggg/bbbb\x1b\\
  if (preg_match('/^\x1b\]11;rgb:([0-9a-fA-F]{2,4})\/([0-9a-fA-F]{2,4})\/([0-9a-fA-F]{2,4})(\x07|\x1b\\\\)/', $this->buffer, $m)) {
   $len = strlen($m[0]);
   $this->buffer = substr($this->buffer, $len);

   $rHex = substr($m[1], 0, 2);
   $gHex = substr($m[2], 0, 2);
   $bHex = substr($m[3], 0, 2);

   $r = (int) hexdec($rHex);
   $g = (int) hexdec($gHex);
   $b = (int) hexdec($bHex);

   // Luminance formula: (0.299*R + 0.587*G + 0.114*B) < 128
   $luminance = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
   $isDark = $luminance < 128.0;

   return new BackgroundColorMsg($r, $g, $b, $isDark);
  }

  // 2. SGR Mouse event: \x1b[<(\d+);(\d+);(\d+)([Mm])
  if (preg_match('/^\x1b\[<(\d+);(\d+);(\d+)([Mm])/', $this->buffer, $m)) {
   $len = strlen($m[0]);
   $this->buffer = substr($this->buffer, $len);

   $b = (int) $m[1];
   $x = (int) $m[2];
   $y = (int) $m[3];
   $type = $m[4];

   $shift = ($b & 4) !== 0;
   $alt = ($b & 8) !== 0;
   $ctrl = ($b & 16) !== 0;
   $motion = ($b & 32) !== 0;

   if ($type === 'm') {
    $action = MouseMsg::ACTION_RELEASE;
   } elseif ($motion) {
    $action = MouseMsg::ACTION_MOTION;
   } else {
    $action = MouseMsg::ACTION_PRESS;
   }

   if (($b & 64) !== 0) {
    // Wheel
    $btn = (($b & 1) === 0) ? MouseMsg::BUTTON_WHEEL_UP : MouseMsg::BUTTON_WHEEL_DOWN;
   } else {
    $btn = $b & 3;
   }

   return new MouseMsg($x, $y, $btn, $action, $ctrl, $shift, $alt);
  }

  // 3. Multi-byte escape sequences
  if (str_starts_with($this->buffer, "\x1b")) {
   // Need more data if buffer is just ESC but more might follow
   if (strlen($this->buffer) === 1) {
    // Single ESC key pressed
    $this->buffer = '';
    return new KeyMsg(KeyMsg::ESC, "\x1b");
   }

   // Arrow keys & cursor navigation
   $sequences = [
    "\x1b[A" => KeyMsg::UP,
    "\x1b[B" => KeyMsg::DOWN,
    "\x1b[C" => KeyMsg::RIGHT,
    "\x1b[D" => KeyMsg::LEFT,
    "\x1bOA" => KeyMsg::UP,
    "\x1bOB" => KeyMsg::DOWN,
    "\x1bOC" => KeyMsg::RIGHT,
    "\x1bOD" => KeyMsg::LEFT,
    "\x1b[H" => KeyMsg::HOME,
    "\x1b[F" => KeyMsg::END,
    "\x1b[1~" => KeyMsg::HOME,
    "\x1b[4~" => KeyMsg::END,
    "\x1b[5~" => KeyMsg::PAGE_UP,
    "\x1b[6~" => KeyMsg::PAGE_DOWN,
    "\x1b[3~" => KeyMsg::DELETE,
    "\x1b[Z"  => KeyMsg::BACKTAB,
    "\x1b[1;2Z" => KeyMsg::BACKTAB,
    "\x1b[27;2;9~" => KeyMsg::BACKTAB,
    "\x1b[9;2u" => KeyMsg::BACKTAB,
    "\x1b[9;2~" => KeyMsg::BACKTAB,
   ];

   foreach ($sequences as $seq => $key) {
    if (str_starts_with($this->buffer, $seq)) {
     $this->buffer = substr($this->buffer, strlen($seq));
     return new KeyMsg($key, $seq);
    }
   }

   // Check if buffer starts with ESC followed by another char (Alt + key)
   if (strlen($this->buffer) >= 2 && $this->buffer[1] !== '[') {
    $ch = $this->buffer[1];
    $this->buffer = substr($this->buffer, 2);
    return new KeyMsg('alt+' . $ch, "\x1b" . $ch, alt: true);
   }

   // If starts with \x1b[ but incomplete, wait if buffer is short
   if (strlen($this->buffer) < 4 && (str_starts_with($this->buffer, "\x1b[") || str_starts_with($this->buffer, "\x1b]"))) {
    return null;
   }

   // Unknown or unhandled escape sequence: discard sequence up to terminator
   if (preg_match('/^\x1b\[[0-9;]*[a-zA-Z~]/', $this->buffer, $m)) {
    $this->buffer = substr($this->buffer, strlen($m[0]));
    return null;
   }

   // Lone ESC
   $this->buffer = substr($this->buffer, 1);
   return new KeyMsg(KeyMsg::ESC, "\x1b");
  }

  // 4. Control keys (0x01 - 0x1A)
  $ord = ord($this->buffer[0]);

  if ($ord === 13 || $ord === 10) { // \r or \n
   $this->buffer = substr($this->buffer, 1);
   return new KeyMsg(KeyMsg::ENTER, "\n");
  }

  if ($ord === 9) { // \t
   $this->buffer = substr($this->buffer, 1);
   return new KeyMsg(KeyMsg::TAB, "\t");
  }

  if ($ord === 127 || $ord === 8) { // Backspace
   $this->buffer = substr($this->buffer, 1);
   return new KeyMsg(KeyMsg::BACKSPACE, chr($ord));
  }

  if ($ord >= 1 && $ord <= 26) { // Ctrl+A to Ctrl+Z
   $ctrlChar = chr(ord('a') + $ord - 1);
   $this->buffer = substr($this->buffer, 1);
   return new KeyMsg('ctrl+' . $ctrlChar, chr($ord), ctrl: true);
  }

  // 5. Multi-key sequences like 'gd' (goto definition) and 'gr' (find references)
  if (strlen($this->buffer) >= 2) {
   $prefix2 = substr($this->buffer, 0, 2);
   if ($prefix2 === 'gd' || $prefix2 === 'gr') {
    $this->buffer = substr($this->buffer, 2);
    return new KeyMsg($prefix2, $prefix2);
   }
  }

  // 6. UTF-8 runes
  $char = mb_substr($this->buffer, 0, 1, 'UTF-8');
  $byteLen = strlen($char);
  $this->buffer = substr($this->buffer, $byteLen);

  return new KeyMsg($char, $char);
 }
}
