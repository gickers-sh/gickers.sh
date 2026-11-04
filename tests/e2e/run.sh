#!/bin/sh
# End-to-end run of `soft serve` on throwaway data and ports (spec §3):
# --dry-run → ssh `repo create` → git push over SSH → clone over HTTP and git:// → SSH `repo tree`
# → signed webhook delivery on push → `git lfs push` → `pr create` / `pr merge`.
set -eu

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
SSH_PORT=${E2E_SSH_PORT:-29231}
HTTP_PORT=${E2E_HTTP_PORT:-29232}
GIT_PORT=${E2E_GIT_PORT:-29418}
HOOK_PORT=${E2E_HOOK_PORT:-29233}
WORK=$(mktemp -d "${TMPDIR:-/tmp}/codes-sh-e2e.XXXXXX")
SERVE_PID=
HOOK_PID=

cleanup() {
    status=$?
    if [ -n "$SERVE_PID" ] && kill -0 "$SERVE_PID" 2>/dev/null; then
        kill -TERM "$SERVE_PID"
        wait "$SERVE_PID" 2>/dev/null || true
    fi
    [ -z "$HOOK_PID" ] || kill "$HOOK_PID" 2>/dev/null || true
    if [ "$status" -ne 0 ]; then
        echo "!! e2e failed; server log:" >&2
        cat "$WORK/serve.log" >&2 || true
    fi
    rm -rf "$WORK"
    exit "$status"
}
trap cleanup EXIT INT TERM

step() { printf '==> %s\n' "$*"; }

ssh-keygen -q -N '' -t ed25519 -f "$WORK/id_ed25519" -C e2e

export APP_ENV=dev
export SOFT_SERVE_DATA_PATH="$WORK/data"
export DATABASE_URL="sqlite:///$WORK/data/codes-sh.db"
export SOFT_SERVE_INITIAL_ADMIN_KEYS="$(cat "$WORK/id_ed25519.pub")"
export SOFT_SERVE_SSH_LISTEN_ADDR="127.0.0.1:$SSH_PORT"
export SOFT_SERVE_SSH_PUBLIC_URL="ssh://127.0.0.1:$SSH_PORT"
export SOFT_SERVE_HTTP_LISTEN_ADDR="127.0.0.1:$HTTP_PORT"
export SOFT_SERVE_HTTP_PUBLIC_URL="http://127.0.0.1:$HTTP_PORT"
export SOFT_SERVE_GIT_LISTEN_ADDR="127.0.0.1:$GIT_PORT"
# Lets the webhook reach the local capture server (creation-time SSRF check and delivery client).
export SOFT_SERVE_WEBHOOK_ALLOW_LIST=127.0.0.1

step "soft serve --dry-run"
"$ROOT/bin/soft" serve --dry-run >"$WORK/dry-run.log" 2>&1
grep -q 'Dry run: nothing started.' "$WORK/dry-run.log"

step "starting webhook capture server (:$HOOK_PORT)"
mkdir -p "$WORK/hooks"
cat >"$WORK/capture.php" <<'PHP'
<?php
$h = getallheaders();
$body = file_get_contents('php://input');
file_put_contents(getenv('CAPTURE_DIR').'/'.($h['X-SoftServe-Event'] ?? 'unknown').'-'.uniqid().'.json', json_encode(['headers' => $h, 'body' => $body]));
PHP
CAPTURE_DIR="$WORK/hooks" php -S "127.0.0.1:$HOOK_PORT" "$WORK/capture.php" >"$WORK/capture.log" 2>&1 &
HOOK_PID=$!

step "starting soft serve (ssh :$SSH_PORT, http :$HTTP_PORT, git :$GIT_PORT)"
"$ROOT/bin/soft" serve >"$WORK/serve.log" 2>&1 &
SERVE_PID=$!

for port in "$SSH_PORT" "$HTTP_PORT" "$GIT_PORT"; do
    i=0
    until nc -z 127.0.0.1 "$port" 2>/dev/null; do
        i=$((i + 1))
        [ "$i" -le 150 ] || { echo "!! port $port never opened" >&2; exit 1; }
        kill -0 "$SERVE_PID" 2>/dev/null || { echo "!! soft serve exited" >&2; exit 1; }
        sleep 0.2
    done
done

SSH_CMD="ssh -i $WORK/id_ed25519 -p $SSH_PORT -o IdentitiesOnly=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR"
export GIT_SSH_COMMAND="$SSH_CMD"
export GIT_TERMINAL_PROMPT=0

step "ssh repo create demo"
# ssh joins its arguments with spaces: quote once for the remote StringInput.
$SSH_CMD 127.0.0.1 'repo create demo -d "e2e demo"'
$SSH_CMD 127.0.0.1 repo list | grep -qx demo

