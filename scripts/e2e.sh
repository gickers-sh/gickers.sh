#!/bin/sh
# End-to-end run of `soft serve` on throwaway data and ports (spec §3):
# ssh `repo create` → git push over SSH → clone over HTTP and git:// → SSH `repo tree`.
set -eu

ROOT=$(cd "$(dirname "$0")/.." && pwd)
SSH_PORT=${E2E_SSH_PORT:-29231}
HTTP_PORT=${E2E_HTTP_PORT:-29232}
GIT_PORT=${E2E_GIT_PORT:-29418}
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

export APP_ENV=dev
export SOFT_SERVE_DATA_PATH="$WORK/data"
export DATABASE_URL="sqlite:///$WORK/data/codes-sh.db"
export SOFT_SERVE_INITIAL_ADMIN_KEYS="$(cat "$WORK/id_ed25519.pub")"
export SOFT_SERVE_SSH_LISTEN_ADDR="127.0.0.1:$SSH_PORT"
export SOFT_SERVE_SSH_PUBLIC_URL="ssh://127.0.0.1:$SSH_PORT"
export SOFT_SERVE_HTTP_LISTEN_ADDR="127.0.0.1:$HTTP_PORT"
export SOFT_SERVE_HTTP_PUBLIC_URL="http://127.0.0.1:$HTTP_PORT"
export SOFT_SERVE_GIT_LISTEN_ADDR="127.0.0.1:$GIT_PORT"

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
git -C "$WORK/src" push -q "ssh://127.0.0.1:$SSH_PORT/demo" main

step "git clone over HTTP"
git clone -q "http://127.0.0.1:$HTTP_PORT/demo.git" "$WORK/http-clone"
grep -qx 'hello e2e' "$WORK/http-clone/README.md"

step "git clone over git://"
git clone -q "git://127.0.0.1:$GIT_PORT/demo" "$WORK/git-clone"
grep -qx 'hello e2e' "$WORK/git-clone/README.md"

step "ssh repo tree demo"
$SSH_CMD 127.0.0.1 repo tree demo | grep -q README.md

step "e2e OK"
