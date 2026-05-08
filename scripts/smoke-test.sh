#!/usr/bin/env bash
#
# End-to-end smoke test for goldnead/statamic-leadhub.
#
# Spins up a fresh Statamic v6 project in /tmp, wires the LeadHub source
# tree as a Composer path repository, then exercises:
#
#   1. eloquent driver  — form mapping, real Form::find()->save() submission,
#                         contact lands in the database
#   2. migration        — `php please leadhub:storage:migrate --from=eloquent
#                         --to=flat` copies all data into content/leadhub/*.yaml
#   3. flat driver      — switch driver, submit again, contact lands in YAML
#
# Usage:
#   scripts/smoke-test.sh                  # run with defaults
#   LEADHUB_PATH=/path/to/leadhub scripts/smoke-test.sh
#
# Requirements:
#   - PHP >=8.2 with sqlite, dom, mbstring, fileinfo, gd
#   - Composer 2.x
#   - Network access (Composer downloads Statamic + deps; ~150 MB)
#
# Exit code is 0 on success, non-zero on the first failed step.

set -euo pipefail
IFS=$'\n\t'

# ============================================================
# Configuration
# ============================================================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LEADHUB_PATH="${LEADHUB_PATH:-$(cd "$SCRIPT_DIR/.." && pwd)}"
TIMESTAMP="$(date +%s)"
TEST_DIR="${TEST_DIR:-/tmp/leadhub-smoketest-$TIMESTAMP}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
STATAMIC_VERSION="${STATAMIC_VERSION:-^6.0}"

# Colors (skip if not a tty)
if [ -t 1 ]; then
    GREEN='\033[0;32m'
    RED='\033[0;31m'
    BLUE='\033[0;34m'
    YELLOW='\033[0;33m'
    BOLD='\033[1m'
    NC='\033[0m'
else
    GREEN='' RED='' BLUE='' YELLOW='' BOLD='' NC=''
fi

step()    { echo; echo -e "${BLUE}${BOLD}▸${NC} ${BOLD}$*${NC}"; }
ok()      { echo -e "  ${GREEN}✓${NC} $*"; }
fail()    { echo -e "  ${RED}✗${NC} $*" >&2; }
warn()    { echo -e "  ${YELLOW}⚠${NC} $*"; }

cleanup_on_error() {
    local exit_code=$?
    if [ $exit_code -ne 0 ]; then
        echo
        fail "Smoke test failed at step. Test directory preserved for inspection:"
        echo "    $TEST_DIR"
    fi
}
trap cleanup_on_error EXIT

# ============================================================
# Pre-flight
# ============================================================
step "Pre-flight checks"

command -v "$PHP_BIN" >/dev/null 2>&1 || { fail "$PHP_BIN not found"; exit 1; }
command -v "$COMPOSER_BIN" >/dev/null 2>&1 || { fail "$COMPOSER_BIN not found"; exit 1; }

PHP_VERSION="$($PHP_BIN -r 'echo PHP_VERSION;')"
PHP_MAJOR_MINOR="$($PHP_BIN -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
case "$PHP_MAJOR_MINOR" in
    8.2|8.3|8.4) ok "PHP $PHP_VERSION" ;;
    *) fail "PHP $PHP_VERSION found — LeadHub requires 8.2+"; exit 1 ;;
esac

if ! "$PHP_BIN" -m | grep -q -i sqlite; then
    fail "PHP sqlite extension required (apt: php-sqlite3 / brew: built-in)"
    exit 1
fi
ok "Composer $($COMPOSER_BIN --version | head -1 | awk '{print $3}')"

if [ ! -f "$LEADHUB_PATH/composer.json" ] || ! grep -q "goldnead/statamic-leadhub" "$LEADHUB_PATH/composer.json"; then
    fail "LeadHub source not found at $LEADHUB_PATH"
    fail "Set LEADHUB_PATH=/absolute/path/to/statamic-leadhub or run from inside the repo."
    exit 1
fi
ok "LeadHub source: $LEADHUB_PATH"

# ============================================================
# 1. Fresh Statamic install
# ============================================================
step "Creating Statamic v6 project at $TEST_DIR"

mkdir -p "$TEST_DIR"
cd "$TEST_DIR"

# Quiet down the install — we only care about the tail.
"$COMPOSER_BIN" create-project --prefer-dist --no-interaction --no-scripts \
    statamic/statamic . "$STATAMIC_VERSION" 2>&1 \
    | tail -5

# Run the post-install scripts ourselves with sane defaults.
"$COMPOSER_BIN" install --no-interaction --prefer-dist 2>&1 | tail -3

ok "Statamic v6 installed"

# ============================================================
# 2. Configure SQLite & base migrations
# ============================================================
step "Configuring SQLite + APP_KEY"

if [ ! -f .env ]; then
    cp .env.example .env 2>/dev/null || touch .env
