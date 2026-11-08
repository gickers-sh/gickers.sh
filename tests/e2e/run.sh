#!/bin/sh
# End-to-end run of `soft serve` on throwaway data and ports (spec §3):
# --dry-run → ssh `repo create` → git push over SSH → clone over HTTP → SSH `repo tree`
# → `git lfs push` → TUI navigation.
set -eu

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
SSH_PORT=${E2E_SSH_PORT:-29231}
HTTP_PORT=${E2E_HTTP_PORT:-29232}
WORK=$(mktemp -d "${TMPDIR:-/tmp}/codes-sh-e2e.XXXXXX")
SERVE_PID=

cleanup() {
    status=$?
    if [ -n "$SERVE_PID" ] && kill -0 "$SERVE_PID" 2>/dev/null; then
        kill -TERM "$SERVE_PID"
        wait "$SERVE_PID" 2>/dev/null || true
    fi
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

export APP_ENV="${E2E_APP_ENV:-dev}"
export SOFT_SERVE_DATA_PATH="$WORK/data"
export DATABASE_URL="sqlite:///$WORK/data/codes-sh.db"
export SOFT_SERVE_INITIAL_ADMIN_KEYS="$(cat "$WORK/id_ed25519.pub")"
export SOFT_SERVE_SSH_LISTEN_ADDR="127.0.0.1:$SSH_PORT"
export SOFT_SERVE_SSH_PUBLIC_URL="ssh://127.0.0.1:$SSH_PORT"
export SOFT_SERVE_HTTP_LISTEN_ADDR="127.0.0.1:$HTTP_PORT"
export SOFT_SERVE_HTTP_PUBLIC_URL="http://127.0.0.1:$HTTP_PORT"

step "soft serve --dry-run"
"$ROOT/bin/soft" serve --dry-run >"$WORK/dry-run.log" 2>&1
grep -q 'Dry run: nothing started.' "$WORK/dry-run.log"

step "starting soft serve (ssh :$SSH_PORT, http :$HTTP_PORT)"
"$ROOT/bin/soft" serve >"$WORK/serve.log" 2>&1 &
SERVE_PID=$!

for port in "$SSH_PORT" "$HTTP_PORT"; do
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

step "ssh repo tree demo"
$SSH_CMD 127.0.0.1 repo tree demo | grep -q README.md

step "git lfs push (git-lfs-authenticate over SSH, batch API over HTTP)"
git -C "$WORK/src" lfs install --local >/dev/null
git -C "$WORK/src" lfs track '*.bin' >/dev/null
head -c 4096 /dev/urandom >"$WORK/src/blob.bin"
git -C "$WORK/src" add .gitattributes blob.bin
git -C "$WORK/src" -c user.name=e2e -c user.email=e2e@codes.sh commit -qm "lfs object"
git -C "$WORK/src" push -q origin main
GIT_LFS_SKIP_SMUDGE=0 git clone -q "ssh://127.0.0.1:$SSH_PORT/demo" "$WORK/lfs-clone"
cmp -s "$WORK/src/blob.bin" "$WORK/lfs-clone/blob.bin" || { echo "!! LFS object not restored on clone" >&2; exit 1; }

step "ssh command latency (spec §5: bin/console boots per session; APP_ENV=$APP_ENV)"
php -r '
    $t = [];
    for ($i = 0; $i < 5; ++$i) {
        $s = hrtime(true);
        exec($argv[1]." >/dev/null 2>&1", $o, $rc);
        if (0 !== $rc) { fwrite(STDERR, "!! timed command failed\n"); exit(1); }
        $t[] = (hrtime(true) - $s) / 1e6;
    }
    sort($t);
    printf("    repo list: median %.0f ms (min %.0f, max %.0f)\n", $t[2], $t[0], $t[4]);
' "$SSH_CMD 127.0.0.1 repo list"

if command -v expect >/dev/null 2>&1; then
    step "ssh tui: SelectionPage → RepoPage (README) → back → quit"
    # Multi-line pattern lists: a one-line `expect { ... }` is read as ONE literal pattern and only times out.
    cat >"$WORK/tui.exp" <<'EXPECT'
set timeout 20
proc must {pattern code} {
    expect {
        -exact $pattern {}
        timeout { puts stderr "!! tui: timed out waiting for '$pattern'"; exit $code }
        eof { puts stderr "!! tui: session ended before '$pattern'"; exit $code }
    }
}
spawn {*}$argv
must "My Repositories" 1
send "\t"
must "demo" 2
send "\r"
must "hello e2e" 3
send "q"
must "My Repositories" 4
send "\003"
expect {
    eof {}
    timeout { puts stderr "!! tui: still running after ctrl+c"; exit 5 }
}
EXPECT
    # shellcheck disable=SC2086 # SSH_CMD is a word list on purpose.
    expect -f "$WORK/tui.exp" $SSH_CMD -tt 127.0.0.1 tui >"$WORK/tui.log" 2>&1 </dev/null || { cat "$WORK/tui.log" >&2; exit 1; }
else
    step "ssh tui: skipped (expect not installed)"
fi

step "e2e OK"
