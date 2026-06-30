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
#   # point at a local checkout of the addon instead of cloning from GitHub:
#   WEBHOOK_MANAGER_PATH=/path/to/statamic-webhook-manager scripts/test-webhook-manager.sh
#
# Requirements: PHP >=8.2 (sqlite, dom, mbstring, fileinfo, gd), Composer 2.x,
# and (unless WEBHOOK_MANAGER_PATH is set) network access to GitHub.

set -euo pipefail
IFS=$'\n\t'

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEBHOOK_MANAGER_PATH="${WEBHOOK_MANAGER_PATH:-}"
WEBHOOK_MANAGER_REPO="${WEBHOOK_MANAGER_REPO:-https://github.com/goldnead/statamic-webhook-manager.git}"

WORKDIR="$(mktemp -d)"
trap 'rm -rf "$WORKDIR"' EXIT

echo "==> Staging a throwaway copy of the addon in $WORKDIR"
# Copy the working tree (excluding vendor/node_modules) so we never touch the
# committed composer files in the real checkout.
git -C "$REPO_ROOT" archive --format=tar HEAD | tar -x -C "$WORKDIR"
cd "$WORKDIR"

echo "==> Registering goldnead/statamic-webhook-manager as a Composer dependency"
if [[ -n "$WEBHOOK_MANAGER_PATH" ]]; then
    composer config repositories.webhook-manager path "$WEBHOOK_MANAGER_PATH"
else
    composer config repositories.webhook-manager vcs "$WEBHOOK_MANAGER_REPO"
fi

# The addon may not have a stable tag yet; allow the dev branch.
composer require --dev "goldnead/statamic-webhook-manager:*@dev" \
    --no-interaction --no-progress --with-all-dependencies

echo "==> Running the Integration suite"
vendor/bin/pest --testsuite=Integration --colors=always
