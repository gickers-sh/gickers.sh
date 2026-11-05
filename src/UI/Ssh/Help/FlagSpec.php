<?php

declare(strict_types=1);

namespace App\UI\Ssh\Help;

/**
 * A single command-line flag, mirroring a cobra/pflag flag for help rendering.
 */
final readonly class FlagSpec
{
    /**
     * @param ?string $shorthand   single-letter shorthand, or null when the flag has none
     * @param string  $name        long flag name (without leading --)
     * @param string  $varname     placeholder shown after the flag (e.g. "string", "strings", "json"); empty for booleans
     * @param string  $usage       help text
     * @param ?string $defaultText rendered default value inside "(default ...)", already quoted when needed (e.g. "\"json\"", "true"); null when the default is the zero value
     */
    public function __construct(
        public ?string $shorthand,
        public string $name,
        public string $varname = '',
        public string $usage = '',
        public ?string $defaultText = null,
    ) {
    }
}
