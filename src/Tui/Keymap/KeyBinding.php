<?php

declare(strict_types=1);

namespace App\Tui\Keymap;

use App\Tui\Event\KeyMsg;

/**
 * KeyBinding represents a keybinding with multiple trigger keys and help text.
 */
final readonly class KeyBinding
{
    /** @var string[] */
    public array $keys;
    public string $helpKey;
    public string $helpDesc;
    public bool $enabled;

    /**
     * @param string|string[] $keys
     */
    public function __construct(string|array $keys, string $helpKey = '', string $helpDesc = '', bool $enabled = true)
    {
        $this->keys = is_array($keys) ? array_values($keys) : [$keys];
        $this->helpKey = $helpKey !== '' ? $helpKey : ($this->keys[0] ?? '');
        $this->helpDesc = $helpDesc;
        $this->enabled = $enabled;
    }

    public function matches(KeyMsg|string $key): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $keyStr = $key instanceof KeyMsg ? $key->key : $key;

        foreach ($this->keys as $k) {
            if ($k === $keyStr) {
                return true;
            }
        }

        return false;
    }
}