fi

# Replace DB_* lines portably (BSD + GNU sed).
"$PHP_BIN" -r '
$path = ".env";
$env = file_exists($path) ? file_get_contents($path) : "";
$lines = preg_split("/\r?\n/", $env);
$lines = array_filter($lines, fn ($line) => ! preg_match(
    "/^(DB_CONNECTION|DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD)=/",
    $line
));
$lines[] = "DB_CONNECTION=sqlite";
$lines[] = "DB_DATABASE=" . __DIR__ . "/database/database.sqlite";
file_put_contents($path, implode("\n", $lines) . "\n");
'

mkdir -p database
touch database/database.sqlite

"$PHP_BIN" artisan key:generate --force --no-interaction >/dev/null
"$PHP_BIN" artisan migrate --force --no-interaction 2>&1 | tail -3

ok "Laravel base tables migrated"

# ============================================================
# 3. Add LeadHub via path repository
# ============================================================
step "Wiring LeadHub source as a path repository"

"$COMPOSER_BIN" config repositories.leadhub path "$LEADHUB_PATH" --no-interaction
"$COMPOSER_BIN" config minimum-stability dev --no-interaction
"$COMPOSER_BIN" config prefer-stable true --no-interaction

# allow-plugins for our and Statamic's transitive plugins (mirrors v0.2.1 fix).
"$COMPOSER_BIN" config --no-interaction allow-plugins.pixelfear/composer-dist-plugin true
"$COMPOSER_BIN" config --no-interaction allow-plugins.composer/installers true
"$COMPOSER_BIN" config --no-interaction allow-plugins.php-http/discovery true

"$COMPOSER_BIN" require "goldnead/statamic-leadhub:@dev" \
    --no-interaction --prefer-dist 2>&1 | tail -5

ok "LeadHub installed via path repository"

"$PHP_BIN" artisan vendor:publish --tag=leadhub-config --force --no-interaction >/dev/null
ok "leadhub.php config published"

"$PHP_BIN" artisan migrate --force --no-interaction 2>&1 | tail -3
ok "LeadHub tables migrated"

# ============================================================
# 4. Create a Statamic form
# ============================================================
step "Creating Statamic form 'contact'"

mkdir -p content/forms resources/blueprints/forms

cat > content/forms/contact.yaml <<'YAML'
title: Contact
honeypot: zip
YAML

cat > resources/blueprints/forms/contact.yaml <<'YAML'
title: Contact
sections:
  main:
    display: Main
    fields:
      - handle: email
        field:
          type: text
          input_type: email
          display: Email
          validate: required|email
      - handle: first_name
        field:
          type: text
          display: First name
      - handle: message
        field:
          type: textarea
          display: Message
YAML

ok "Form 'contact' (content + blueprint) written"

# ============================================================
# 5. Eloquent driver test — real Form::save()
# ============================================================
step "Eloquent driver: submit form, expect contact in DB"

cat > smoke-eloquent.php <<'PHP'
<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\FormMapping;
use Goldnead\Leadhub\Models\Event as LeadEvent;
use Statamic\Facades\Form;

// Configure the form mapping
FormMapping::query()->updateOrCreate(
    ['form_handle' => 'contact'],
    [
        'enabled' => true,
        'email_field' => 'email',
        'first_name_field' => 'first_name',
        'message_field' => 'message',
        'default_status' => 'new',
        'default_source' => 'website',
        'default_tags' => ['e2e'],
        'attach_full_submission' => true,
    ]
);

$form = Form::find('contact');
if (! $form) {
    fwrite(STDERR, "Form 'contact' not found by Statamic\n");
    exit(2);
}

$submission = $form->makeSubmission();
$submission->data([
    'email' => 'jane@example.com',
    'first_name' => 'Jane',
    'message' => 'Hello from the smoke test',
]);
$submission->save();

$count = Contact::count();
$contact = Contact::query()->where('email', 'jane@example.com')->first();

if ($count !== 1 || ! $contact) {
    fwrite(STDERR, "Expected 1 contact, found {$count}\n");
    exit(3);
}

printf("contact_id=%s\n", $contact->id);
printf("display_name=%s\n", $contact->displayName());
printf("status=%s\n", $contact->status);
printf("tags=%s\n", $contact->tags()->pluck('name')->implode(','));
printf("events=%d\n", LeadEvent::count());
exit(0);
PHP

"$PHP_BIN" smoke-eloquent.php | while IFS='=' read -r k v; do
    case "$k" in
        contact_id)   ok "Contact id: $v" ;;
        display_name) ok "Display name: $v" ;;
        status)       ok "Status: $v" ;;
        tags)         ok "Tags: $v" ;;
        events)       ok "Timeline events recorded: $v" ;;
    esac
done

ok "Form::save() ⇒ Contact landed in database"

