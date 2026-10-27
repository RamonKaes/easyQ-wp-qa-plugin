#!/usr/bin/env bash
#
# easyQ – the local CI: every check for the commit that is pushed, once per push, in its own worktree.
# Replaces the GitHub Actions workflow; the pre-push hook (bin/pre-push.sh) runs it.
#
#   bash bin/ci.sh [--full] [commit]      Default: HEAD
#
# - Own worktree in ~/easyq-wp-audit/easyq (the folder must be called `easyq`: plugin slug and text domain).
#   vendor/ and node_modules/ stay between runs. Uncommitted changes in the project are not part of the run.
# - Own instance of the test environment (.wp-env.test.json) on port 8891, so the development and test
#   environments on 8888/8889 keep running. It is stopped after the run.
# - Scope from the difference to the last GREEN run: only Markdown changed → Markdown lint only;
#   otherwise everything. `--full` additionally runs the integration tests on the whole matrix
#   (latest WordPress with PHP 8.4 and 8.5, WordPress 6.7 with PHP 8.4, which predates PHP 8.5); the default
#   run uses latest WordPress with PHP 8.4.
# - Unit tests run on PHP 8.4 and 8.5 in Docker images (php:<version>-cli).
# - Failing steps are collected instead of stopping at the first one.
#
# Result: `result.txt` in the log folder of the commit, last line `GREEN <commit>` or `RED <commit> at …`.
#
set -uo pipefail

FULL=false
if [ "${1:-}" = "--full" ]; then FULL=true; shift; fi

PROJECT=$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)
COMMIT=$(git -C "$PROJECT" rev-parse --verify "${1:-HEAD}^{commit}") || exit 2
SHORT=$(git -C "$PROJECT" rev-parse --short "$COMMIT")
ROOT=${CI_ROOT:-$HOME/easyq-wp-audit}
TREE=$ROOT/easyq
LOGS=$ROOT/logs/$SHORT
RESULT=$LOGS/result.txt

# The test environment; npm run test:php and plugin-check use it as well.
export WP_ENV_CONFIG=.wp-env.test.json
export WP_ENV_PORT=${CI_PORT:-8891}
export WP_BASE_URL=http://localhost:$WP_ENV_PORT

mkdir -p "$LOGS"
: > "$RESULT"
report() { echo "$*" | tee -a "$RESULT"; }

FAILED=""
step() {
	local name=$1; shift
	local start=$SECONDS
	if "$@" > "$LOGS/$name.log" 2>&1; then
		report "$name: green ($((SECONDS - start)) s)"
	else
		report "$name: RED ($((SECONDS - start)) s, $LOGS/$name.log)"
		grep -E 'FAILURES|ERROR|✘|Error:|^[0-9]+\) ' "$LOGS/$name.log" | head -20 | sed 's/^/    /' | tee -a "$RESULT"
		FAILED="$FAILED $name"
		# Without worktree, dependencies or environment no further step can run.
		case $name in setup | environment) report "RED $SHORT at $name"; exit 1 ;; esac
	fi
}

# Files changed since the last green run, or ALL if there is none or it is not an ancestor of this commit.
changed() {
	local base
	base=$(cat "$ROOT/green" 2>/dev/null || true)
	if [ -n "$base" ] && git -C "$PROJECT" merge-base --is-ancestor "$base" "$COMMIT" 2>/dev/null; then
		git -C "$PROJECT" diff --name-only "$base" "$COMMIT"
	else
		echo ALL
	fi
}

SINCE=ALL
[ "$FULL" = false ] && SINCE=$(changed)

if [ -z "$SINCE" ]; then
	report "Nothing changed since the last green run."
	report "GREEN $SHORT"
	exit 0
fi

# The worktree: created on the first run, then moved to the commit. `git clean` without `-x` keeps ignored
# files (vendor, node_modules, build) and makes the next run faster.
if git -C "$TREE" rev-parse --git-dir > /dev/null 2>&1; then
	git -C "$TREE" checkout -q --detach "$COMMIT" && git -C "$TREE" clean -qfd
else
	mkdir -p "$ROOT" && git -C "$PROJECT" worktree add -q --detach "$TREE" "$COMMIT"
fi || { report "RED $SHORT at worktree"; exit 1; }

cd "$TREE" || exit 1
report "CI for $SHORT in $TREE, logs in $LOGS"

if ! grep -qvE '\.md$' <<< "$SINCE"; then
	report "Only Markdown changed since the last green run."
	step setup npm ci --no-audit --no-fund
	step lint-md npm run lint:md
	if [ -n "$FAILED" ]; then report "RED $SHORT at$FAILED"; exit 1; fi
	echo "$COMMIT" > "$ROOT/green"
	report "GREEN $SHORT"
	exit 0
fi

setup() {
	composer install --no-interaction --no-progress \
		&& npm ci --no-audit --no-fund \
		&& npm run build
}

unit() {
	local php code=0
	for php in 8.4 8.5; do
		echo "== PHP $php"
		docker run --rm -u "$(id -u):$(id -g)" -v "$TREE:/app" -w /app "php:$php-cli" \
			vendor/bin/phpunit -c phpunit-unit.xml.dist || code=1
	done
	return $code
}

# Starts the environment with a WordPress and PHP version; `null` is the latest WordPress.
environment() {
	echo "{ \"core\": $1, \"phpVersion\": \"$2\" }" > .wp-env.test.override.json
	npx wp-env start --config="$WP_ENV_CONFIG"
}

stop_environment() {
	npx wp-env stop --config="$WP_ENV_CONFIG" > /dev/null 2>&1
	rm -f .wp-env.test.override.json
}
trap stop_environment EXIT

plugin_check() {
	npx wp-env run --config="$WP_ENV_CONFIG" cli wp plugin is-installed plugin-check \
		|| npx wp-env run --config="$WP_ENV_CONFIG" cli wp plugin install plugin-check || return 1
	npx wp-env run --config="$WP_ENV_CONFIG" cli wp plugin activate plugin-check easyq && npm run plugin-check
}

step setup setup
step lint composer lint
step analyse composer analyse
step lint-js npm run lint:js
step lint-md npm run lint:md
step lint-pkg-json npm run lint:pkg-json
step unit unit

if [ "$FULL" = true ]; then
	MATRIX="null:8.4 null:8.5 \"WordPress/WordPress#6.7\":8.4"
else
	MATRIX="null:8.4"
fi

for entry in $MATRIX; do
	core=${entry%:*}
	php=${entry##*:}
	label=$( [ "$core" = null ] && echo latest || echo 6.7 )-php$php
	step "environment-$label" environment "$core" "$php"
	step "integration-$label" npm run test:php
	step "multisite-$label" npm run test:php:multisite
done

# End-to-end tests and Plugin Check on the last environment (latest WordPress and PHP 8.4 in the default run).
# The integration tests reinstalled the test site; the end-to-end tests activate the plugin themselves.
step e2e npx wp-scripts test-playwright --output="$LOGS/playwright"
step plugin-check plugin_check

if [ -n "$FAILED" ]; then report "RED $SHORT at$FAILED"; exit 1; fi
echo "$COMMIT" > "$ROOT/green"
report "GREEN $SHORT"