step "git push over SSH"
git init -q -b main "$WORK/src"
printf 'hello e2e\n' >"$WORK/src/README.md"
git -C "$WORK/src" add README.md
git -C "$WORK/src" -c user.name=e2e -c user.email=e2e@codes.sh commit -qm "initial commit"
git -C "$WORK/src" remote add origin "ssh://127.0.0.1:$SSH_PORT/demo"
git -C "$WORK/src" push -q origin main

step "git clone over HTTP"
git clone -q "http://127.0.0.1:$HTTP_PORT/demo.git" "$WORK/http-clone"
grep -qx 'hello e2e' "$WORK/http-clone/README.md"

step "git clone over git://"
git clone -q "git://127.0.0.1:$GIT_PORT/demo" "$WORK/git-clone"
grep -qx 'hello e2e' "$WORK/git-clone/README.md"

step "ssh repo tree demo"
$SSH_CMD 127.0.0.1 repo tree demo | grep -q README.md

step "signed webhook delivered on push"
$SSH_CMD 127.0.0.1 "repo webhook create demo http://127.0.0.1:$HOOK_PORT/hook -s e2e-secret -e push -a"
printf 'webhook\n' >"$WORK/src/hook.txt"
git -C "$WORK/src" add hook.txt
git -C "$WORK/src" -c user.name=e2e -c user.email=e2e@codes.sh commit -qm "trigger webhook"
git -C "$WORK/src" push -q origin main
i=0
until ls "$WORK"/hooks/push-*.json >/dev/null 2>&1; do
    i=$((i + 1))
    [ "$i" -le 100 ] || { echo "!! no webhook delivery received" >&2; exit 1; }
    sleep 0.2
done
php -r '
    $d = json_decode(file_get_contents($argv[1]), true);
    $expected = "sha256=".hash_hmac("sha256", $d["body"], "e2e-secret");
    $got = $d["headers"]["X-SoftServe-Signature"] ?? "";
    if (!hash_equals($expected, $got)) { fwrite(STDERR, "!! bad signature: $got\n"); exit(1); }
    if (!str_contains($d["body"], "\"demo\"")) { fwrite(STDERR, "!! payload lacks repository\n"); exit(1); }
' "$(ls "$WORK"/hooks/push-*.json | head -1)"

step "git lfs push (git-lfs-authenticate over SSH, batch API over HTTP)"
git -C "$WORK/src" lfs install --local >/dev/null
git -C "$WORK/src" lfs track '*.bin' >/dev/null
head -c 4096 /dev/urandom >"$WORK/src/blob.bin"
git -C "$WORK/src" add .gitattributes blob.bin
git -C "$WORK/src" -c user.name=e2e -c user.email=e2e@codes.sh commit -qm "lfs object"
git -C "$WORK/src" push -q origin main
GIT_LFS_SKIP_SMUDGE=0 git clone -q "ssh://127.0.0.1:$SSH_PORT/demo" "$WORK/lfs-clone"
cmp -s "$WORK/src/blob.bin" "$WORK/lfs-clone/blob.bin" || { echo "!! LFS object not restored on clone" >&2; exit 1; }

step "pr create / pr merge"
git -C "$WORK/src" checkout -q -b feature
printf 'feature\n' >"$WORK/src/feature.txt"
git -C "$WORK/src" add feature.txt
git -C "$WORK/src" -c user.name=e2e -c user.email=e2e@codes.sh commit -qm "add feature"
git -C "$WORK/src" push -q origin feature
$SSH_CMD 127.0.0.1 'pr create demo -H feature -B main -t "Add feature"'
$SSH_CMD 127.0.0.1 'pr merge demo 1 -s merge'
git -C "$WORK/src" fetch -q origin main
git -C "$WORK/src" log -1 --format=%P origin/main | grep -q ' ' || { echo "!! main head is not a merge commit" >&2; exit 1; }
git -C "$WORK/src" ls-tree -r --name-only origin/main | grep -qx feature.txt

if command -v expect >/dev/null 2>&1; then
    step "ssh tui: SelectionPage → RepoPage (README) → back → quit"
    # shellcheck disable=SC2086 # SSH_CMD is a word list on purpose.
    expect -f - $SSH_CMD -tt 127.0.0.1 tui <<'EXPECT' >"$WORK/tui.log" 2>&1 || { cat "$WORK/tui.log" >&2; exit 1; }
set timeout 20
spawn {*}$argv
expect { "My Repositories" {} timeout { exit 1 } }
expect { "demo" {} timeout { exit 2 } }
send "\r"
expect { "hello e2e" {} timeout { exit 3 } }
send "q"
expect { "My Repositories" {} timeout { exit 4 } }
send "\003"
expect { eof {} timeout { exit 5 } }
EXPECT
else
    step "ssh tui: skipped (expect not installed)"
fi

step "e2e OK"
