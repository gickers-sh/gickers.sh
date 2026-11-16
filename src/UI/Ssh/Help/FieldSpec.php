<?php

declare(strict_types=1);

namespace App\UI\Ssh\Help;

/**
 * An interactive positional argument or option specification used by web interfaces
 * to render editable controls and construct copyable shell commands.
 */
final readonly class FieldSpec
{
    /**
     * @param string       $kind        argument kind: 'arg', 'choice-arg', 'flag', 'choice-flag', or 'bool'
     * @param string       $key         parameter identifier / token name
     * @param string       $label       human-readable label
     * @param string       $placeholder text placeholder for input
     * @param bool         $required    whether the parameter is mandatory
     * @param string       $default     initial default value
     * @param string       $flag        CLI flag prefix (e.g. '-c', '--no-force')
     * @param list<string> $choices     allowed values for choice kinds
     */
    public function __construct(
        public string $kind,
        public string $key,
        public string $label,
        public string $placeholder = '',
        public bool $required = false,
        public string $default = '',
        public string $flag = '',
        public array $choices = [],
    ) {}

    public static function arg(string $key, string $label, string $placeholder = '', bool $required = false, string $default = ''): self
    {
        return new self('arg', $key, $label, $placeholder, $required, $default);
    }

    /**
     * @param list<string> $choices
     */
    public static function choiceArg(string $key, string $label, array $choices, string $default): self
    {
        return new self('choice-arg', $key, $label, '', true, $default, '', $choices);
    }

    public static function opt(string $key, string $label, string $flag, string $placeholder = '', string $default = ''): self
    {
        return new self('flag', $key, $label, $placeholder, false, $default, $flag);
    }

    /**
     * @param list<string> $choices
     */
    public static function choiceOpt(string $key, string $label, string $flag, array $choices, string $default): self
    {
        return new self('choice-flag', $key, $label, '', false, $default, $flag, $choices);
    }

    public static function boolOpt(string $key, string $label, string $flag, bool $default = false): self
    {
        return new self('bool', $key, $label, '', false, $default ? '1' : '', $flag);
    }

    /**
     * @return array{kind: string, key: string, label: string, placeholder: string, required: bool, default: string, flag: string, choices: list<string>}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'key' => $this->key,
            'label' => $this->label,
            'placeholder' => $this->placeholder,
            'required' => $this->required,
            'default' => $this->default,
            'flag' => $this->flag,
            'choices' => $this->choices,
        ];
    }
}
