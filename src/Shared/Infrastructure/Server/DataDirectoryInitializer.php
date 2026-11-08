<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Server;

use App\Shared\Infrastructure\Config\ServerSettings;

/**
 * Creates the data directory layout and the `hooks/update.sample` example hook
 * (Go `cmd/soft/serve/serve.go` + `pkg/config` bootstrap).
 */
final readonly class DataDirectoryInitializer
{
    private const UPDATE_HOOK_SAMPLE = <<<'SH'
        #!/bin/sh
        #
        # An example hook script to echo information about the push
        # and send it to the client.
        #
        # To enable this hook, rename this file to "update" and make it executable.

        refname="$1"
        oldrev="$2"
        newrev="$3"

        # Safety check
        if [ -z "$GIT_DIR" ]; then
                echo "Don't run this script from the command line." >&2
                echo " (if you want, you could supply GIT_DIR then run" >&2
                echo "  $0 <ref> <oldrev> <newrev>)" >&2
                exit 1
        fi

        if [ -z "$refname" -o -z "$oldrev" -o -z "$newrev" ]; then
                echo "usage: $0 <ref> <oldrev> <newrev>" >&2
                exit 1
        fi

        # Check types
        # if $newrev is 0000...0000, it's a commit to delete a ref.
        zero=$(git hash-object --stdin </dev/null | tr '[0-9a-f]' '0')
        if [ "$newrev" = "$zero" ]; then
                newrev_type=delete
        else
                newrev_type=$(git cat-file -t $newrev)
        fi

        echo "Hi from Soft Serve update hook!"
        echo
        echo "Repository: $SOFT_SERVE_REPO_NAME"
        echo "RefName: $refname"
        echo "Change Type: $newrev_type"
        echo "Old SHA1: $oldrev"
        echo "New SHA1: $newrev"

        exit 0

        SH;

    public function __construct(private ServerSettings $settings)
    {
    }

    public function initialize(): void
    {
        $dirs = [
            $this->settings->dataPath => 0o755,
            $this->settings->sshPath() => 0o700,
            $this->settings->hooksPath() => 0o755,
            $this->settings->dataPath.'/log' => 0o755,
            $this->settings->reposPath() => 0o755,
            $this->settings->lfsPath() => 0o755,
        ];
        foreach ($dirs as $dir => $mode) {
            if (!is_dir($dir) && !mkdir($dir, $mode, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Failed to create directory "%s"', $dir));
            }
        }

        $sample = $this->settings->hooksPath().'/update.sample';
        if (!is_file($sample)) {
            if (false === file_put_contents($sample, self::UPDATE_HOOK_SAMPLE)) {
                throw new \RuntimeException(sprintf('Failed to write "%s"', $sample));
            }
            chmod($sample, 0o744);
        }
    }
}