# ============================================================
# 6. Migration: eloquent → flat
# ============================================================
step "leadhub:storage:migrate --from=eloquent --to=flat"

"$PHP_BIN" artisan leadhub:storage:migrate \
    --from=eloquent --to=flat --no-interaction 2>&1 \
    | sed 's/^/    /'

if [ ! -d content/leadhub/contacts ]; then
    fail "content/leadhub/contacts/ was not created"
    exit 1
fi

YAML_COUNT=$(find content/leadhub/contacts -name '*.yaml' 2>/dev/null | wc -l | tr -d ' ')
if [ "$YAML_COUNT" -lt 1 ]; then
    fail "No contact YAML written under content/leadhub/contacts/"
    exit 1
fi

ok "$YAML_COUNT contact YAML file(s) written"
ls -la content/leadhub/contacts | sed 's/^/    /'

if [ -f content/leadhub/tags.yaml ]; then
    ok "Tags migrated to content/leadhub/tags.yaml"
fi
if [ -f content/leadhub/form-mappings.yaml ]; then
    ok "Form mappings migrated to content/leadhub/form-mappings.yaml"
fi

# ============================================================
# 7. Flat driver: switch + new submission
# ============================================================
step "Flat driver: switch driver, submit again"

"$PHP_BIN" -r '
$path = ".env";
$env = file_get_contents($path);
$env = preg_replace("/\nLEADHUB_DRIVER=.*\n/", "\n", $env);
file_put_contents($path, rtrim($env, "\n") . "\nLEADHUB_DRIVER=flat\n");
'
ok "LEADHUB_DRIVER=flat written to .env"

"$PHP_BIN" artisan config:clear --no-interaction >/dev/null
"$PHP_BIN" artisan leadhub:stache:warm --clear 2>&1 | sed 's/^/    /'

cat > smoke-flat.php <<'PHP'
<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Statamic\Facades\Form;

if (config('leadhub.storage.driver') !== 'flat') {
    fwrite(STDERR, "Driver is " . config('leadhub.storage.driver') . ", expected flat\n");
    exit(2);
}

$form = Form::find('contact');
$submission = $form->makeSubmission();
$submission->data([
    'email' => 'flat-jane@example.com',
    'first_name' => 'Flat Jane',
    'message' => 'Submission via flat driver',
]);
$submission->save();

$contacts = app(ContactRepository::class);
$migrated = $contacts->findByEmailNormalized('jane@example.com');
$flatJane = $contacts->findByEmailNormalized('flat-jane@example.com');

if (! $migrated) {
    fwrite(STDERR, "Migrated contact (jane@example.com) not visible to flat driver\n");
    exit(3);
}
if (! $flatJane) {
    fwrite(STDERR, "Newly-submitted flat-jane@example.com not visible to flat driver\n");
    exit(4);
}

$repo = get_class($contacts);
printf("repo=%s\n", $repo);
printf("total=%d\n", $contacts->paginate([], 100, 1)->total());
printf("migrated_uuid=%s\n", $migrated->uuid);
printf("flat_jane_uuid=%s\n", $flatJane->uuid);
exit(0);
PHP

"$PHP_BIN" smoke-flat.php | while IFS='=' read -r k v; do
    case "$k" in
        repo)            ok "Active ContactRepository: $v" ;;
        total)           ok "Total contacts visible to flat driver: $v" ;;
        migrated_uuid)   ok "Migrated contact UUID: $v" ;;
        flat_jane_uuid)  ok "New flat contact UUID: $v" ;;
    esac
done

# ============================================================
# 8. Summary
# ============================================================
echo
echo -e "${GREEN}${BOLD}════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}${BOLD} Smoke test PASSED${NC}"
echo -e "${GREEN}${BOLD}════════════════════════════════════════════════════════════${NC}"
echo
echo "Test project: $TEST_DIR"
echo
echo "What just happened:"
echo "  1. Fresh Statamic v$STATAMIC_VERSION installed."
echo "  2. LeadHub source from $LEADHUB_PATH wired as Composer path repo."
echo "  3. Form 'contact' submitted → eloquent contact in database."
echo "  4. leadhub:storage:migrate copied data → content/leadhub/*.yaml."
echo "  5. LEADHUB_DRIVER=flat → second submission via flat driver."
echo
echo "To inspect:"
echo "  • $TEST_DIR/database/database.sqlite     (sqlite3 to browse)"
echo "  • $TEST_DIR/content/leadhub/             (YAML lead store)"
echo "  • $TEST_DIR/storage/app/leadhub/index/   (Stache-style indexes)"
echo "  • $TEST_DIR/storage/forms/contact/       (raw Statamic submissions)"
echo
echo "To open the CP:"
echo "  cd $TEST_DIR && php please make:user && php artisan serve"
echo "  → visit http://127.0.0.1:8000/cp"
echo

# Disarm the failure trap on success
trap - EXIT
