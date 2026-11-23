#!/bin/sh
# PostToolUse(Edit|Write) — run php-cs-fixer on the one file that was just edited.
#
# Why a script and not an inline command: the pipeline needs three guards (is it
# PHP, is it inside the project, is it under src/ or tests/) and a readable
# failure message. A one-liner in settings.json hides all of that.
#
# Claude Code pipes the tool payload on stdin:
#   {"tool_name":"Edit","tool_input":{"file_path":"/abs/path/File.php"},...}
#
# This is style only. PHPStan and PHPUnit are still the gates — see
# .claude/rules/technical/linting.md.
set -eu

# Hooks do not inherit an interactive shell's PATH: jq lives in Homebrew's prefix
# and docker in /usr/local/bin.
PATH="/opt/homebrew/bin:/usr/local/bin:$PATH"
export PATH

command -v jq >/dev/null 2>&1 || exit 0

path=$(jq -r '.tool_input.file_path // empty')
[ -n "$path" ] || exit 0

# PHP only.
case "$path" in
    *.php) ;;
    *) exit 0 ;;
esac

root="${CLAUDE_PROJECT_DIR:-$(pwd)}"
rel=${path#"$root"/}
# Unchanged means the file is outside the project — nothing to format here.
[ "$rel" != "$path" ] || exit 0

# src/ and tests/ only. migrations/, var/, vendor/ and the two generated config
# files are excluded by .php-cs-fixer.dist.php, and --path-mode=intersection
# below honours that finder instead of overriding it — the default for an
# explicit path argument is to override, which would reformat a migration.
case "$rel" in
    src/*|tests/*) ;;
    *) exit 0 ;;
esac

# The container bind-mounts ./:/app with WORKDIR /app, so the relative path is
# the same on both sides and the fix lands on the host file.
if ! out=$(docker exec -i cookbook_app vendor/bin/php-cs-fixer fix "$rel" \
        --path-mode=intersection --quiet 2>&1); then
    printf 'php-cs-fixer did not run on %s (is `make up` running?):\n%s\n' "$rel" "$out" >&2
    # Exit 2 on PostToolUse reports back to Claude, not to the user. The edit
    # itself already succeeded and is not reverted.
    exit 2
fi

exit 0
