<?php

declare(strict_types=1);

namespace App\UI\Ssh\Help;

/**
 * Renders a {@see CommandSpec} exactly like upstream Soft Serve's cobra
 * help output (custom UsageTemplate + pflag FlagUsages), so that
 * `ssh <host> help …` matches the Go server byte-for-byte.
 */
final class HelpRenderer
{
    /**
     * @param string $sshCommand the "ssh [-p PORT] HOST" prefix, derived from the SSH public URL
     */
    public function render(CommandSpec $command, string $sshCommand): string
    {
        $out = '';
        $desc = '' !== $command->long ? $command->long : $command->short;
        if ('' !== $desc) {
            $out .= rtrim($desc)."\n\n";
        }

        if ($command->runnable || $command->hasChildren()) {
            $out .= $this->usageString($command, $sshCommand);
        }

        return $out;
    }

    private function usageString(CommandSpec $command, string $sshCommand): string
    {
        $out = 'Usage:';

        if ($command->runnable) {
            $out .= "\n  ".$this->useLine($command);
        }

        if ($command->hasChildren()) {
            $out .= "\n  ".$sshCommand.$command->commandPath().' [command]';
        }

        if ([] !== $command->aliases) {
            $out .= "\n\nAliases:\n  ".$command->name.', '.implode(', ', $command->aliases);
        }

        if ($command->hasChildren()) {
            $padding = $command->namePadding();
            $children = $command->visibleChildren();
            usort($children, static fn (CommandSpec $a, CommandSpec $b): int => strcmp($a->name, $b->name));

            $out .= "\n\nAvailable Commands:";
            foreach ($children as $child) {
                $out .= "\n  ".$this->rpad($child->name, $padding).' '.$child->short;
            }
        }

        $out .= "\n\nFlags:\n".$this->flagUsages($command);

        if ($command->hasChildren()) {
            $out .= "\n\nUse \"".$sshCommand.$command->commandPath().' [command] --help" for more information about a command.';
        }

        return $out."\n";
    }

    private function useLine(CommandSpec $command): string
    {
        $parentPath = null !== $command->parent ? $command->parent->commandPath() : '';
        $line = $parentPath.' '.$command->use;

        // Every command has at least the implicit --help flag.
        if (!str_contains($line, '[flags]')) {
            $line .= ' [flags]';
        }

        return $line;
    }

    /**
     * Reproduces pflag's FlagUsagesWrapped(0) followed by trimTrailingWhitespaces.
     */
    private function flagUsages(CommandSpec $command): string
    {
        $flags = [...$command->flags, $this->helpFlag($command)];
        usort($flags, static fn (FlagSpec $a, FlagSpec $b): int => strcmp($a->name, $b->name));

        $prefixes = [];
        $usages = [];
        $maxlen = 0;
        foreach ($flags as $flag) {
            if (null !== $flag->shorthand && '' !== $flag->shorthand) {
                $prefix = '  -'.$flag->shorthand.', --'.$flag->name;
            } else {
                $prefix = '      --'.$flag->name;
            }
            if ('' !== $flag->varname) {
                $prefix .= ' '.$flag->varname;
            }

            $usage = $flag->usage;
            if (null !== $flag->defaultText) {
                $usage .= ' (default '.$flag->defaultText.')';
            }

            $prefixes[] = $prefix;
            $usages[] = $usage;

            // pflag compares len(prefix)+1 (the \x00 marker) against maxlen.
            $len = strlen($prefix) + 1;
            if ($len > $maxlen) {
                $maxlen = $len;
            }
        }

        $lines = [];
        foreach ($prefixes as $i => $prefix) {
            $sidx = strlen($prefix);
            // Fprintln joins "prefix", spacing, usage with single spaces:
            // prefix + " " + repeat(" ", maxlen - sidx) + " " + usage
            $spacing = str_repeat(' ', $maxlen - $sidx);
            $lines[] = $prefix.' '.$spacing.' '.$usages[$i];
        }

        return rtrim(implode("\n", $lines));
    }

    private function helpFlag(CommandSpec $command): FlagSpec
    {
        $usage = null === $command->parent
            ? 'help for this command'
            : 'help for '.$command->name;

        return new FlagSpec('h', 'help', '', $usage, null);
    }

    private function rpad(string $value, int $padding): string
    {
        return str_pad($value, $padding);
    }

    /**
     * Derives the "ssh [-p PORT] HOST" prefix from the SSH public URL, exactly
     * like upstream Soft Serve's UsageFunc.
     */
    public static function sshCommandFromPublicUrl(string $publicUrl): string
    {
        $hostname = 'localhost';
        $port = '23231';

        $parsed = parse_url($publicUrl);
        if (false !== $parsed && isset($parsed['host'])) {
            $hostname = $parsed['host'];
            $port = isset($parsed['port']) ? (string) $parsed['port'] : '';
        }

        $sshCommand = 'ssh';
        if ('' !== $port && '22' !== $port) {
            $sshCommand .= ' -p '.$port;
        }

        return $sshCommand.' '.$hostname;
    }
}
