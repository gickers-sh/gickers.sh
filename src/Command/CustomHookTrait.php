<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

trait CustomHookTrait
{
 private ?string $inputStream = null;

 /**
  * Sets custom input stream data (useful for testing without php://stdin).
  */
 public function setInputStream(?string $input): void
 {
  $this->inputStream = $input;
 }

 /**
  * Reads stdin data from explicit input stream, env, or php://stdin.
  */
 protected function getStdinData(): string
 {
  if ($this->inputStream !== null) {
   return $this->inputStream;
  }

  $envInput = getenv('SOFT_SERVE_HOOK_INPUT');
  if ($envInput !== false && $envInput !== '') {
   return $envInput;
  }

  $data = @file_get_contents('php://stdin');
  return $data !== false ? $data : '';
 }

 /**
  * Executes custom hook at $customHookPath if it exists and is executable.
  * Matches runCustomHook in cmd/soft/hook/hook.go.
  *
  * @param string $customHookPath Path to custom hook script
  * @param string|null $stdin Stdin data to pipe to custom hook
  * @param list<string> $args CLI arguments
  * @param OutputInterface|null $output Output interface
  * @return int Exit code (0 on success or if hook missing/not executable)
  */
 protected function runCustomHook(
  string $customHookPath,
  ?string $stdin = null,
  array $args = [],
  ?OutputInterface $output = null,
 ): int {
  if (!is_file($customHookPath) || !is_executable($customHookPath)) {
   return 0;
  }

  $process = new Process([$customHookPath, ...$args]);
  if ($stdin !== null && $stdin !== '') {
   $process->setInput($stdin);
  }
  $process->setTimeout(null);

  return $process->run(function (string $type, string $buffer) use ($output): void {
   if ($output !== null) {
    $output->write($buffer);
   }
  });
 }
}
