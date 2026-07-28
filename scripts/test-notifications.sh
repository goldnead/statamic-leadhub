#!/usr/bin/env bash
#
# Run the live LeadHub <-> goldnead/statamic-notifications integration suite.
#
# The notifications addon is an OPTIONAL peer: the default test suite runs with
# it absent (so tests/Feature/TaskAssignmentNotificationTest can prove the
# no-op-when-absent contract). This script installs the addon into the dev
# dependencies and runs ONLY the Integration suite, which exercises the real
# both-addons path:
#
#   1. the task-assignment type is registered from LeadHub's ServiceProvider,
#      i.e. it exists in a process that has served no request — which is what
#      the scheduled digest process is
#   2. assigning a task to somebody else persists a notification for them, and
#      assigning it to yourself persists nothing
#   3. the open-task digest source contributes the work the follow-up digest
#      never covered
#
# It does NOT modify the committed composer.json/lock: it works on a throwaway
# copy of the repo so your working tree stays clean.
#
# Usage:
#   scripts/test-notifications.sh
#
#   # point at a local checkout of the addon instead of cloning from GitHub:
#   NOTIFICATIONS_PATH=/path/to/statamic-notifications scripts/test-notifications.sh
#
# Requirements: PHP >=8.2 (sqlite, dom, mbstring, fileinfo, gd), Composer 2.x,
# and (unless NOTIFICATIONS_PATH is set) network access to GitHub.

set -euo pipefail
IFS=$'\n\t'

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NOTIFICATIONS_PATH="${NOTIFICATIONS_PATH:-}"
NOTIFICATIONS_REPO="${NOTIFICATIONS_REPO:-https://github.com/goldnead/statamic-notifications.git}"
IDENTITY_PATH="${IDENTITY_PATH:-}"
IDENTITY_REPO="${IDENTITY_REPO:-https://github.com/goldnead/statamic-identity-contracts.git}"

WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT

echo "==> Staging a throwaway copy of the addon in $WORKDIR"
# The working tree, not HEAD: `git ls-files -co --exclude-standard` lists tracked
# and untracked files while honouring .gitignore, so vendor/ and node_modules/
# stay out and a test written five minutes ago is actually in the copy. The
# webhook-manager script archives HEAD instead, which silently runs an empty
# suite when the tests under examination are not committed yet.
git -C "$REPO_ROOT" ls-files -co --exclude-standard -z \
    | tar -C "$REPO_ROOT" --null -T - -cf - \
    | tar -x -C "$WORKDIR"
cd "$WORKDIR"

echo "==> Registering goldnead/statamic-notifications as a Composer dependency"
# statamic-identity-contracts is a hard requirement of the notifications addon
# and is not on Packagist either, so it needs a repository entry of its own.
#
# A path repository reports the checkout as `dev-main`, which neither the root
# minimum-stability (stable) nor the notifications addon's own `^1.0` constraint
# on identity-contracts accepts. Pinning a version through the repository's
# `versions` option is what makes a local checkout resolvable at all; the vcs
# form used in CI needs none of that because the tags are real.
if [[ -n "$IDENTITY_PATH" ]]; then
    composer config repositories.identity-contracts \
        "{\"type\":\"path\",\"url\":\"$IDENTITY_PATH\",\"options\":{\"versions\":{\"goldnead/statamic-identity-contracts\":\"1.0.0\"}}}"
else
    composer config repositories.identity-contracts vcs "$IDENTITY_REPO"
fi

if [[ -n "$NOTIFICATIONS_PATH" ]]; then
    composer config repositories.notifications \
        "{\"type\":\"path\",\"url\":\"$NOTIFICATIONS_PATH\",\"options\":{\"versions\":{\"goldnead/statamic-notifications\":\"1.0.5\"}}}"
else
    composer config repositories.notifications vcs "$NOTIFICATIONS_REPO"
fi

composer require --dev "goldnead/statamic-notifications:*" \
    --no-interaction --no-progress --with-all-dependencies

echo "==> Running the Integration suite"
vendor/bin/pest --testsuite=Integration --colors=always
