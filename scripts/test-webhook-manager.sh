#!/usr/bin/env bash
#
# Run the live LeadHub <-> goldnead/statamic-webhook-manager integration suite.
#
# The webhook-manager addon is an OPTIONAL peer: the default test suite runs
# with it absent (so tests/Feature/WebhookManagerBridgeTest can prove the
# no-op-when-absent contract). This script installs the addon into the dev
# dependencies and runs ONLY the Integration suite, which exercises the real
# both-addons path:
#
#   1. LeadHub lifecycle events register as webhook-manager triggers (CP select)
#   2. a LeadHub event re-emits as the addon's TriggerDetected with a
#      normalized payload
#   3. a configured outbound webhook is delivered end-to-end (snapshot + queued
#      ProcessOutboundDeliveryJob + webhook_deliveries row)
#
# It does NOT modify the committed composer.json/lock: it works on a throwaway
# copy of the repo so your working tree stays clean.
#
# Usage:
#   scripts/test-webhook-manager.sh
#
#   # point at a local checkout of the addon instead of resolving Packagist:
#   WEBHOOK_MANAGER_PATH=/path/to/statamic-webhook-manager scripts/test-webhook-manager.sh
#
#   # test an untagged branch straight from GitHub:
#   WEBHOOK_MANAGER_REPO=https://github.com/goldnead/statamic-webhook-manager.git \
#       scripts/test-webhook-manager.sh
#
# Requirements: PHP >=8.2 (sqlite, dom, mbstring, fileinfo, gd), Composer 2.x,
# and (unless WEBHOOK_MANAGER_PATH is set) network access to Packagist.

set -euo pipefail
IFS=$'\n\t'

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEBHOOK_MANAGER_PATH="${WEBHOOK_MANAGER_PATH:-}"
# Deliberately NOT defaulted to the GitHub URL any more: an empty value means
# "resolve from Packagist", which is the path a real installation takes.
WEBHOOK_MANAGER_REPO="${WEBHOOK_MANAGER_REPO:-}"

WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT

echo "==> Staging a throwaway copy of the addon in $WORKDIR"
# The working tree, not HEAD. This used to be `git archive HEAD`, which was
# wrong twice over:
#
#   1. `git archive` APPLIES export-ignore. Since the .gitattributes added on
#      2026-08-01 marks /tests, /scripts and /phpunit.xml as export-ignore (so
#      the Composer tarball ships code only), the archive contained no test
#      suite and no Pest config at all, and this job died on
#      "The test directory [%s] does not exist."
#   2. HEAD ignores uncommitted work, so a test written five minutes ago was
#      silently absent from the run.
#
# `git ls-files -co --exclude-standard` lists tracked and untracked files while
# honouring .gitignore, so vendor/ and node_modules/ stay out, export-ignore
# does not apply, and the copy is what the developer actually has. Identical to
# how scripts/test-notifications.sh stages its copy.
git -C "$REPO_ROOT" ls-files -co --exclude-standard -z \
    | tar -C "$REPO_ROOT" --null -T - -cf - \
    | tar -x -C "$WORKDIR"
cd "$WORKDIR"

echo "==> Registering goldnead/statamic-webhook-manager as a Composer dependency"
# The suite being run is LeadHub's own tests/Integration — the sibling is only
# needed as an installed library, so a dist install is fine and no source
# checkout is required.
#
# Since 2026-08-01 the addon is on Packagist, so the default path needs no
# repository entry and resolves the released tag a real user would get. The two
# env vars stay as escape hatches: a local checkout, or a branch that has not
# been tagged yet.
if [[ -n "$WEBHOOK_MANAGER_PATH" ]]; then
    composer config repositories.webhook-manager path "$WEBHOOK_MANAGER_PATH"
    WEBHOOK_MANAGER_CONSTRAINT='*@dev'
elif [[ -n "${WEBHOOK_MANAGER_REPO:-}" ]]; then
    composer config repositories.webhook-manager vcs "$WEBHOOK_MANAGER_REPO"
    WEBHOOK_MANAGER_CONSTRAINT='*@dev'
else
    WEBHOOK_MANAGER_CONSTRAINT='*'
fi

composer require --dev "goldnead/statamic-webhook-manager:${WEBHOOK_MANAGER_CONSTRAINT}" \
    --no-interaction --no-progress --with-all-dependencies

echo "==> Running the Integration suite"
vendor/bin/pest --testsuite=Integration --colors=always
